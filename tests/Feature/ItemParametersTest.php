<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\I18n;
use Fuxico\Storage;
use Fuxico\Views;

$paramEnv = new class
{
    public string $tempDir = '';

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

beforeEach(function () use ($paramEnv) {
    $paramEnv->tempDir = sys_get_temp_dir().'/fuxico_param_'.uniqid();
    $dataDir = $paramEnv->tempDir.'/data';
    $filesDir = $dataDir.'/files';
    $dbPath = $dataDir.'/fuxico.sqlite';

    $paramEnv->config = new Config;
    $paramEnv->config->dataDir = $dataDir;
    $paramEnv->config->filesDir = $filesDir;
    $paramEnv->config->dbPath = $dbPath;
    $paramEnv->config->baseUrl = 'http://localhost:8080';
    $paramEnv->config->authTokens = ['master-token'];

    $paramEnv->db = new Database($dbPath);
    $paramEnv->storage = new Storage($dataDir, $filesDir);
    $i18n = new I18n;
    $views = new Views($i18n, $paramEnv->config);

    $paramEnv->app = new App($paramEnv->config, $paramEnv->db, $paramEnv->storage, $i18n, $views);

    // Register a test member user (Valéria)
    $paramEnv->db->createUser([
        'id' => 'user-valeria',
        'username' => 'valeria',
        'token' => 'fux-valeria-secret',
        'role' => 'member',
        'created_at' => time(),
    ]);

    // Register a second test member user (Claudia)
    $paramEnv->db->createUser([
        'id' => 'user-claudia',
        'username' => 'claudia',
        'token' => 'fux-claudia-secret',
        'role' => 'member',
        'created_at' => time(),
    ]);
});

afterEach(function () use ($paramEnv) {
    if (is_dir($paramEnv->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($paramEnv->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($paramEnv->tempDir);
    }
});

test('PATCH /api/items/{slug} edits expiration, download limits, names and calculates hash', function () use ($paramEnv) {
    // 1. Create a test file item uploaded by Valeria
    $content = 'Conteúdo ultrassecreto para teste de parâmetros.';
    $filePath = 'secret_note.txt';
    file_put_contents($paramEnv->storage()->getFilePath($filePath), $content);

    $paramEnv->db()->createItem([
        'id' => 'item-param-1',
        'slug' => 'fux-param-1',
        'original_name' => 'original.txt',
        'type' => 'file',
        'file_path' => $filePath,
        'mime_type' => 'text/plain',
        'size' => strlen($content),
        'x_token' => 'xtok-param-1',
        'is_oneshot' => 0,
        'expires_at' => time() + 3600,
        'created_at' => time(),
        'views' => 0,
        'user_id' => 'user-valeria',
    ]);

    // 2. Valeria edits: expires to never, max_downloads to 10, new original name, and recalculate hash
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $_SERVER['REQUEST_URI'] = '/api/items/fux-param-1';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fux-valeria-secret';
    $_POST = [
        'expires' => 'never',
        'max_downloads' => 10,
        'original_name' => 'novo_nome_personalizado.txt',
        'recalculate_hash' => true,
    ];

    ob_start();
    $paramEnv->app()->handleRequest();
    $output = ob_get_clean();

    expect(http_response_code())->toBe(200);
    $res = json_decode($output, true);
    expect($res['success'])->toBeTrue();

    // Verify in database
    $updated = $paramEnv->db()->findBySlug('fux-param-1');
    expect($updated)->not->toBeNull();
    expect($updated['expires_at'])->toBeNull();
    expect((int) $updated['max_downloads'])->toBe(10);
    expect($updated['original_name'])->toBe('novo_nome_personalizado.txt');
    expect($updated['hash'])->toBe(hash('sha256', $content));

    // 3. Edit duration to 24h
    $_POST = [
        'expires' => '24h',
    ];
    ob_start();
    $paramEnv->app()->handleRequest();
    ob_get_clean();

    expect(http_response_code())->toBe(200);
    $updated2 = $paramEnv->db()->findBySlug('fux-param-1');
    expect($updated2['expires_at'])->toBeGreaterThan(time() + 86000);
});

test('Download quota (max_downloads) automatically burns/purges the file when reached', function () use ($paramEnv) {
    // 1. Create a file with max_downloads = 2
    $content = 'Arquivo que vai queimar após dois downloads.';
    $filePath = 'burn_after_2.txt';
    file_put_contents($paramEnv->storage()->getFilePath($filePath), $content);

    $paramEnv->db()->createItem([
        'id' => 'item-burn-2',
        'slug' => 'slug-burn-2',
        'original_name' => 'burn.txt',
        'type' => 'file',
        'file_path' => $filePath,
        'mime_type' => 'text/plain',
        'size' => strlen($content),
        'x_token' => 'xtok-burn-2',
        'is_oneshot' => 0,
        'max_downloads' => 2,
        'expires_at' => null,
        'created_at' => time(),
        'views' => 0,
        'user_id' => 'user-valeria',
    ]);

    // 2. Download 1 -> Should succeed and views count becomes 1
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/slug-burn-2';
    $_SERVER['HTTP_AUTHORIZATION'] = '';

    ob_start();
    $paramEnv->app()->handleRequest();
    $out1 = ob_get_clean();

    expect(http_response_code())->toBe(200);
    expect($out1)->toBe($content);
    $itemAfter1 = $paramEnv->db()->findBySlug('slug-burn-2');
    expect($itemAfter1)->not->toBeNull();
    expect((int) $itemAfter1['views'])->toBe(1);

    // 3. Download 2 -> Reaches quota (views = 2 == max_downloads), returns content and evaporates!
    ob_start();
    $paramEnv->app()->handleRequest();
    $out2 = ob_get_clean();

    expect(http_response_code())->toBe(200);
    expect($out2)->toBe($content);

    // Should now be purged from DB and storage
    expect($paramEnv->db()->findBySlug('slug-burn-2'))->toBeNull();
    expect(file_exists($paramEnv->storage()->getFilePath($filePath)))->toBeFalse();

    // 4. Download 3 -> 404 Not Found
    ob_start();
    $paramEnv->app()->handleRequest();
    ob_get_clean();
    expect(http_response_code())->toBe(404);
});

test('RBAC ownership: member cannot edit another members item parameters, but admin can', function () use ($paramEnv) {
    // 1. Create item owned by Valeria
    $content = 'Conteúdo de Valéria';
    $filePath = 'valeria.txt';
    file_put_contents($paramEnv->storage()->getFilePath($filePath), $content);

    $paramEnv->db()->createItem([
        'id' => 'item-valeria',
        'slug' => 'fux-valeria-only',
        'original_name' => 'valeria.txt',
        'type' => 'file',
        'file_path' => $filePath,
        'mime_type' => 'text/plain',
        'size' => strlen($content),
        'x_token' => 'xtok-valeria',
        'is_oneshot' => 0,
        'expires_at' => null,
        'created_at' => time(),
        'views' => 0,
        'user_id' => 'user-valeria',
    ]);

    // 2. Claudia attempts to edit Valeria's item -> 403 Forbidden
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $_SERVER['REQUEST_URI'] = '/api/items/fux-valeria-only';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fux-claudia-secret';
    $_POST = [
        'original_name' => 'tentativa_de_invasao.txt',
    ];

    ob_start();
    $paramEnv->app()->handleRequest();
    ob_get_clean();

    expect(http_response_code())->toBe(403);
    $item = $paramEnv->db()->findBySlug('fux-valeria-only');
    expect($item['original_name'])->toBe('valeria.txt');

    // 3. Admin (master) edits Valeria's item -> 200 OK
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-token';
    $_POST = [
        'original_name' => 'admin_ajustou.txt',
        'max_downloads' => 100,
    ];

    ob_start();
    $paramEnv->app()->handleRequest();
    ob_get_clean();

    expect(http_response_code())->toBe(200);
    $itemUpdated = $paramEnv->db()->findBySlug('fux-valeria-only');
    expect($itemUpdated['original_name'])->toBe('admin_ajustou.txt');
    expect((int) $itemUpdated['max_downloads'])->toBe(100);
});
