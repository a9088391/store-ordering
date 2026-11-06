<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class StoreOrderingApiTest extends TestCase
{
    private const HEADQUARTERS = 'hq@example.test';
    private const TAIPEI_STAFF = 'taipei@example.test';
    private const TAICHUNG_STAFF = 'taichung@example.test';

    // 匿名頁能取得 token，且私人資源即使省略 Accept 標頭也會回應固定的未登入錯誤。
    public function testAnonymousCanReadSessionButCannotReadPrivateResources(): void
    {
        // 匿名登入頁先取得 token，再確認每一個私人讀取入口都封鎖匿名請求。
        $session = $this
            ->getJson('/api/session')
            ->assertOk();
        $csrfToken = $session->json('csrfToken');
        self::assertIsString($csrfToken);
        self::assertNotEmpty($csrfToken);
        $session->assertExactJson(['user' => null, 'csrfToken' => $csrfToken]);

        foreach (['/api/stores', '/api/products', '/api/stores/1/inventory'] as $path) {
            $this
                ->get($path)
                ->assertUnauthorized()
                ->assertJsonPath('code', 'UNAUTHENTICATED');
        }
    }

    // 從匿名會話登入後輪替 ID 和 token，登出後已取得的登入 cookie 也不能重播。
    public function testLoginRotatesSessionAndLogoutRevokesTheOldCookie(): void
    {
        // 先取得登入前的會話，供後續比較及重播。
        $password = $this->insertFixture();
        $anonymous = $this->getJson('/api/session')->assertOk();
        $anonymousCookie = $this->sessionCookie($anonymous);

        // 同一個匿名 cookie 完成登入，回應應對應新會話及正規化後的帳號。
        $login = $this->login('  HQ@EXAMPLE.TEST  ', $password, cookie: $anonymousCookie);
        $login->assertOk()->assertJsonPath('user', [
            'id' => 1, 'name' => '總部人員', 'role' => 'headquarters', 'storeId' => null,
        ]);
        $loginCookie = $this->sessionCookie($login);
        self::assertTrue($loginCookie->isHttpOnly());
        self::assertFalse($loginCookie->isSecure());
        self::assertSame('lax', $loginCookie->getSameSite());
        self::assertNotSame($anonymousCookie->getValue(), $loginCookie->getValue());
        self::assertNotSame($anonymous->json('csrfToken'), $login->json('csrfToken'));

        // 舊匿名 ID 未被升級，保有新登入 ID 才能讀取私人資源。
        $this->newRequest($anonymousCookie);
        $this->getJson('/api/products')->assertUnauthorized();
        $this->newRequest($loginCookie);
        $this->getJson('/api/products')->assertOk();

        // 登出回傳新匿名會話，原有登入 ID 與新匿名 ID 都不得讀私人資源。
        $this->newRequest($loginCookie);
        $logout = $this
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('user', null);
        $logoutCookie = $this->sessionCookie($logout);
        self::assertNotSame($loginCookie->getValue(), $logoutCookie->getValue());
        self::assertNotSame($login->json('csrfToken'), $logout->json('csrfToken'));
        foreach ([$loginCookie, $logoutCookie] as $cookie) {
            $this->newRequest($cookie);
            $this->getJson('/api/products')->assertUnauthorized();
        }
    }

    // 三種身分都可使用商品目錄，兩間門市的庫存數字與可見範圍各自獨立。
    public function testEachRoleCanOnlyReadItsAuthorizedStores(): void
    {
        // 明列每個帳號預期看到的門市與同一份商品目錄。
        $password = $this->insertFixture();
        $taipei = ['id' => 1, 'name' => '台北門市'];
        $taichung = ['id' => 2, 'name' => '台中門市'];
        $coffee = ['id' => 1, 'name' => '濾掛咖啡', 'price' => 45];
        $cookies = ['id' => 2, 'name' => '燕麥餅乾', 'price' => 60];
        $meta = ['page' => 1, 'perPage' => 50, 'total' => 2];
        $cases = [
            self::HEADQUARTERS => [null, [$taipei, $taichung]],
            self::TAIPEI_STAFF => [1, [$taipei]],
            self::TAICHUNG_STAFF => [2, [$taichung]],
        ];

        foreach ($cases as $email => [$storeId, $stores]) {
            // 以實際登入建立的 cookie 驗證清單、角色及共用商品目錄。
            $login = $this
                ->login($email, $password)
                ->assertOk()
                ->assertJsonPath('user.storeId', $storeId)
                ->assertJsonPath('user.role', $storeId === null ? 'headquarters' : 'staff');
            $cookie = $this->sessionCookie($login);
            $this->newRequest($cookie);
            $this
                ->getJson('/api/stores')
                ->assertOk()
                ->assertExactJson(['data' => $stores]);
            $this->newRequest($cookie);
            $this
                ->getJson('/api/products')
                ->assertOk()
                ->assertExactJson(['data' => [$coffee, $cookies], 'meta' => $meta]);

            // 台中零庫存商品仍保留在結果，且金額及庫存必須是 JSON 整數。
            foreach ($stores as $store) {
                [$coffeeStock, $cookieStock] = $store['id'] === 1 ? [12, 3] : [4, 0];
                $this->newRequest($cookie);
                $inventory = $this->getJson('/api/stores/' . $store['id'] . '/inventory')->assertOk();
                self::assertSame([
                    $coffee + ['onHand' => $coffeeStock, 'available' => $coffeeStock],
                    $cookies + ['onHand' => $cookieStock, 'available' => $cookieStock],
                ], $inventory->json('data'));
                self::assertSame($meta, $inventory->json('meta'));
            }

            // 越權查詢必須與不存在的門市完全相同，兩名店員都要通過此檢查。
            if ($storeId !== null) {
                $this->newRequest($cookie);
                $missing = $this->getJson('/api/stores/999/inventory')->assertNotFound();
                $this->newRequest($cookie);
                $this
                    ->getJson('/api/stores/' . (3 - $storeId) . '/inventory')
                    ->assertNotFound()
                    ->assertExactJson($missing->json());
            }
        }
    }

    // 共用分頁在商品與庫存都能取回第二頁，並接受最大頁長、拒絕超限或不合法頁碼。
    public function testCatalogPaginationEnforcesBoundsAndKeepsTotals(): void
    {
        // 以已登入會話測試分頁，確保結果來自查詢而非登入限制。
        $password = $this->insertFixture();
        $cookie = $this->sessionCookie($this->login(self::HEADQUARTERS, $password)->assertOk());

        foreach (['/api/products', '/api/stores/1/inventory'] as $path) {
            // 商品識別順序及總筆數在翻頁時維持一致。
            $this->newRequest($cookie);
            $this
                ->getJson($path . '?page=2&perPage=1')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', '燕麥餅乾')
                ->assertJsonPath('meta', ['page' => 2, 'perPage' => 1, 'total' => 2]);

            // 邊界頁長有效，超限、非正頁碼及超過安全整數的頁碼都回應驗證錯誤。
            $this->newRequest($cookie);
            $this
                ->getJson($path . '?perPage=100')
                ->assertOk()
                ->assertJsonPath('meta.perPage', 100);
            foreach (['perPage=101', 'page=0', 'page=9007199254740992'] as $query) {
                $this->newRequest($cookie);
                $this
                    ->getJson($path . '?' . $query)
                    ->assertUnprocessable()
                    ->assertJsonPath('code', 'VALIDATION_FAILED');
            }
        }
    }

    // 錯誤密碼與未知帳號使用同一回應，錯誤格式則用一般欄位驗證錯誤。
    public function testLoginFailureDoesNotIdentifyExistingAccounts(): void
    {
        // 建立已知帳號作對照，核對錯誤憑證與未知帳號的回應。
        $this->insertFixture();
        $badPassword = $this
            ->login('  HQ@EXAMPLE.TEST  ', 'incorrect')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this
            ->login('  MISSING@EXAMPLE.TEST  ', 'incorrect')
            ->assertUnauthorized()
            ->assertExactJson($badPassword->json());

        // 格式無效時提供固定欄位錯誤。
        $this
            ->login('  invalid-email  ', 'incorrect')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    // 跨連線來源使用同一正規化帳號時共用五次額度，成功登入也會佔用額度。
    public function testAccountLimiterCountsSuccessfulAttemptsAndRecoversAtSixtySeconds(): void
    {
        // 固定時鐘以精確檢查時間邊界，並使用真實登入資料。
        $this->freezeTime();
        $password = $this->insertFixture();

        // 不同來源及大小寫變體仍命中同一個帳號額度。
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $email = $attempt % 2 === 0 ? '  HQ@EXAMPLE.TEST  ' : self::HEADQUARTERS;
            $this->login($email, $password, '192.0.2.' . $attempt)->assertOk();
        }
        $this
            ->login(self::HEADQUARTERS, $password, '192.0.2.6')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('code', 'LOGIN_THROTTLED');

        // 只封鎖超量的帳號，且第 60 秒起可再次成功登入。
        $this->login(self::TAIPEI_STAFF, $password, '192.0.2.6')->assertOk();
        $this->travel(59)->seconds();
        $this
            ->login(self::HEADQUARTERS, $password, '192.0.2.6')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '1');
        $this->travel(1)->seconds();
        $this->login(self::HEADQUARTERS, $password, '192.0.2.6')->assertOk();
    }

    // 固定來源嘗試多個帳號仍會受限，代理標頭不能繞過來源額度。
    public function testSourceLimiterIgnoresSpoofedForwardedAddresses(): void
    {
        // 固定時鐘以精確檢查時間邊界，並使用真實登入資料。
        $this->freezeTime();
        $password = $this->insertFixture();

        // 每次更換帳號與宣稱的來源，只維持相同的伺服器連線來源。
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeader('X-Forwarded-For', '192.0.2.' . $attempt);
            $this->login('missing' . $attempt . '@example.test', 'incorrect')->assertUnauthorized();
        }
        $this->withHeader('X-Forwarded-For', '192.0.2.6');
        $this
            ->login(self::HEADQUARTERS, $password)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('code', 'LOGIN_THROTTLED');

        // 其他來源可繼續登入，原來源也在恰好 60 秒後恢復。
        $this->login(self::HEADQUARTERS, $password, '192.0.2.7')->assertOk();
        $this->travel(60)->seconds();
        $this->login(self::HEADQUARTERS, $password)->assertOk();
    }

    // 有活動會重新起算閒置時間，累計正好閒置 30 分鐘即拒絕舊會話。
    public function testSessionIdleDeadlineIsInclusive(): void
    {
        // 從固定時刻開始登入，讓後續閒置間隔有確定起點。
        $this->freezeTime();
        $password = $this->insertFixture();
        $cookie = $this->sessionCookie($this->login(self::HEADQUARTERS, $password)->assertOk());

        // 兩次請求都在上一個活動後 1799 秒，即使超過登入後的 30 分鐘也仍可使用。
        for ($visit = 1; $visit <= 2; $visit++) {
            $this->travel(1799)->seconds();
            $this->newRequest($cookie);
            $this->getJson('/api/products')->assertOk();
        }

        // 恰好閒置 1800 秒時先失效再檢查授權，舊 ID 的會話狀態也必須匿名。
        $this->travel(1800)->seconds();
        $this->newRequest($cookie);
        $this
            ->getJson('/api/products')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->newRequest($cookie);
        $this
            ->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('user', null);
    }

    // 保持定期活動到第 28799 秒仍有效，第 28800 秒不受剛才的活動延長。
    public function testSessionAbsoluteDeadlineAppliesEvenWithRecentActivity(): void
    {
        // 保存原始登入時間，供活動間隔與固定期限使用同一起點。
        $this->freezeTime();
        $startedAt = now()->toImmutable();
        $password = $this->insertFixture();
        $cookie = $this->sessionCookie($this->login(self::HEADQUARTERS, $password)->assertOk());

        // 定期活動跨過所有閒置區間，讓最後一次拒絕只可能來自絕對期限。
        for ($seconds = 1799; $seconds < 28799; $seconds += 1799) {
            $this->travelTo($startedAt->addSeconds($seconds));
            $this->newRequest($cookie);
            $this->getJson('/api/products')->assertOk();
        }
        $this->travelTo($startedAt->addSeconds(28799));
        $this->newRequest($cookie);
        $this->getJson('/api/products')->assertOk();

        // 登入滿 8 小時立即拒絕私人資源，舊 ID 也只能取得匿名會話。
        $this->travelTo($startedAt->addSeconds(28800));
        $this->newRequest($cookie);
        $this
            ->getJson('/api/products')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->newRequest($cookie);
        $this
            ->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('user', null);
    }

    // 以原始 JSON 位元組驗證 64 KiB 邊界，包括無 Content-Length 的請求。
    public function testBodyLimitChecksBytesEvenWithoutContentLength(): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        // 恰好 64 KiB 時請求應走到欄位驗證；超過一個位元組時必須先拒絕。
        $body = str_pad('{"email":true}', 65536);
        $this
            ->call('POST', '/api/login', [], [], [], $server, $body)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->newRequest();
        $this
            ->call('POST', '/api/login', [], [], [], $server, $body . ' ')
            ->assertStatus(413)
            ->assertExactJson(['code' => 'PAYLOAD_TOO_LARGE', 'message' => '請求本文超過 64 KiB。']);
    }

    // 建立可辨識的雙門市資料及臨時密碼，所有讀取和登入皆透過 HTTP 驗證。
    private function insertFixture(): string
    {
        // 使用固定識別與差異明顯的初始數量，讓跨店錯用或漏掉零庫存立即可見。
        DB::table('stores')->insert([
            ['id' => 1, 'name' => '台北門市'],
            ['id' => 2, 'name' => '台中門市'],
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'name' => '濾掛咖啡', 'price' => 45],
            ['id' => 2, 'name' => '燕麥餅乾', 'price' => 60],
        ]);
        DB::table('store_inventory')->insert([
            ['store_id' => 1, 'product_id' => 1, 'on_hand' => 12],
            ['store_id' => 1, 'product_id' => 2, 'on_hand' => 3],
            ['store_id' => 2, 'product_id' => 1, 'on_hand' => 4],
            ['store_id' => 2, 'product_id' => 2, 'on_hand' => 0],
        ]);

        // 密碼每次測試即時生成，資料庫使用與正式登入相同的 Laravel 雜湊。
        $password = bin2hex(random_bytes(24));
        $hash = Hash::make($password);
        DB::table('users')->insert([
            [
                'id' => 1,
                'email' => self::HEADQUARTERS,
                'name' => '總部人員',
                'role' => 'headquarters',
                'store_id' => null,
                'password' => $hash,
            ],
            [
                'id' => 2,
                'email' => self::TAIPEI_STAFF,
                'name' => '台北門市店員',
                'role' => 'staff',
                'store_id' => 1,
                'password' => $hash,
            ],
            [
                'id' => 3,
                'email' => self::TAICHUNG_STAFF,
                'name' => '台中門市店員',
                'role' => 'staff',
                'store_id' => 2,
                'password' => $hash,
            ],
        ]);

        return $password;
    }

    // 透過 API 登入，以指定連線來源與 cookie 重現不同客戶端。
    private function login(
        string $email,
        string $password,
        string $source = '192.0.2.1',
        ?Cookie $cookie = null,
    ): TestResponse {
        $this->newRequest($cookie, $source);

        return $this->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    // 讀取回應送出的真實 session cookie，讓後續 HTTP 請求測試會話持久化。
    private function sessionCookie(TestResponse $response): Cookie
    {
        $cookie = $response->getCookie(config('session.cookie'));
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie;
    }

    // 模擬每個 HTTP 請求重建 guard 與 session driver，仍沿用隔離測試的資料庫交易。
    private function newRequest(?Cookie $cookie = null, string $source = '192.0.2.1'): void
    {
        // 測試框架會重用應用容器，清除前一個請求的驗證及 session 記憶體狀態。
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        // 只帶上案例指定的 cookie 與連線來源，避免前一個客戶端影響結果。
        $this->defaultCookies = [];
        $this->withServerVariables(['REMOTE_ADDR' => $source]);
        if ($cookie !== null) {
            $this->withCredentials()->withCookie($cookie->getName(), $cookie->getValue());
        }
    }
}
