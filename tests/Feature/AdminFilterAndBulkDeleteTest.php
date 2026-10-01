<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\Storage;
use Fuxico\Views;

$filterEnv = new class
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

beforeEach(function () use ($filterEnv) {
    $filterEnv->tempDir = sys_get_temp_dir().'/fuxico_filter_test_'.uniqid();
    mkdir($filterEnv->tempDir, 0755, true);

    $filterEnv->dbPath = $filterEnv->tempDir.'/fuxico.sqlite';
    $filterEnv->filesDir = $filterEnv->tempDir.'/files';
    mkdir($filterEnv->filesDir, 0755, true);

    $filterEnv->config = new Config;
    $filterEnv->config->publicMode = false;
    $filterEnv->config->baseUrl = 'https://fuxico.test';
    $filterEnv->config->authTokens = ['master-secret-token'];

    $filterEnv->db = new Database($filterEnv->dbPath);
    $filterEnv->storage = new Storage($filterEnv->tempDir, $filterEnv->filesDir);
    $filterEnv->app = new App($filterEnv->config, $filterEnv->db, $filterEnv->storage);

    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER = [];
});

afterEach(function () use ($filterEnv) {
    if (is_dir($filterEnv->tempDir)) {
        $filterEnv->storage()->deleteDirectory($filterEnv->tempDir);
    }
});

test('GET /api/items filters by type, user and date range', function () use ($filterEnv) {
    // 1. Create two users
    $user1Id = 'u_user1';
    $user2Id = 'u_user2';
    $filterEnv->db()->createUser([
        'id' => $user1Id,
        'username' => 'silvia_rivera',
        'token' => 'tok_silvia',
        'role' => 'member',
        'created_at' => strtotime('2026-01-01 12:00:00'),
    ]);
    $filterEnv->db()->createUser([
        'id' => $user2Id,
        'username' => 'marsha_johnson',
        'token' => 'tok_marsha',
        'role' => 'member',
        'created_at' => strtotime('2026-01-01 12:00:00'),
    ]);

    // 2. Insert items across different dates, types and users
    // Item A: file by user 1 on 2026-02-10
    $filterEnv->db()->createItem([
        'slug' => 'slug-a',
        'original_name' => 'manifesto.txt',
        'type' => 'file',
        'target_url' => null,
        'file_path' => 'manifesto.txt',
        'mime_type' => 'text/plain',
        'size' => 100,
        'is_oneshot' => 0,
        'x_token' => 'xtok-a',
        'expires_at' => null,
        'created_at' => strtotime('2026-02-10 10:00:00'),
        'user_id' => $user1Id,
    ]);

    // Item B: url by user 2 on 2026-03-15
    $filterEnv->db()->createItem([
        'slug' => 'slug-b',
        'original_name' => 'https://trans.org',
        'type' => 'url',
        'target_url' => 'https://trans.org',
        'file_path' => null,
        'mime_type' => null,
        'size' => 0,
        'is_oneshot' => 0,
        'x_token' => 'xtok-b',
        'expires_at' => null,
        'created_at' => strtotime('2026-03-15 15:00:00'),
        'user_id' => $user2Id,
    ]);

    // Item C: doc without user (anonymous) on 2026-04-20
    $filterEnv->db()->createItem([
        'slug' => 'slug-c',
        'original_name' => 'artigo.md',
        'type' => 'doc',
        'target_url' => null,
        'file_path' => 'artigo.md',
        'mime_type' => 'text/markdown',
        'size' => 250,
        'is_oneshot' => 0,
        'x_token' => 'xtok-c',
        'expires_at' => null,
        'created_at' => strtotime('2026-04-20 18:00:00'),
        'user_id' => null,
    ]);

    // --- Query 1: Admin lists all items (total 3) ---
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/items';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-secret-token';
    $_GET = [];

    ob_start();
    $filterEnv->app()->handleRequest();
    $resAll = json_decode(ob_get_clean(), true);

    expect($resAll['total'])->toBe(3);
    expect($resAll['items'])->toHaveCount(3);

    // --- Query 2: Filter by type=file (should return only slug-a) ---
    $_GET = ['type' => 'file'];
    ob_start();
    $filterEnv->app()->handleRequest();
    $resFile = json_decode(ob_get_clean(), true);

    expect($resFile['total'])->toBe(1);
    expect($resFile['items'][0]['slug'])->toBe('slug-a');

    // --- Query 3: Filter by user_id=u_user2 (should return only slug-b) ---
    $_GET = ['user_id' => $user2Id];
    ob_start();
    $filterEnv->app()->handleRequest();
    $resUser2 = json_decode(ob_get_clean(), true);

    expect($resUser2['total'])->toBe(1);
    expect($resUser2['items'][0]['slug'])->toBe('slug-b');

    // --- Query 4: Filter by anonymous user (user_id=none, should return slug-c) ---
    $_GET = ['user_id' => 'none'];
    ob_start();
    $filterEnv->app()->handleRequest();
    $resAnon = json_decode(ob_get_clean(), true);

    expect($resAnon['total'])->toBe(1);
    expect($resAnon['items'][0]['slug'])->toBe('slug-c');

    // --- Query 5: Filter by date range (from 2026-03-01 to 2026-03-31, should return slug-b) ---
    $_GET = ['date_from' => '2026-03-01', 'date_to' => '2026-03-31'];
    ob_start();
    $filterEnv->app()->handleRequest();
    $resDateRange = json_decode(ob_get_clean(), true);

    expect($resDateRange['total'])->toBe(1);
    expect($resDateRange['items'][0]['slug'])->toBe('slug-b');

    // --- Query 6: Filter by date_from only (from 2026-03-01 onwards, should return slug-b and slug-c) ---
    $_GET = ['date_from' => '2026-03-01'];
    ob_start();
    $filterEnv->app()->handleRequest();
    $resFrom = json_decode(ob_get_clean(), true);

    expect($resFrom['total'])->toBe(2);

    // --- Query 7: Filter by date_to only (up to 2026-02-28, should return slug-a) ---
    $_GET = ['date_to' => '2026-02-28'];
    ob_start();
    $filterEnv->app()->handleRequest();
    $resTo = json_decode(ob_get_clean(), true);

    expect($resTo['total'])->toBe(1);
    expect($resTo['items'][0]['slug'])->toBe('slug-a');
});

test('renderDashboardUI renders user and date filter controls', function () use ($filterEnv) {
    $html = Views::renderDashboardUI($filterEnv->config);

    expect($html)->toContain('id="dashUser"');
    expect($html)->toContain('id="dashDateFrom"');
    expect($html)->toContain('id="dashDateTo"');
    expect($html)->toContain('clearDashboardFilters()');
});
