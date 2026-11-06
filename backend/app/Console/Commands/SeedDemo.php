<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use JsonException;
use SensitiveParameter;
use Throwable;

final class SeedDemo extends Command
{
    protected $signature = 'store-ordering:seed-demo {accounts : Path to the private accounts JSON file}';

    protected $description = '初始化示範門市、商品、庫存與帳號';

    private const ACCOUNTS = [
        'hq@example.test' => ['name' => '總部人員', 'role' => 'headquarters', 'store' => null],
        'taipei@example.test' => ['name' => '台北門市店員', 'role' => 'staff', 'store' => '台北門市'],
        'taichung@example.test' => ['name' => '台中門市店員', 'role' => 'staff', 'store' => '台中門市'],
    ];

    // 在本案展示資料庫初始化，並以固定訊息避免把憑證或 SQL 繫結值送入輸出。
    public function handle(): int
    {
        // 僅接受完整的私有憑證檔，檔案有問題時不開始資料庫操作。
        $passwords = $this->readPasswords((string) $this->argument('accounts'));
        if ($passwords === null) {
            $this->error('初始帳號檔必須是包含三組有效憑證的 0600 JSON 檔案。');

            return self::FAILURE;
        }

        try {
            // 檢查實際連線目標，避免初始化到本案以外或 PHPUnit 專用的資料庫。
            if (
                DB::getDriverName() !== 'pgsql'
                || !in_array(DB::scalar('SELECT current_database()'), ['store_ordering', 'store_ordering_test'], true)
            ) {
                $this->error('請指定本案的 PostgreSQL 展示資料庫。');

                return self::FAILURE;
            }

            // 將初始化視為整筆操作，與同時進行的另一個初始化命令依序執行。
            DB::transaction(fn () => $this->insertDemo($passwords));
        } catch (DomainException) {
            $this->error('既有示範帳號的密碼或門市範圍與初始設定不符。');

            return self::FAILURE;
        } catch (Throwable $error) {
            $this->error('示範資料初始化失敗（' . $error::class . '）。');

            return self::FAILURE;
        }

        $this->info('示範資料已備妥。');

        return self::SUCCESS;
    }

    // 在同一個開啟的檔案上核對權限與內容，限制憑證檔大小並保留原始密碼。
    private function readPasswords(string $path): ?array
    {
        // 先排除非普通檔案及符號連結，避免阻塞在特殊檔案或意外跟隨路徑。
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $file = @fopen($path, 'rb');
        if ($file === false) {
            return null;
        }

        try {
            // 以已開啟檔案的實際權限作為準據，避免單憑路徑檢查。
            $stat = fstat($file);
            if ($stat === false || ($stat['mode'] & 0177777) !== 0100600) {
                return null;
            }
            $content = stream_get_contents($file, 4097);
            if ($content === false || strlen($content) > 4096) {
                return null;
            }

            // 只接收預期的帳號集合，並拒絕不適用於 bcrypt 的密碼。
            $passwords = json_decode($content, true, depth: 4, flags: JSON_THROW_ON_ERROR);
            if (
                !is_array($passwords)
                || count($passwords) !== count(self::ACCOUNTS)
                || array_diff_key(self::ACCOUNTS, $passwords) !== []
            ) {
                return null;
            }

            foreach ($passwords as $password) {
                if (!is_string($password) || !preg_match('/\A[^\x00]{1,72}\z/', $password)) {
                    return null;
                }
            }

            return $passwords;
        } catch (JsonException) {
            return null;
        } finally {
            fclose($file);
        }
    }

    // 僅補上不存在的示範資料，重試時保留已存在的庫存和帳號雜湊。
    private function insertDemo(#[SensitiveParameter] array $passwords): void
    {
        // 以交易層級鎖序列化整組示範資料的核對與補入。
        DB::select('SELECT pg_advisory_xact_lock(3, 0)');

        // 以唯一名稱取得既有或新建立的門市、商品識別。
        DB::table('stores')->insertOrIgnore([['name' => '台北門市'], ['name' => '台中門市']]);
        $stores = DB::table('stores')->pluck('id', 'name');
        DB::table('products')->insertOrIgnore([
            ['name' => '濾掛咖啡', 'price' => 45],
            ['name' => '燕麥餅乾', 'price' => 60],
        ]);
        $products = DB::table('products')->pluck('id', 'name');

        // 複合主鍵讓重試補入缺少的紀錄，同時保留操作過的既有庫存。
        DB::table('store_inventory')->insertOrIgnore([
            ['store_id' => $stores['台北門市'], 'product_id' => $products['濾掛咖啡'], 'on_hand' => 12],
            ['store_id' => $stores['台北門市'], 'product_id' => $products['燕麥餅乾'], 'on_hand' => 3],
            ['store_id' => $stores['台中門市'], 'product_id' => $products['濾掛咖啡'], 'on_hand' => 4],
            ['store_id' => $stores['台中門市'], 'product_id' => $products['燕麥餅乾'], 'on_hand' => 0],
        ]);

        // 先批次讀取現有帳號，所有密碼僅在記憶體驗證或轉為雜湊。
        $users = DB::table('users')
            ->whereIn('email', array_keys(self::ACCOUNTS))
            ->get()
            ->keyBy('email');
        $createdAt = now();
        $newUsers = [];
        foreach (self::ACCOUNTS as $email => $account) {
            $storeId = $account['store'] === null ? null : (int) $stores[$account['store']];
            $user = $users->get($email);

            // 發現現有帳號與初始化憑證或授權不符時，回滾整筆操作。
            if ($user !== null) {
                if (
                    $user->role !== $account['role']
                    || ($user->store_id === null ? null : (int) $user->store_id) !== $storeId
                    || !Hash::check($passwords[$email], $user->password)
                ) {
                    throw new DomainException();
                }
                continue;
            }

            // 只把密碼雜湊交給資料庫，不更新已存在的帳號。
            $newUsers[] = [
                'name' => $account['name'],
                'email' => $email,
                'password' => Hash::make($passwords[$email]),
                'role' => $account['role'],
                'store_id' => $storeId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        DB::table('users')->insert($newUsers);
    }
}
