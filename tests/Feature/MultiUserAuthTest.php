<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\Storage;

$multiUserEnv = new class
{
    public string $tempDir = '';

    public string $dbPath = '';

    public string $filesDir = '';

    public ?Config $config = null;

    public ?Database $db = null;

    public ?Storage $storage = null;

    public ?App $app = null;

    public function app(): App
    {
        assert($this->app !== null);

        return $this->app;
    }

    public function db(): Database
    {
        assert($this->db !== null);

        return $this->db;
    }

    public function storage(): Storage
    {
        assert($this->storage !== null);

        return $this->storage;
    }
};

beforeEach(function () use ($multiUserEnv) {
    $multiUserEnv->tempDir = sys_get_temp_dir().'/fuxico_multiuser_test_'.uniqid();
    mkdir($multiUserEnv->tempDir, 0755, true);

    $multiUserEnv->dbPath = $multiUserEnv->tempDir.'/fuxico.sqlite';
    $multiUserEnv->filesDir = $multiUserEnv->tempDir.'/files';
    mkdir($multiUserEnv->filesDir, 0755, true);

    $multiUserEnv->config = new Config;
    $multiUserEnv->config->publicMode = false; // Private mode to strictly test auth
    $multiUserEnv->config->baseUrl = 'https://fuxico.test';
    $multiUserEnv->config->authTokens = ['master-secret-token'];

    $multiUserEnv->db = new Database($multiUserEnv->dbPath);
    $multiUserEnv->storage = new Storage($multiUserEnv->tempDir, $multiUserEnv->filesDir);
    $multiUserEnv->app = new App($multiUserEnv->config, $multiUserEnv->db, $multiUserEnv->storage);

    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER = [];
});

afterEach(function () use ($multiUserEnv) {
    if (is_dir($multiUserEnv->tempDir)) {
        $multiUserEnv->storage()->deleteDirectory($multiUserEnv->tempDir);
    }
});

test('GET /api/me identifies master admin and database fuxiqueiras correctly', function () use ($multiUserEnv) {
    // 1. Master token
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/me';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-secret-token';

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $output = ob_get_clean();

    $me = json_decode($output, true);
    expect($me)->toBeArray();
    expect($me['authenticated'])->toBeTrue();
    expect($me['role'])->toBe('admin');
    expect($me['username'])->toBe('mestre');
    expect($me['is_master'])->toBeTrue();

    // 2. Database user (fuxiqueira)
    $userId = bin2hex(random_bytes(8));
    $userToken = 'fux_test_'.bin2hex(random_bytes(8));
    $multiUserEnv->db()->createUser([
        'id' => $userId,
        'username' => 'lorelay_fox',
        'token' => $userToken,
        'role' => 'member',
        'created_at' => time(),
    ]);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$userToken;
    ob_start();
    $multiUserEnv->app()->handleRequest();
    $output = ob_get_clean();

    $meMember = json_decode($output, true);
    expect($meMember)->toBeArray();
    expect($meMember['authenticated'])->toBeTrue();
    expect($meMember['role'])->toBe('member');
    expect($meMember['username'])->toBe('lorelay_fox');
    expect($meMember['user_id'])->toBe($userId);
    expect($meMember['is_master'])->toBeFalse();
});

test('RBAC endpoints: /api/users CRUD restricted to admin fuxiqueira-mor', function () use ($multiUserEnv) {
    $memberToken = 'fux_member_123';
    $multiUserEnv->db()->createUser([
        'id' => 'u_member_1',
        'username' => 'babado_member',
        'token' => $memberToken,
        'role' => 'member',
        'created_at' => time(),
    ]);

    // 1. Regular member tries GET /api/users -> 403 Forbidden
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/users';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$memberToken;

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $output = ob_get_clean();

    expect(http_response_code())->toBe(403);
    $res = json_decode($output, true);
    expect($res['error'])->toBe('Forbidden');

    // 2. Admin creates a new fuxiqueira via POST /api/users
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/api/users';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-secret-token';
    $_POST = [
        'username' => 'marsha_p',
        'role' => 'member',
    ];

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $output = ob_get_clean();

    expect(http_response_code())->toBe(201);
    $resCreated = json_decode($output, true);
    expect($resCreated['success'])->toBeTrue();
    expect($resCreated['user']['username'])->toBe('marsha_p');
    expect($resCreated['user']['token'])->toStartWith('fux_');
    $marshaId = $resCreated['user']['id'];
    $marshaToken = $resCreated['user']['token'];

    // 3. Admin lists users via GET /api/users
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/users';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-secret-token';
    $_POST = [];

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $output = ob_get_clean();

    expect(http_response_code())->toBe(200);
    $resList = json_decode($output, true);
    expect($resList['total'])->toBe(2);

    // 4. Admin regenerates token via POST /api/users/{id}/token
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = "/api/users/{$marshaId}/token";
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-secret-token';

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $output = ob_get_clean();

    expect(http_response_code())->toBe(200);
    $resRegen = json_decode($output, true);
    expect($resRegen['success'])->toBeTrue();
    expect($resRegen['token'])->not->toBe($marshaToken);
    expect($resRegen['token'])->toStartWith('fux_');

    // Verify old token no longer resolves
    expect($multiUserEnv->app()->resolveAuth($marshaToken))->toBeNull();
    // New token resolves
    expect($multiUserEnv->app()->resolveAuth($resRegen['token']))->not->toBeNull();

    // 5. Admin deletes user via DELETE /api/users/{id}
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $_SERVER['REQUEST_URI'] = "/api/users/{$marshaId}";
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-secret-token';

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $output = ob_get_clean();

    expect(http_response_code())->toBe(200);
    expect($multiUserEnv->db()->findUserById($marshaId))->toBeNull();
});

test('Item ownership isolation: member cannot see or delete another members items', function () use ($multiUserEnv) {
    $tokenA = 'fux_user_a';
    $multiUserEnv->db()->createUser([
        'id' => 'user_a',
        'username' => 'fuxiqueira_a',
        'token' => $tokenA,
        'role' => 'member',
        'created_at' => time(),
    ]);

    $tokenB = 'fux_user_b';
    $multiUserEnv->db()->createUser([
        'id' => 'user_b',
        'username' => 'fuxiqueira_b',
        'token' => $tokenB,
        'role' => 'member',
        'created_at' => time(),
    ]);

    // Member A creates item 1 (shorten URL)
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokenA;
    $_POST = ['url' => 'https://example.com/fofoca-a'];

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outA = ob_get_clean();

    $itemA = $multiUserEnv->db()->listItems(10, 0, null, null, 'user_a');
    expect($itemA)->toHaveCount(1);
    $slugA = $itemA[0]['slug'];
    expect($itemA[0]['user_id'])->toBe('user_a');
    expect($itemA[0]['author'])->toBe('fuxiqueira_a');

    // Also Member A has a file
    $multiUserEnv->db()->createItem([
        'id' => 'item_fa',
        'slug' => 'fa-slug',
        'original_name' => 'fa.txt',
        'type' => 'file',
        'file_path' => 'fa-slug.txt',
        'mime_type' => 'text/plain',
        'size' => 10,
        'x_token' => 'tok_fa',
        'is_oneshot' => 0,
        'created_at' => time(),
        'views' => 0,
        'user_id' => 'user_a',
    ]);
    file_put_contents($multiUserEnv->filesDir.'/fa-slug.txt', 'hello A');

    // Member B creates item 2 (shorten URL)
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokenB;
    $_POST = ['url' => 'https://example.com/fofoca-b'];

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outB = ob_get_clean();

    $itemB = $multiUserEnv->db()->listItems(10, 0, null, null, 'user_b');
    expect($itemB)->toHaveCount(1);
    $slugB = $itemB[0]['slug'];

    // Also Member B has a file
    $multiUserEnv->db()->createItem([
        'id' => 'item_fb',
        'slug' => 'fb-slug',
        'original_name' => 'fb.txt',
        'type' => 'file',
        'file_path' => 'fb-slug.txt',
        'mime_type' => 'text/plain',
        'size' => 20,
        'x_token' => 'tok_fb',
        'is_oneshot' => 0,
        'created_at' => time(),
        'views' => 0,
        'user_id' => 'user_b',
    ]);
    file_put_contents($multiUserEnv->filesDir.'/fb-slug.txt', 'hello B');

    // 1. Member A calls /api/items -> only sees items owned by user_a
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/items';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokenA;
    $_POST = [];

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outListA = ob_get_clean();

    $dataA = json_decode($outListA, true);
    expect($dataA['items'])->toHaveCount(2);
    $slugsA = array_column($dataA['items'], 'slug');
    expect($slugsA)->toContain($slugA);
    expect($slugsA)->toContain('fa-slug');
    expect($slugsA)->not->toContain($slugB);
    expect($slugsA)->not->toContain('fb-slug');

    // 2. Member B calls /api/items -> only sees items owned by user_b
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokenB;
    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outListB = ob_get_clean();

    $dataB = json_decode($outListB, true);
    expect($dataB['items'])->toHaveCount(2);
    $slugsB = array_column($dataB['items'], 'slug');
    expect($slugsB)->toContain($slugB);
    expect($slugsB)->toContain('fb-slug');
    expect($slugsB)->not->toContain($slugA);
    expect($slugsB)->not->toContain('fa-slug');

    // 3. Member B calls /list (CLI format) -> only receives fb-slug.txt
    $_SERVER['REQUEST_URI'] = '/list';
    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outCliListB = ob_get_clean();

    expect($outCliListB)->toContain('fb-slug.txt');
    expect($outCliListB)->not->toContain('fa-slug.txt');

    // 4. Member B attempts to DELETE /api/items/fa-slug -> 403 Forbidden!
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $_SERVER['REQUEST_URI'] = '/api/items/fa-slug';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokenB;

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outDelFail = ob_get_clean();

    expect(http_response_code())->toBe(403);
    $delJson = json_decode($outDelFail, true);
    expect($delJson['error'])->toBe('Forbidden');
    expect($multiUserEnv->db()->findBySlug('fa-slug'))->not->toBeNull();

    // 5. Member B attempts rustypaste-style DELETE /fa-slug with Bearer tokenB -> 401
    $_SERVER['REQUEST_URI'] = '/fa-slug';
    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outDelRpFail = ob_get_clean();

    expect(http_response_code())->toBe(401);
    expect($multiUserEnv->db()->findBySlug('fa-slug'))->not->toBeNull();

    // 6. Member A deletes their own item via /api/items/fa-slug -> 200 OK
    $_SERVER['REQUEST_URI'] = '/api/items/fa-slug';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokenA;

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outDelSuccess = ob_get_clean();

    expect(http_response_code())->toBe(200);
    expect($multiUserEnv->db()->findBySlug('fa-slug'))->toBeNull();

    // 7. Master Admin can see and delete any item
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/items';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-secret-token';

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outAdminList = ob_get_clean();

    $adminItems = json_decode($outAdminList, true);
    expect($adminItems['items'])->toHaveCount(3); // slugA, slugB, fb-slug

    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $_SERVER['REQUEST_URI'] = '/api/items/fb-slug';

    ob_start();
    $multiUserEnv->app()->handleRequest();
    $outAdminDel = ob_get_clean();

    expect(http_response_code())->toBe(200);
    expect($multiUserEnv->db()->findBySlug('fb-slug'))->toBeNull();
});
