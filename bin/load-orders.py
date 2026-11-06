#!/usr/bin/env python3
"""在獨立 Compose 專案中重跑門市訂單效能驗收。"""

import argparse
import hashlib
import hmac
import http.client
import json
import math
import os
import re
import signal
import stat
import subprocess
import sys
import time
import traceback
from collections import Counter, defaultdict
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from datetime import datetime, timezone
from functools import partial
from http.cookies import SimpleCookie
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PORT = 8012
RPS = 20
WINDOWS = {"warmup": 60, "formal": 300}
READS = {"list", "detail", "inventory"}
WRITES = ("create", "start", "decision", "callback")
PATTERN = ("list", "write", "detail", "write", "inventory",
           "list", "write", "detail", "write", "list")
STAFF = ("taipei@example.test", "taichung@example.test")
HQ = "hq@example.test"
APP_DB = "store_ordering_test"
MOCK_DB = "store_ordering_mock_payment_test"
APP_USER = "store_ordering"
MOCK_USER = "store_ordering_mock_payment"
APP_PASSWORD = "STORE_ORDERING_DB_PASSWORD"
MOCK_PASSWORD = "STORE_ORDERING_MOCK_PAYMENT_DB_PASSWORD"
HMAC_KEY = "STORE_ORDERING_PAYMENT_HMAC_KEY"
UUID = re.compile(r"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}")
MAX_IN_FLIGHT = 16


class InvalidRun(Exception):
    """僅攜帶可寫入本機證據的固定診斷，不附外部輸入。"""


# 以固定錯誤代碼保存可排查的失敗，避免記錄回應本文、憑證或原始例外。
def require(valid, code):
    if not valid:
        raise InvalidRun(code)


# 只接受目前使用者的私有一般檔案，限制輸入大小且拒絕追蹤符號連結。
def private_file(path):
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK), "rb") as source:
        info = os.fstat(source.fileno())
        require(
            stat.S_ISREG(info.st_mode)
            and stat.S_IMODE(info.st_mode) == 0o600
            and info.st_uid == os.getuid(),
            "invalid_private_file",
        )
        data = source.read(4097)
        require(len(data) <= 4096, "private_file_too_large")
        return data


# 讀取 bin/local 產生的固定鍵值格式，不執行檔案內容。
def env_values(data):
    return dict(line.split("=", 1) for line in data.decode().splitlines())


# 只透過 bin/local 釘選的本機 socket 操作指定容器，外部輸出由呼叫端自行驗證。
def docker(*args, data=None, timeout=12):
    endpoint = os.environ.get("DOCKER_HOST", "")
    require(endpoint.startswith("unix:///") and not os.environ.get("DOCKER_CONTEXT"), "invalid_docker_socket")
    return subprocess.run(
        ["docker", "--host", endpoint, *args],
        input=data,
        capture_output=True,
        timeout=timeout,
        check=False,
    )


# 以固定容器 ID 再核對掛載、服務連線及憑證，避免相似名稱指到其他環境。
def verify_target(project, local, db_id, app_id):
    result = docker("inspect", "--type", "container", db_id, app_id)
    require(result.returncode == 0, "inspect_failed")
    db, app = json.loads(result.stdout)
    runtime = private_file(local / "runtime.env")
    settings = env_values(runtime)
    settings.update(env_values(private_file(local / "mock-payment.env")))
    settings.update(env_values(private_file(local / "payment-notifications.env")))
    require(
        bool(re.fullmatch(r"[0-9a-f]{48}", settings.get(APP_PASSWORD, "")))
        and bool(re.fullmatch(r"[0-9a-f]{48}", settings.get(MOCK_PASSWORD, "")))
        and bool(re.fullmatch(r"[0-9a-f]{64}", settings.get(HMAC_KEY, ""))),
        "invalid_target_credentials",
    )

    # 同一資料卷須屬於這份 checkout 和私有設定，且使用同一個隔離網路。
    for container, expected_id, service in ((db, db_id, "db"), (app, app_id, "app")):
        labels = container["Config"]["Labels"]
        networks = container["NetworkSettings"]["Networks"]
        require(
            container["Id"] == expected_id
            and container["State"]["Running"]
            and labels.get("com.docker.compose.project") == project
            and labels.get("com.docker.compose.project.working_dir") == str(ROOT)
            and labels.get("com.docker.compose.project.config_files") == str(ROOT / "compose.yaml")
            and labels.get("com.docker.compose.service") == service
            and set(networks) == {f"{project}_default"},
            f"wrong_{service}_container",
        )
    require(
        [
            (mount["Type"], mount.get("Name"), mount["RW"])
            for mount in db["Mounts"]
            if mount["Destination"] == "/var/lib/postgresql"
        ] == [("volume", f"{project}_postgres-data", True)],
        "wrong_database_mount",
    )
    owner = docker("exec", "--user", "postgres", db_id, "readlink", "/var/lib/postgresql/.store-ordering-owner")
    require(
        owner.returncode == 0 and owner.stdout.decode().rstrip("\n") == f"{ROOT}|{hashlib.sha256(runtime).hexdigest()}",
        "wrong_database_volume_owner",
    )

    # 比對 app 的實際路由及連線設定，登入才會送出本次專用帳密。
    db_env = env_values("\n".join(db["Config"]["Env"]).encode())
    app_env = env_values("\n".join(app["Config"]["Env"]).encode())
    expected_db = {
        "POSTGRES_DB": APP_DB,
        "STORE_ORDERING_MOCK_PAYMENT_DATABASE": MOCK_DB,
        APP_PASSWORD: settings[APP_PASSWORD],
        MOCK_PASSWORD: settings[MOCK_PASSWORD],
    }
    expected_app = {
        "APP_KEY": settings["STORE_ORDERING_APP_KEY"],
        "APP_URL": f"http://127.0.0.1:{PORT}",
        "DB_CONNECTION": "pgsql",
        "DB_HOST": "db",
        "DB_DATABASE": APP_DB,
        "DB_USERNAME": APP_USER,
        "DB_PASSWORD": settings[APP_PASSWORD],
        "MOCK_PAYMENT_URL": "http://mock-payment:8000",
        HMAC_KEY: settings[HMAC_KEY],
    }
    cookie = app_env.get("SESSION_COOKIE", "")
    require(
        all(db_env.get(k) == v for k, v in expected_db.items())
        and all(app_env.get(k) == v for k, v in expected_app.items())
        and cookie == f"store_ordering_load_{project.rsplit('-', 1)[1]}_session"
        and app["NetworkSettings"]["Ports"].get("8000/tcp") == [{"HostIp": "127.0.0.1", "HostPort": str(PORT)}],
        "wrong_runtime_configuration",
    )
    return cookie, settings[HMAC_KEY].encode()


# 測試 SQL 走資料庫容器內 socket，以各自服務角色連線；只有種子檔可寫入。
def sql(db_id, database, operation, query, *, cutoff=0, timeout=12):
    role, secret = (APP_USER, APP_PASSWORD) if database == APP_DB else (MOCK_USER, MOCK_PASSWORD)
    guard = (
        "\\o /dev/null\n"
        f"SELECT 1 / ((current_database() = '{database}' AND current_user = '{role}')::int);\n"
        "\\o\n"
    )
    # 使用容器現有憑證，不在主機子程序的命令列中傳送密碼。
    options = "-c statement_timeout=8000 -c lock_timeout=3000"
    if operation != "seed":
        options += " -c default_transaction_read_only=on"
    process = docker(
        "exec", "-i", "--user", "postgres", "--env", "PGCLIENTENCODING=UTF8",
        "--env", f"PGOPTIONS={options}",
        db_id, "sh", "-eu", "-c", f'export PGPASSWORD="${secret}"; exec psql "$@"', "sh",
        "--no-psqlrc", "--quiet", "--tuples-only", "--no-align", "--no-password",
        "--host", "/var/run/postgresql", "--username", role, "--dbname", database,
        "--set=ON_ERROR_STOP=1", "--set=VERBOSITY=sqlstate", f"--set=cutoff={cutoff}",
        data=(guard + query).encode(),
        timeout=timeout,
    )
    if process.returncode:
        states = re.findall(rb"(?:ERROR|FATAL):\s+([0-9A-Z]{5})\b", process.stderr)
        detail = states[-1].decode() if states else str(process.returncode)
        raise InvalidRun(f"{operation}_psql_{detail}")
    return json.loads(process.stdout) if process.stdout.strip() else None


# 同一條 loopback 入口處理登入、業務操作與回呼，直接記下完整 HTTP 來回時間。
def send_http(method, path, body=None, headers=None, issue=None):
    connection = http.client.HTTPConnection("127.0.0.1", PORT, timeout=5)
    request_headers = {"Accept": "application/json", **(headers or {})}
    if body is not None:
        request_headers["Content-Type"] = "application/json"
    send = partial(connection.request, method, path, body=body, headers=request_headers)
    try:
        if issue is None:
            send()
        else:
            connection.connect()
            issue(send)
        with connection.getresponse() as response:
            data = response.read(1_048_577)
            return response.status, response.headers, data, time.monotonic()
    finally:
        connection.close()


# 核對三種帳號的真實登入、會話輪替及門市綁定；不將會話值寫入量測證據。
def authenticate(local, cookie_name):
    accounts = json.loads(private_file(local / "accounts.json"))
    require(isinstance(accounts, dict) and set(accounts) == {HQ, *STAFF}, "invalid_accounts")
    sessions = {}
    for email in (*STAFF, HQ):
        # 從真實的 Set-Cookie 擷取 Laravel 會話，沿用匿名 token 進入登入路由。
        status, headers, raw, _ = send_http("GET", "/api/session")
        payload = json.loads(raw)
        cookie = session_cookie(headers, cookie_name)
        require(status == 200 and payload["user"] is None and bool(payload["csrfToken"]), "anonymous_session_failed")
        password = accounts[email]
        require(isinstance(password, str) and bool(re.fullmatch(r"[0-9a-f]{36}", password)), "invalid_account_secret")
        status, headers, raw, _ = send_http(
            "POST", "/api/login", json.dumps({"email": email, "password": password}).encode(),
            {"Cookie": cookie, "X-CSRF-TOKEN": payload["csrfToken"]},
        )

        # 登入後驗證身分並使用輪替後的 cookie 及 token 執行正式請求。
        logged = json.loads(raw)
        new_cookie = session_cookie(headers, cookie_name)
        user = logged["user"]
        require(
            status == 200
            and user["role"] == ("headquarters" if email == HQ else "staff")
            and (user["storeId"] is None if email == HQ else positive_int(user["storeId"]))
            and positive_int(user["id"])
            and bool(logged["csrfToken"])
            and logged["csrfToken"] != payload["csrfToken"]
            and new_cookie != cookie,
            "login_failed",
        )
        sessions[email] = (user, {"Cookie": new_cookie, "X-CSRF-TOKEN": logged["csrfToken"]})
    return sessions


# 只重送已核對的會話 cookie，排除 XSRF 輔助 cookie 及其他回應標頭。
def session_cookie(headers, name):
    cookies = SimpleCookie()
    for header in headers.get_all("Set-Cookie", []):
        cookies.load(header)
    require(name in cookies, "session_cookie_missing")
    return f"{name}={cookies[name].coded_value}"


# 排除 JSON 布林值和浮點數被 Python 當作等值整數。
def positive_int(value):
    return type(value) is int and value > 0


@dataclass(frozen=True)
class Slot:
    index: int
    operation: str
    store: int
    chain: int | None

    # 將固定頻率的序號換算成預定秒數，避免逐次加總浮點誤差。
    @property
    def offset(self):
        return self.index / RPS


# 每 10 秒建立完整一組配額，四段均穿插讀取，讓相依操作間有 2.5 秒。
def schedule(seconds):
    for cohort in range(seconds // 10):
        reads = Counter()
        for stage in WRITES:
            for repeat in range(5):
                for place, kind in enumerate(PATTERN):
                    index = cohort * 200 + WRITES.index(stage) * 50 + repeat * 10 + place
                    if kind == "write":
                        member = repeat * 4 + (1, 3, 6, 8).index(place)
                        yield Slot(index, stage, member % 2, cohort * 20 + member)
                    else:
                        yield Slot(index, kind, reads[kind] % 2, None)
                        reads[kind] += 1


# 每店每五張中固定一張付款失敗，暖機與正式測量各自維持相同配比。
def outcome(chain):
    return "failed" if (chain // 2) % 5 == 4 else "paid"


# 操作鍵於單次專案內重現，對應 SQL 保存的 SHA-256 並識別回應遺失的訂單。
def operation_key(project, phase, chain):
    return f"{project}:{phase}:{chain}"


# 將測試事件原文按付款識別批次讀回，測試流量不另呼叫應用程式入口。
def get_events(db_id, ids):
    require(all(UUID.fullmatch(payment_id) for payment_id in ids), "invalid_event_query_ids")
    if not ids:
        return {}
    values = ", ".join(f"'{payment_id}'" for payment_id in ids)
    rows = sql(db_id, MOCK_DB, "event_prefetch", f"""
        SELECT COALESCE(json_agg(json_build_array(payment_id, event_id,
            encode(convert_to(body, 'UTF8'), 'hex'))), '[]')
        FROM payment_events WHERE payment_id IN ({values});
    """, timeout=3)
    return {payment: (event, bytes.fromhex(body)) for payment, event, body in rows}


# 將請求與預定識別綁在一起，正式路徑上的 Cookie、CSRF 及原文驗簽都會生效。
def request_for(slot, phase, context, prior):
    operation = slot.operation
    user, headers = context["sessions"][STAFF[slot.store]]
    store = user["storeId"]
    product = context["product"]
    if operation == "list":
        return "GET", f"/api/orders?storeId={store}", None, headers, None
    if operation == "detail":
        rows = context["historical"][store]
        fixture = rows[(slot.index * 137) % len(rows)]
        return "GET", f"/api/orders/{fixture['id']}", None, headers, fixture
    if operation == "inventory":
        return "GET", f"/api/stores/{store}/inventory", None, headers, None
    if operation == "create":
        body = {
            "storeId": store,
            "operationKey": operation_key(context["project"], phase, slot.chain),
            "items": [{"productId": product["id"], "quantity": 1}],
        }
        return "POST", "/api/orders", json.dumps(body).encode(), headers, None
    if operation == "start":
        return "POST", f"/api/orders/{prior['order_id']}/payment", b"{}", headers, None
    if operation == "decision":
        headers = context["sessions"][HQ][1]
        return (
            "POST", f"/api/mock-payments/{prior['payment_id']}/decision",
            json.dumps({"status": outcome(slot.chain)}).encode(), headers, None,
        )

    # 使用伺服器所存的位元組原樣驗簽，並先比對事件及付款綁定。
    event_id, raw = prior["event"]
    require(json.loads(raw) == {
        "eventId": event_id, "paymentId": prior["payment_id"], "orderId": prior["order_id"],
        "amount": product["price"], "currency": "TWD", "status": outcome(slot.chain),
    }, "event_payload_mismatch")
    timestamp = str(int(time.time()))
    signature = hmac.new(context["key"], timestamp.encode() + b"." + raw, "sha256").hexdigest()
    return (
        "POST", "/api/payment-notifications", raw,
        {"X-Payment-Timestamp": timestamp, "X-Payment-Signature": signature}, event_id,
    )


# 驗證讀取內容及交易回應，僅保留用於後續相依工作與稽核的識別。
def validate_response(slot, payload, context, prior, fixture):
    operation = slot.operation
    data = payload["data"]
    user = context["sessions"][STAFF[slot.store]][0]
    store = user["storeId"]
    product = context["product"]
    if operation == "list":
        require(
            len(data) == 50
            and payload["meta"]["page"] == 1
            and payload["meta"]["perPage"] == 50
            and payload["meta"]["total"] >= 5000
            and all(row["storeId"] == store and positive_int(row["id"]) for row in data)
            and len({row["id"] for row in data}) == 50,
            "list_payload_mismatch",
        )
    elif operation == "detail":
        require(
            data["id"] == fixture["id"]
            and data["storeId"] == store
            and data["createdBy"] == fixture["created_by"]
            and data["status"] == fixture["status"]
            and data["total"] == fixture["total"]
            and data["items"] == [fixture["item"]]
            and data["payment"] is None
            and not data["anomalies"]
            and [row["status"] for row in data["history"]] == ["awaiting_payment", fixture["status"]]
            and [row["type"] for row in data["stockLedger"]] == ["reserved", "released"],
            "detail_payload_mismatch",
        )
    elif operation == "inventory":
        expected = context["inventory"][store]
        require(
            len(data) == len(expected)
            and set(row["id"] for row in data) == set(expected)
            and all(
                type(row["available"]) is int
                and type(row["onHand"]) is int
                and 0 <= row["available"] <= row["onHand"] <= expected[row["id"]]
                and (row["id"] == product["id"] or row["available"] == row["onHand"] == expected[row["id"]])
                for row in data
            ),
            "inventory_payload_mismatch",
        )
    elif operation in ("create", "start"):
        require(
            positive_int(data["id"])
            and data["storeId"] == store
            and data["createdBy"] == user["id"]
            and data["total"] == product["price"]
            and data["status"] == ("awaiting_payment" if operation == "create" else "processing"),
            f"{operation}_payload_mismatch",
        )
        if operation == "create":
            return {"order_id": data["id"]}
        require(
            data["id"] == prior["order_id"]
            and data["payment"]["status"] == "pending"
            and data["payment"]["pendingReason"] == "awaiting_result"
            and bool(UUID.fullmatch(data["payment"]["id"])),
            "start_payment_mismatch",
        )
        return {**prior, "payment_id": data["payment"]["id"]}
    elif operation == "decision":
        require(data == {
            "paymentId": prior["payment_id"], "orderId": prior["order_id"],
            "amount": product["price"], "currency": "TWD", "status": outcome(slot.chain),
        }, "decision_payload_mismatch")
        return prior
    else:
        require(data == {"eventId": fixture}, "callback_payload_mismatch")
        return {key: prior[key] for key in ("order_id", "payment_id")} | {"event_id": fixture}
    return {}


# 一筆證據代表一個預定入口；未發送的原因與 HTTP 結果分開記錄。
def request_record(slot, phase, context):
    return {
        "phase": phase, "slot": slot.index, "operation": slot.operation,
        "store_id": context["sessions"][STAFF[slot.store]][0]["storeId"], "chain": slot.chain,
        "scheduled_offset_s": slot.offset, "attempt_offset_s": None,
        "issue_offset_s": None, "completion_offset_s": None,
        "http_status": None, "latency_ms": None, "error": None, "identity": None,
    }


# 在 worker 取得執行權後才量測實際送出；過了該時槽便留下缺口，禁止補送。
def execute(slot, phase, context, prior, start):
    record = request_record(slot, phase, context)

    # 連線完成後才核對時槽，完整寫入 socket 後才將請求算作已送出。
    def issue(send):
        now = time.monotonic() - start
        require(now < (slot.index + 1) / RPS, "missed_slot")
        send()
        record["issue_offset_s"] = now

    try:
        method, path, body, headers, fixture = request_for(slot, phase, context, prior)
        record["attempt_offset_s"] = time.monotonic() - start
        status, _, raw, finished = send_http(method, path, body, headers, issue)
        record.update(
            http_status=status,
            completion_offset_s=finished - start,
            latency_ms=(finished - start - record["attempt_offset_s"]) * 1000,
        )
        if status != (201 if slot.operation == "create" else 200):
            record["error"] = "http_system" if status >= 500 else "http_contract"
        else:
            require(len(raw) <= 1_048_576, "oversized_response")
            record["identity"] = validate_response(slot, json.loads(raw), context, prior, fixture)
    except InvalidRun as exc:
        record["error"] = str(exc)
    except (OSError, http.client.HTTPException) as exc:
        record["error"] = f"transport_{type(exc).__name__}"
        record["completion_offset_s"] = time.monotonic() - start
        if record["issue_offset_s"] is not None:
            record["latency_ms"] = (record["completion_offset_s"] - record["attempt_offset_s"]) * 1000
    except (KeyError, ValueError, TypeError, AttributeError):
        record["error"] = "invalid_response_shape"
    return record


# 讀取已提交的決定事件；查詢耗時另計，避免混入正式 HTTP 延遲。
def prefetch(db_id, payment_ids):
    started = time.monotonic()
    error = None
    events = {}
    try:
        events = get_events(db_id, payment_ids)
    except InvalidRun as exc:
        error = str(exc)
    except (OSError, ValueError, subprocess.TimeoutExpired) as exc:
        error = f"event_prefetch_{type(exc).__name__}"
    return events, {
        "requested": len(payment_ids), "found": len(events), "error": error,
        "duration_ms": (time.monotonic() - started) * 1000,
    }


# 只等下一個單調時鐘時槽，不等待上一個 HTTP 回應後才安排下一個請求。
def wait_until(deadline):
    remaining = deadline - time.monotonic()
    if remaining > 0:
        time.sleep(remaining)


# 以 UTC 微秒時間提供服務 access log 可比對的量測窗。
def utc(timestamp=None):
    value = datetime.now(timezone.utc) if timestamp is None else datetime.fromtimestamp(timestamp, timezone.utc)
    return value.isoformat(timespec="microseconds")


# 每個時槽無論成功或缺失都保存證據，並將後續排空獨立記錄。
def run_window(phase, context, journal, window):
    seconds = WINDOWS[phase]
    slots = list(schedule(seconds))
    callback_slots = {slot.chain: slot.index for slot in slots if slot.operation == "callback"}
    # 每十筆取第一個回呼的時槽，提前 600 毫秒開啟批次讀取。
    batch_slots = {chain: callback_slots[chain // 10 * 10] - 12 for chain in callback_slots}
    start = time.monotonic() + 0.1
    wall_start = time.time() + 0.1
    window.update(started_at=utc(wall_start), ended_at=utc(wall_start + seconds), elapsed=False, prefetch=[])
    records = {}
    stages = {}
    active = {}
    waiting_events = {}
    event_jobs = {}
    prefetches = {}
    interrupted = False

    # 每筆完成或略過時立即保存，讓中斷仍可看見實際進度。
    def record(result):
        records[result["slot"]] = result
        if result["chain"] is not None:
            stages[(result["chain"], result["operation"])] = result
        journal.write(json.dumps(result, separators=(",", ":")) + "\n")

        # 決定成功後才有可信的付款識別，回呼時槽已過時不再啟動查詢。
        chain = result["chain"]
        if (
            result["operation"] == "decision"
            and result["identity"] is not None
            and time.monotonic() < start + callback_slots[chain] / RPS
        ):
            waiting_events[chain] = result["identity"]["payment_id"]

    # 只收已完成的工作，避免排程因 HTTP 或資料庫查詢而停下來。
    def collect(*, drain=False):
        for future, slot in list(active.items()):
            if drain or future.done():
                try:
                    result = future.result()
                except Exception as exc:
                    result = request_record(slot, phase, context)
                    result["error"] = f"worker_{type(exc).__name__}"
                record(result)
                del active[future]

    # HTTP 工作與事件讀取共用固定併發上限，先保留當前的 HTTP 時槽。
    with ThreadPoolExecutor(max_workers=MAX_IN_FLIGHT) as workers:
        try:
            for slot in slots:
                wait_until(start + slot.offset)
                collect()
                capacity = MAX_IN_FLIGHT - len(active) - sum(not job.done() for job in prefetches)
                if slot.operation == "callback":
                    waiting_events.pop(slot.chain, None)

                # 依相依結果判斷可否發送；時槽不足仍記成該操作的缺口。
                result = request_record(slot, phase, context)
                prior = None
                if time.monotonic() >= start + (slot.index + 1) / RPS:
                    result["error"] = "missed_slot"
                elif capacity <= 0:
                    result["error"] = "client_capacity"
                elif slot.chain is not None and slot.operation != "create":
                    previous = stages.get((slot.chain, WRITES[WRITES.index(slot.operation) - 1]), {})
                    prior = previous.get("identity")
                    if not prior:
                        result["error"] = "prerequisite_incomplete"
                    elif slot.operation == "callback":
                        batch = event_jobs.get(slot.chain)
                        events = batch.result()[0] if batch and batch.done() else {}
                        event = events.get(prior["payment_id"])
                        if event is None:
                            result["error"] = "event_not_ready"
                        else:
                            prior = {**prior, "event": event}

                if result["error"]:
                    record(result)
                else:
                    active[workers.submit(execute, slot, phase, context, prior, start)] = slot
                    capacity -= 1

                # 批次之後才完成的決定可補查一次，依各自回呼時槽優先，始終不等待資料庫。
                if (
                    capacity > 0
                    and result["error"] != "missed_slot"
                    and waiting_events
                ):
                    ready = sorted(
                        (chain for chain in waiting_events if batch_slots[chain] <= slot.index < callback_slots[chain]),
                        key=callback_slots.__getitem__,
                    )[:10]
                    if ready:
                        job = workers.submit(prefetch, context["db_id"], [waiting_events.pop(chain) for chain in ready])
                        for chain in ready:
                            event_jobs[chain] = job
                        kind = "batch" if all(batch_slots[chain] == slot.index for chain in ready) else "supplemental"
                        prefetches[job] = {"first_chain": ready[0], "chains": ready, "kind": kind,
                                           "scheduled_offset_s": slot.offset}

            # 等到精確的結束邊界後才進入排空，逾窗回應仍可對照測後資料。
            wait_until(start + seconds)
            window["elapsed"] = True
        except KeyboardInterrupt:
            interrupted = True
        finally:
            collect(drain=True)
            for slot in slots:
                if slot.index not in records:
                    result = request_record(slot, phase, context)
                    result["error"] = "window_interrupted"
                    record(result)
            for job, evidence in prefetches.items():
                window["prefetch"].append({**evidence, **job.result()[1]})
            window["drained_at"] = utc()

    window.update(measure(records.values(), seconds))
    return stages, interrupted


# 採最近名次 p95，避免小樣本插值將門檻附近的慢請求稀釋。
def p95(values):
    return sorted(values)[math.ceil(0.95 * len(values)) - 1] if values else None


# 分開統計 HTTP、錯過時槽、回應內容與完整交易，避免合法的付款失敗變成系統錯誤。
def measure(records, seconds):
    operations = defaultdict(Counter)
    errors = Counter()
    by_store = Counter()
    issued_by_store = Counter()
    transactions = defaultdict(dict)
    latency = {"read": [], "transaction_operation": []}
    per_operation = defaultdict(list)
    for row in records:
        operation = row["operation"]
        counts = operations[operation]
        counts["scheduled"] += 1
        counts["issued"] += row["issue_offset_s"] is not None
        counts["http_responses"] += row["http_status"] is not None
        counts["valid_responses"] += row["identity"] is not None
        counts["not_issued"] += row["issue_offset_s"] is None
        counts["late_responses"] += row["completion_offset_s"] is not None and row["completion_offset_s"] >= seconds
        if row["http_status"] is not None:
            counts[f"http_{row['http_status']}"] += 1
        if row["error"]:
            errors[row["error"]] += 1
        if row["latency_ms"] is not None:
            latency["read" if operation in READS else "transaction_operation"].append(row["latency_ms"])
            per_operation[operation].append(row["latency_ms"])
        if row["issue_offset_s"] is not None:
            issued_by_store[f"{row['store_id']}:{operation}"] += 1
        if row["identity"] is not None:
            by_store[f"{row['store_id']}:{operation}"] += 1
        if row["chain"] is not None:
            transactions[row["chain"]][operation] = row

    # 交易必須在同一量測窗送完且收到四個正確回應，排空完成只計入測後稽核。
    completed = Counter()
    for chain, stages in transactions.items():
        if set(stages) == set(WRITES) and all(
            row["identity"] is not None and row["completion_offset_s"] < seconds
            for row in stages.values()
        ):
            completed[f"{stages['create']['store_id']}:{outcome(chain)}"] += 1

    # 把未送出的工作與 HTTP 非預期錯誤分開，兩者分別影響流量和錯誤門檻。
    issued = sum(row["issued"] for row in operations.values())
    unexpected = sum(
        count for kind, count in errors.items()
        if kind in ("http_contract", "http_system") or kind.startswith("transport_")
    )
    correctness = sum(
        count for kind, count in errors.items()
        if kind not in (
            "http_system", "missed_slot", "client_capacity", "prerequisite_incomplete",
            "event_not_ready", "window_interrupted",
        ) and not kind.startswith("transport_")
    )
    return {
        "scheduled": sum(row["scheduled"] for row in operations.values()),
        "issued": issued,
        "http_responses": sum(row["http_responses"] for row in operations.values()),
        "not_issued": sum(row["not_issued"] for row in operations.values()),
        "late_responses": sum(row["late_responses"] for row in operations.values()),
        "operations": dict(operations),
        "valid_responses_by_store": dict(by_store),
        "issued_by_store": dict(issued_by_store),
        "p95_ms": {**{kind: p95(values) for kind, values in latency.items()},
                   **{kind: p95(values) for kind, values in per_operation.items()}},
        "errors": dict(errors),
        "unexpected_http_or_transport_errors": unexpected,
        "unexpected_error_rate": unexpected / issued if issued else None,
        "response_correctness_errors": correctness,
        "complete_transactions": sum(completed.values()),
        "complete_transactions_by_store_and_outcome": dict(completed),
    }


# 保存兩個資料庫各自的快照，讓核對使用真實持久化資料而不共用應用程式查詢。
def snapshot(db_id, cutoff):
    app = sql(
        db_id, APP_DB, "app_audit", (ROOT / "bin/load-orders-audit.sql").read_text(encoding="utf-8"),
        cutoff=cutoff,
    )
    mock = sql(db_id, MOCK_DB, "mock_audit", """
        SELECT json_build_object(
            'payments', COALESCE((SELECT json_agg(p ORDER BY payment_id) FROM payments AS p), '[]'),
            'events', COALESCE((
                SELECT json_agg(json_build_object('event_id', event_id, 'payment_id', payment_id,
                    'body_hash', encode(sha256(convert_to(body, 'UTF8')), 'hex'), 'binding', body::json)
                    ORDER BY payment_id)
                FROM payment_events
            ), '[]'),
            'dispatches', (SELECT count(*) FROM payment_notification_dispatches)
        );
    """)
    return app, mock


# 播種後建立 API 讀取及測後稽核的共同基準，登入門市與資料庫門市必須一致。
def setup_fixture(context):
    db_id = context["db_id"]
    # psql 預設將布林值顯示成 t／f；轉成 JSON 以符合所有讀回查詢的解析格式。
    require(sql(db_id, MOCK_DB, "mock_preflight", """
        SELECT to_json(NOT EXISTS (SELECT FROM payments)
            AND NOT EXISTS (SELECT FROM payment_events)
            AND NOT EXISTS (SELECT FROM payment_notification_dispatches));
    """) is True, "mock_database_not_empty")
    sql(db_id, APP_DB, "seed", (ROOT / "bin/load-orders-seed.sql").read_text(encoding="utf-8"))

    # 逐店保存固定的歷史明細樣本來源，並確認 10,000 筆均已提交。
    rows = sql(db_id, APP_DB, "fixture_readback", """
        SELECT json_agg(json_build_object(
            'id', orders.id, 'store_id', orders.store_id, 'created_by', orders.created_by,
            'status', orders.status, 'total', orders.total,
            'item', json_build_object('productId', order_items.product_id, 'name', order_items.name,
                'quantity', order_items.quantity, 'unitPrice', order_items.unit_price, 'subtotal', order_items.subtotal)
        ) ORDER BY orders.id)
        FROM orders JOIN order_items ON order_items.order_id = orders.id;
    """)
    stores = [context["sessions"][email][0] for email in STAFF]
    historical = {
        user["storeId"]: [row for row in rows if row["store_id"] == user["storeId"]]
        for user in stores
    }
    require(
        len(rows) == 10_000
        and len({row["id"] for row in rows}) == 10_000
        and len(historical) == 2
        and all(
            Counter(row["status"] for row in historical[user["storeId"]]) == {"cancelled": 2500, "expired": 2500}
            and all(row["created_by"] == user["id"] for row in historical[user["storeId"]])
            for user in stores
        ),
        "historical_orders_mismatch",
    )
    cutoff = rows[-1]["id"]
    app, mock = snapshot(db_id, cutoff)
    product = sql(db_id, APP_DB, "product_readback", "SELECT row_to_json(p) FROM products p WHERE name = '濾掛咖啡';")
    require(
        app["history"][:2] == [10_000, cutoff]
        and not any(app[key] for key in ("orders", "intents", "receipts", "anomalies", "active_orders"))
        and not any(mock.values())
        and len(app["inventory"]) == 4
        and product["price"] == 45,
        "fixture_readback_mismatch",
    )
    inventory = {
        store: {row["product_id"]: row["on_hand"] for row in app["inventory"] if row["store_id"] == store}
        for store in historical
    }
    require(
        [inventory[user["storeId"]].get(product["id"]) for user in stores] == [1000, 1100],
        "load_inventory_mismatch",
    )
    context.update(product=product, historical=historical, cutoff=cutoff, initial_app=app, inventory=inventory)


# 每個預定操作鍵都需對應唯一訂單，即使 HTTP 回應遺失也能定位已提交的交易。
def check_persistence(context, results, app, mock):
    product = context["product"]
    by_key = {row["operation_key"]: row for row in app["orders"]}
    intents = {row["order_id"]: row for row in app["intents"]}
    payments = {row["payment_id"]: row for row in mock["payments"]}
    events = {row["payment_id"]: row for row in mock["events"]}
    receipts = {row["event_id"]: row for row in app["receipts"]}
    seen = defaultdict(set)
    expected_paid = Counter()
    chains = []

    # 比對兩端交易綁定、原文雜湊、終局與庫存歷程，保留異常對應的識別。
    for phase, seconds in WINDOWS.items():
        for chain in range(seconds * 2):
            actor = context["sessions"][STAFF[chain % 2]][0]
            store, terminal = actor["storeId"], outcome(chain)
            expected_paid[store] += terminal == "paid"
            key = hashlib.sha256(operation_key(context["project"], phase, chain).encode()).hexdigest()
            order = by_key.get(key, {})
            order_id = order.get("id")
            intent = intents.get(order_id, {})
            payment_id = intent.get("payment_id")
            payment = payments.get(payment_id, {})
            event = events.get(payment_id, {})
            event_id = event.get("event_id")
            receipt = receipts.get(event_id, {})
            binding = {"order_id": order_id, "payment_id": payment_id, "amount": product["price"], "currency": "TWD"}
            expected_ledger = [[product["id"], 1, kind] for kind in
                               (("reserved", "deducted", "released") if terminal == "paid" else ("reserved", "released"))]
            checks = {
                "order": order and all(order.get(k) == v for k, v in {
                    "store": store, "actor": actor["id"], "status": terminal, "total": product["price"],
                    "expiry_seconds": 900,
                    "items": [[product["id"], product["name"], 1, product["price"], product["price"]]],
                    "history": ["awaiting_payment", "processing", terminal], "ledger": expected_ledger,
                }.items()),
                "intent": intent and all(intent.get(k) == v for k, v in {
                    **binding, "create_pending": False, "pending_reason": None, "next_recovery_at": None,
                    "recovery_attempts": 0,
                }.items()),
                "mock_payment": payment and all(payment.get(k) == v for k, v in {**binding, "status": terminal}.items()),
                "event": event and event["binding"] == {
                    "eventId": event_id, "paymentId": payment_id, "orderId": order_id,
                    "amount": product["price"], "currency": "TWD", "status": terminal,
                },
                "receipt": receipt and all(receipt.get(k) == v for k, v in {
                    "event_id": event_id, "source": "mock-payment", "body_hash": event.get("body_hash"),
                    "status": 200, "order_id": order_id,
                }.items()),
            }

            # 已收到的 HTTP 識別也要與測後資料相符，不能由資料庫結果掩蓋回應錯誤。
            for stage in WRITES:
                response = results.get(phase, {}).get((chain, stage), {}).get("identity")
                if response is not None:
                    checks[f"{stage}_identity"] = all(
                        {"order_id": order_id, "payment_id": payment_id, "event_id": event_id}[k] == v
                        for k, v in response.items()
                    )
            for kind, value in (("orders", order_id), ("intents", order_id), ("payments", payment_id),
                                ("events", event_id), ("receipts", event_id)):
                if value is not None:
                    seen[kind].add(value)
            chains.append({
                "phase": phase, "chain": chain, "store_id": store, "expected_status": terminal,
                "order_id": order_id, "payment_id": payment_id, "event_id": event_id,
                "receipt_status": receipt.get("status"), "errors": [name for name, valid in checks.items() if not valid],
            })

    # 由完整資料集反查未排程的寫入、仍有效的保留及每個品項的實存差額。
    unexpected = {}
    for kind, rows, identity in (
        ("orders", app["orders"], "id"), ("intents", app["intents"], "order_id"),
        ("payments", mock["payments"], "payment_id"), ("events", mock["events"], "event_id"),
        ("receipts", app["receipts"], "event_id"),
    ):
        counts = Counter(row[identity] for row in rows)
        unexpected[kind] = [key for key, count in counts.items() if key not in seen[kind] or count != 1]
    stock = {(row["store_id"], row["product_id"]): row["on_hand"] for row in app["inventory"]}
    inventory = []
    for store, items in context["inventory"].items():
        for item, before in items.items():
            expected = before - (expected_paid[store] if item == product["id"] else 0)
            inventory.append({"store_id": store, "product_id": item, "before": before,
                              "expected_after": expected, "observed_after": stock.get((store, item))})
    checks = {
        "all_expected_chains": all(not chain["errors"] for chain in chains),
        "no_unexpected_rows": not any(unexpected.values()),
        "history_unchanged": context["initial_app"]["history"] == app["history"],
        "inventory_matches": len(stock) == len(inventory) and all(
            row["observed_after"] == row["expected_after"] for row in inventory
        ),
        "no_unresolved_holds": app["active_orders"] == 0,
        "no_anomalies": not app["anomalies"],
        "no_queued_dispatches": mock["dispatches"] == 0,
    }
    return {
        "passed": all(checks.values()), "checks": checks, "chains": chains, "unexpected": unexpected,
        "active_orders": app["active_orders"], "anomalies": app["anomalies"], "inventory": inventory,
        "queued_dispatches": mock["dispatches"],
        "observed_counts": {kind: len(rows) for kind, rows in (
            ("orders", app["orders"]), ("intents", app["intents"]), ("receipts", app["receipts"]),
            ("payments", mock["payments"]), ("events", mock["events"]),
        )},
    }


# 流量與門檻一併判斷，交易完整數、兩店配比或測後資料缺一就無法通過。
def qualification(windows, audit, stores):
    warmup, formal = (windows.get(phase, {}) for phase in WINDOWS)
    targets = {"list": 1800, "detail": 1200, "inventory": 600, **dict.fromkeys(WRITES, 600)}
    expected_transactions = {f"{store}:{state}": count for store in stores
                             for state, count in (("paid", 240), ("failed", 60))}
    measured = formal.get("operations", {})
    latency = formal.get("p95_ms", {})
    errors = formal.get("unexpected_error_rate")
    return {
        "warmup": warmup.get("elapsed") is True and warmup.get("issued") == 1200,
        "formal": formal.get("elapsed") is True and formal.get("scheduled") == 6000 and formal.get("issued") == 6000,
        "request_mix": {kind: row["issued"] for kind, row in measured.items()} == targets,
        "request_store_balance": formal.get("issued_by_store") == {
            f"{store}:{kind}": count // 2 for store in stores for kind, count in targets.items()
        },
        "complete_transactions": formal.get("complete_transactions_by_store_and_outcome") == expected_transactions,
        "read_p95": latency.get("read") is not None and latency["read"] <= 500,
        "transaction_operation_p95": (
            latency.get("transaction_operation") is not None and latency["transaction_operation"] <= 1000
        ),
        "unexpected_error_rate": errors is not None and errors < 0.01,
        "response_correctness": formal.get("response_correctness_errors") == 0,
        "persistence": audit.get("passed") is True,
    }


# 原子更新摘要；程序中途停止時，已完成階段的摘要仍保留。
def write_json(path, data):
    temp = path.with_suffix(".tmp")
    with temp.open("x", encoding="utf-8") as output:
        json.dump(data, output, ensure_ascii=False, indent=2)
        output.write("\n")
    temp.replace(path)


# 第一次停止訊號才進入排空，避免外層轉送的重複訊號中斷證據寫入。
def stop_run(_signum, _frame):
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    signal.signal(signal.SIGHUP, signal.SIG_IGN)
    raise KeyboardInterrupt


# 真實執行前先取得專用目錄的獨占證據位置，並拒絕重用本次識別。
def run(project, local, db_id, app_id):
    require(
        bool(re.fullmatch(r"store-ordering-load-[0-9a-f]{12}", project))
        and bool(re.fullmatch(r"[0-9a-f]{64}", db_id))
        and bool(re.fullmatch(r"[0-9a-f]{64}", app_id))
        and db_id != app_id,
        "invalid_load_project_or_container_ids",
    )
    expected = ROOT / ".local" / project.removeprefix("store-ordering-")
    local = local.absolute()
    require(
        Path.cwd() == ROOT and local == expected and not expected.parent.is_symlink()
        and not expected.is_symlink(),
        "invalid_load_directory",
    )
    info = local.stat()
    require(stat.S_ISDIR(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o700 and info.st_uid == os.getuid(),
            "invalid_load_directory_permissions")
    evidence = local / "benchmark"
    evidence.mkdir(mode=0o700)
    summary_path = evidence / "summary.json"
    summary = {
        "status": "running", "project": project, "started_at": utc(), "windows": {}, "stage": "preflight",
        "scope": {
            "automated": ["application_ingress_load_measurement", "persisted_consistency"],
            "separate_evidence": ["observed_mock_service_http", "container_limits_and_usage",
                                  "host_background_load", "runtime_versions"],
        },
        "python": sys.version.split()[0],
        "thresholds": {"read_p95_ms": 500, "transaction_operation_p95_ms": 1000,
                       "unexpected_error_rate_exclusive": 0.01, "correctness_errors": 0},
        "inner_http": {
            "expected": {phase: {"create": seconds * 2, "decision": seconds * 2} for phase, seconds in WINDOWS.items()},
            "observed": None,
        },
        "callback_source": "persisted_mock_event_bytes",
        "mock_sender_queue_poll_measured": False,
    }
    write_json(summary_path, summary)
    context = {"project": project, "db_id": db_id}
    outcomes = {}
    audit = {}
    stores = []
    interrupted = False
    try:
        # 建立可重現來源識別與專案核對後，才啟動登入及種子操作。
        commit = subprocess.run(
            ["git", "rev-parse", "HEAD"], check=True, capture_output=True, timeout=5,
        ).stdout.decode().strip()
        summary["git_head"] = commit
        summary["source_sha256"] = {
            name: hashlib.sha256((ROOT / "bin" / name).read_bytes()).hexdigest()
            for name in ("load-orders.py", "load-orders-seed.sql", "load-orders-audit.sql")
        }
        cookie, key = verify_target(project, local, db_id, app_id)
        context.update(key=key, sessions=authenticate(local, cookie))
        stores = [context["sessions"][email][0]["storeId"] for email in STAFF]
        summary["stage"] = "fixture"
        write_json(summary_path, summary)
        setup_fixture(context)
        summary["fixture"] = {"historical_orders": 10_000, "stores": stores, "historical_max_id": context["cutoff"]}

        # 每個階段各自記錄實際時窗；暖機排空後才開始正式計時。
        with (evidence / "requests.jsonl").open("x", encoding="utf-8", buffering=1) as journal:
            for phase in WINDOWS:
                summary["stage"] = phase
                window = summary["windows"][phase] = {}
                write_json(summary_path, summary)
                outcomes[phase], interrupted = run_window(phase, context, journal, window)
                write_json(summary_path, summary)
                if interrupted:
                    break
    except KeyboardInterrupt:
        interrupted = True
    except InvalidRun as exc:
        summary["failure"] = str(exc)
    except Exception as exc:
        # 顯示失敗位置與類別，保留除錯線索而不輸出例外本文及區域變數。
        summary["failure"] = f"{summary['stage']}_{type(exc).__name__}"
        frame = next(frame for frame in reversed(traceback.extract_tb(exc.__traceback__)) if frame.filename == __file__)
        summary["failure_at"] = f"{frame.name}:{frame.lineno}"

    # 即使負載階段失敗也要查回已提交資料；以專案實際 DB ID 維持同一稽核目標。
    if "cutoff" in context:
        try:
            app, mock = snapshot(db_id, context["cutoff"])
            audit = check_persistence(context, outcomes, app, mock)
            write_json(evidence / "consistency.json", audit)
            summary["consistency"] = {
                "passed": audit["passed"], "checks": audit["checks"], "observed_counts": audit["observed_counts"],
            }
        except InvalidRun as exc:
            summary["audit_failure"] = str(exc)
        except KeyboardInterrupt:
            interrupted = True
            summary["audit_failure"] = "interrupted"
        except Exception as exc:
            summary["audit_failure"] = f"audit_{type(exc).__name__}"

    # 最後一道檢查包含量測與持久化；未完成或中止必須傳回非零狀態。
    checks = qualification(summary["windows"], audit, stores)
    passed = all(checks.values()) and not interrupted and "failure" not in summary and "audit_failure" not in summary
    summary.update(status="passed" if passed else "failed", checks=checks, interrupted=interrupted, finished_at=utc())
    write_json(summary_path, summary)
    print(f"負載量測及資料核對{'通過' if passed else '失敗或未完成'}，結果見 {summary_path.relative_to(ROOT)}")
    print("實際內部 HTTP、容器配額與資源用量、主機背景負載及執行環境版本需另行蒐證。")
    return 0 if passed else 1


# 以可控時鐘執行真實排程，讓兩筆晚於批次的決定分別遇到可用及逾時事件。
def self_test_prefetch():
    from io import StringIO
    from unittest.mock import Mock, patch

    now = 0.0
    window = {}
    journal = StringIO()
    context = {"db_id": "test-db", "sessions": {
        email: ({"storeId": i + 1}, {}) for i, email in enumerate(STAFF)
    }}

    # 只有排程等候下一個時槽或結束排空時才推進假時鐘。
    def advance(seconds):
        nonlocal now
        now += seconds

    # 保留 worker 的完成延遲，讓排程依 Future 就緒與否判斷相依操作。
    def submit(function, *args):
        if function is execute:
            slot, phase, ctx, prior, start = args
            result = request_record(slot, phase, ctx)
            delay = 0.9 if slot.operation == "decision" and slot.chain in (9, 19) else 0.01
            identity = {"payment_id": f"payment-{slot.chain}"} if slot.chain is not None else {}
            if slot.operation == "callback":
                assert prior["event"] == (f"event-{slot.chain}", b"event bytes")
            result.update(issue_offset_s=now - start, completion_offset_s=now - start + delay,
                          http_status=201 if slot.operation == "create" else 200,
                          latency_ms=delay * 1000, identity=identity)
        else:
            assert function is prefetch
            ids = args[1]
            delay = 2.0 if ids == ["payment-19"] else 0.2
            events = {pid: (pid.replace("payment-", "event-"), b"event bytes") for pid in ids}
            result = events, {"requested": len(ids), "found": len(ids), "error": None, "duration_ms": delay * 1000}
        ready_at = now + delay

        # 若在正常時窗內取用未完成的工作，測試立即失敗以防排程偷偷等待。
        def result_when_ready():
            if now < ready_at:
                assert window["elapsed"]
                advance(ready_at - now)
            return result

        # 只有完成時間到達時，排程才可取得結果而不阻塞。
        def is_ready():
            return now >= ready_at

        future = Mock()
        future.done.side_effect = is_ready
        future.result.side_effect = result_when_ready
        return future

    # 只替換 worker 和時間邊界，照常產出請求證據及統計。
    with (
        patch.dict(WINDOWS, formal=10),
        patch.object(time, "monotonic", side_effect=lambda: now),
        patch.object(time, "sleep", side_effect=advance),
        patch(f"{__name__}.ThreadPoolExecutor") as pool,
    ):
        pool.return_value.__enter__.return_value.submit.side_effect = submit
        stages, interrupted = run_window("formal", context, journal, window)

    assert not interrupted and window["elapsed"]
    assert window["issued"] == 199 and window["complete_transactions"] == 19
    assert stages[9, "callback"]["error"] is None
    assert stages[19, "callback"]["error"] == "event_not_ready"
    assert stages[19, "callback"]["issue_offset_s"] is None
    assert len(journal.getvalue().splitlines()) == 200
    assert [(job["chains"], job["duration_ms"]) for job in window["prefetch"]
            if job["kind"] == "supplemental"] == [([9], 200.0), ([19], 2000.0)]


# 使用純記憶體驗證配額、相依時槽、缺口及門檻，不接觸本機資料庫。
def self_test():
    from unittest.mock import patch

    # 模擬程序群組與外層重複送出停止訊號，排空期間不可再拋出中斷。
    previous = {signum: signal.signal(signum, stop_run) for signum in (signal.SIGTERM, signal.SIGHUP)}
    try:
        try:
            signal.raise_signal(signal.SIGTERM)
        except KeyboardInterrupt:
            signal.raise_signal(signal.SIGTERM)
            signal.raise_signal(signal.SIGHUP)
        else:
            raise AssertionError("first stop signal did not interrupt")
    finally:
        for signum, handler in previous.items():
            signal.signal(signum, handler)

    self_test_prefetch()

    # 驗證回呼請求使用 Laravel 掛載後的 API 路徑，並保留事件原文。
    payment_id = "00000000-0000-4000-8000-000000000001"
    event_id = "00000000-0000-4000-8000-000000000002"
    raw = json.dumps({"eventId": event_id, "paymentId": payment_id, "orderId": 1,
                      "amount": 45, "currency": "TWD", "status": "paid"}).encode()
    context = {"sessions": {STAFF[0]: ({"storeId": 1}, {})}, "product": {"price": 45}, "key": b"test"}
    prior = {"order_id": 1, "payment_id": payment_id, "event": (event_id, raw)}
    method, path, body, _, identifier = request_for(Slot(151, "callback", 0, 0), "formal", context, prior)
    assert (method, path, body, identifier) == ("POST", "/api/payment-notifications", raw, event_id)

    # 以 psql 的 JSON 布林輸出確認只有空白模擬資料庫可以進入播種。
    for empty, expected in ((False, "mock_database_not_empty"), (True, "seed_psql_P0002")):
        preflight = subprocess.CompletedProcess([], 0, json.dumps(empty).encode() + b"\n", b"")
        seed_rejected = subprocess.CompletedProcess([], 3, b"", b"ERROR:  P0002\n")
        with patch(f"{__name__}.docker", side_effect=[preflight, seed_rejected]) as command:
            try:
                setup_fixture({"db_id": "test-db"})
            except InvalidRun as exc:
                assert str(exc) == expected
            else:
                raise AssertionError("fixture preflight did not stop at the expected guard")
            assert command.call_count == (2 if empty else 1)
            assert b"SELECT to_json(NOT EXISTS" in command.call_args_list[0].kwargs["data"]

    # 用假連線跑真實 HTTP 輔助函式，並確認連線失敗不會增加已送出數。
    with patch("http.client.HTTPConnection") as connection_type:
        connection = connection_type.return_value
        response = connection.getresponse.return_value.__enter__.return_value
        response.status = 200
        response.read.return_value = b"{}"
        assert send_http("GET", "/api/session")[0] == 200
        connection.request.assert_called_once_with("GET", "/api/session", body=None,
                                                  headers={"Accept": "application/json"})
        connection.request.reset_mock()
        connection.connect.side_effect = ConnectionRefusedError()
        context = {"sessions": {STAFF[0]: ({"storeId": 1}, {})}, "product": {}}
        failed = execute(Slot(0, "list", 0, None), "formal", context, None, time.monotonic())
        assert failed["attempt_offset_s"] is not None and failed["issue_offset_s"] is None
        assert failed["error"] == "transport_ConnectionRefusedError"
        connection.request.assert_not_called()

    for phase, seconds in WINDOWS.items():
        slots = list(schedule(seconds))
        assert [slot.index for slot in slots] == list(range(seconds * RPS)), phase
        assert Counter(slot.operation for slot in slots) == {
            "list": seconds * 6, "detail": seconds * 4, "inventory": seconds * 2, **dict.fromkeys(WRITES, seconds * 2),
        }
        assert Counter((slot.operation, slot.store) for slot in slots) == {
            (kind, store): count // 2 for kind, count in Counter(slot.operation for slot in slots).items()
            for store in (0, 1)
        }
        assert Counter((chain % 2, outcome(chain)) for chain in range(seconds * 2)) == {
            (0, "paid"): seconds * 4 // 5, (1, "paid"): seconds * 4 // 5,
            (0, "failed"): seconds // 5, (1, "failed"): seconds // 5,
        }
        write_slots = [slot for slot in slots if slot.chain is not None]
        for first, second in zip(write_slots, write_slots[20:]):
            if first.operation != "callback":
                assert first.chain == second.chain and second.offset - first.offset > 2.49

    # 以可預期的完整量測和一個漏送案例檢驗所有通過門檻的組合。
    context = {"sessions": {email: ({"storeId": i + 1}, {}) for i, email in enumerate(STAFF)}}
    rows = []
    for slot in schedule(300):
        row = request_record(slot, "formal", context)
        row.update(issue_offset_s=slot.offset, completion_offset_s=slot.offset + 0.01,
                   http_status=201 if slot.operation == "create" else 200, latency_ms=10, identity={})
        rows.append(row)
    warm = {"elapsed": True, "issued": 1200}
    audit = {"passed": True}
    formal = {"elapsed": True, **measure(rows, 300)}
    assert all(qualification({"warmup": warm, "formal": formal}, audit, [1, 2]).values())
    formal["unexpected_error_rate"] = 0.01
    assert not qualification({"warmup": warm, "formal": formal}, audit, [1, 2])["unexpected_error_rate"]
    formal["p95_ms"]["transaction_operation"] = 1000.1
    assert not qualification({"warmup": warm, "formal": formal}, audit, [1, 2])["transaction_operation_p95"]
    callback = next(row for row in rows if row["operation"] == "callback")
    callback.update(issue_offset_s=None, completion_offset_s=None, http_status=None, latency_ms=None,
                    identity=None, error="prerequisite_incomplete")
    measured = measure(rows, 300)
    assert measured["issued"] == 5999 and measured["complete_transactions"] == 599
    assert measured["not_issued"] == 1 and measured["unexpected_http_or_transport_errors"] == 0
    assert p95(list(range(1, 21))) == 19
    print("負載配額、交易相依與失敗門檻自我測試通過")


# 僅由 bin/local 提供已核對目標；自我測試不需要任何運行中的服務。
def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("project", nargs="?")
    parser.add_argument("local_dir", nargs="?")
    parser.add_argument("db_id", nargs="?")
    parser.add_argument("app_id", nargs="?")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return 0
    if not all((args.project, args.local_dir, args.db_id, args.app_id)):
        parser.error("請透過 bin/local load-<12 位小寫十六進位識別> benchmark 執行")
    os.umask(0o077)
    signal.signal(signal.SIGTERM, stop_run)
    signal.signal(signal.SIGHUP, stop_run)
    try:
        return run(args.project, Path(args.local_dir), args.db_id, args.app_id)
    except InvalidRun as exc:
        print(f"無法開始效能驗收（{exc}）", file=sys.stderr)
    except (OSError, KeyboardInterrupt) as exc:
        print(f"無法完成效能驗收（{type(exc).__name__}）", file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
