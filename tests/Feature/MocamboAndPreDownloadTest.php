<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\Storage;

$testEnv = new class
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

beforeEach(function () use ($testEnv) {
    $testEnv->tempDir = sys_get_temp_dir().'/fuxico_moca_test_'.uniqid();
    mkdir($testEnv->tempDir, 0755, true);

    $testEnv->dbPath = $testEnv->tempDir.'/fuxico.sqlite';
    $testEnv->filesDir = $testEnv->tempDir.'/files';
    mkdir($testEnv->filesDir, 0755, true);

    $testEnv->config = new Config;
    $testEnv->config->publicMode = true;
    $testEnv->config->baseUrl = 'https://2lp.in';
    $testEnv->config->authTokens = ['mestre-secret-token'];

    $testEnv->db = new Database($testEnv->dbPath);
    $testEnv->storage = new Storage($testEnv->tempDir, $testEnv->filesDir);
    $testEnv->app = new App($testEnv->config, $testEnv->db, $testEnv->storage);
});

afterEach(function () use ($testEnv) {
    if (is_dir($testEnv->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testEnv->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
        }
        rmdir($testEnv->tempDir);
    }
});

test('browser request to file renders pre-download page while curl receives raw bytes', function () use ($testEnv) {
    $slug = 'predownload-test';
    $filename = 'relatorio-secreto.pdf';
    $content = '%PDF-1.4 Fake PDF Content for Pre-Download Testing';
    file_put_contents($testEnv->storage()->getFilePath($slug), $content);

    $testEnv->db()->createItem([
        'slug' => $slug,
        'original_name' => $filename,
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($content),
        'x_token' => 'token-123',
        'user_id' => 'admin',
        'created_at' => time(),
        'expires_at' => null,
        'type' => 'file',
        'source' => 'native',
    ]);

    // 1. Browser request (Accept: text/html)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}";
    $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64)';
    $_GET = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $html = (string) ob_get_clean();

    expect($html)->toContain('relatorio-secreto.pdf');
    expect($html)->toContain('href="/'.$slug.'/r"');
    expect($html)->toContain('class="pre-download');

    // 2. CLI curl request (Accept: */* and User-Agent: curl/...)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}";
    $_SERVER['HTTP_ACCEPT'] = '*/*';
    $_SERVER['HTTP_USER_AGENT'] = 'curl/8.5.0';
    $_GET = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $raw = (string) ob_get_clean();

    expect($raw)->toBe($content);

    // 3. Browser with /slug/r receives raw bytes
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}/r";
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $_GET = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $rawFromSlashR = (string) ob_get_clean();

    expect($rawFromSlashR)->toBe($content);

    // 4. Browser with /slug/raw receives raw bytes
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}/raw";
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $_GET = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $rawFromSlashRaw = (string) ob_get_clean();

    expect($rawFromSlashRaw)->toBe($content);

    // 5. Browser with ?raw=1 receives raw bytes
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}?raw=1";
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $_GET = ['raw' => '1'];

    ob_start();
    $testEnv->app()->handleRequest();
    $rawFromBrowser = (string) ob_get_clean();

    expect($rawFromBrowser)->toBe($content);

    // 6. Browser with ?r=1 receives raw bytes
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}?r=1";
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $_GET = ['r' => '1'];

    ob_start();
    $testEnv->app()->handleRequest();
    $rawFromQueryR = (string) ob_get_clean();

    expect($rawFromQueryR)->toBe($content);
});

test('api mocambo creates share with source mocambo and supports deletion', function () use ($testEnv) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/api/mocambo/share';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer mestre-secret-token';
    $_SERVER['CONTENT_TYPE'] = 'application/json';

    $payload = [
        'file_name' => 'documento_moca.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'mocambo_token' => 'moca_tok_abc123',
        'mocambo_stream_url' => 'http://100.64.0.2:8080/api/internal/stream/moca_tok_abc123',
        'ttl' => '24h',
    ];

    // Mock php://input via reflection or testing helper
    $testEnv->app()->setMockRawInput(json_encode($payload));

    ob_start();
    $testEnv->app()->handleRequest();
    $responseJson = (string) ob_get_clean();

    $data = json_decode($responseJson, true);
    expect($data)->toBeArray();
    expect($data)->toHaveKey('slug');
    expect($data)->toHaveKey('url');
    expect($data['url'])->toContain('https://2lp.in/');

    $slug = $data['slug'];
    $item = $testEnv->db()->findBySlug($slug);
    expect($item)->not->toBeNull();
    expect($item['source'])->toBe('mocambo');
    expect($item['original_name'])->toBe('documento_moca.pdf');

    // Test Deletion via API
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $_SERVER['REQUEST_URI'] = "/api/mocambo/share/{$slug}";
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer mestre-secret-token';

    ob_start();
    $testEnv->app()->handleRequest();
    $delJson = (string) ob_get_clean();

    $delData = json_decode($delJson, true);
    expect($delData['success'])->toBeTrue();
    expect($testEnv->db()->findBySlug($slug))->toBeNull();
});

test('folder item renders directory listing and zip streaming endpoint', function () use ($testEnv) {
    $slug = 'pasta-projeto';
    $files = [
        ['path' => 'sub/arq1.txt', 'size' => 12, 'content' => 'Conteudo 1'],
        ['path' => 'arq2.txt', 'size' => 12, 'content' => 'Conteudo 2'],
    ];

    $testEnv->db()->createItem([
        'slug' => $slug,
        'original_name' => 'Minha Pasta Compartilhada',
        'mime_type' => 'application/x-directory',
        'size_bytes' => 24,
        'x_token' => 'tok-dir',
        'user_id' => 'admin',
        'created_at' => time(),
        'expires_at' => null,
        'type' => 'folder',
        'source' => 'native',
        'data' => json_encode(['files' => $files]),
    ]);

    // 1. Visit folder URL
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}";
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $_GET = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $html = (string) ob_get_clean();

    expect($html)->toContain('Minha Pasta Compartilhada');
    expect($html)->toContain('arq2.txt');
    expect($html)->toContain('sub/arq1.txt');
    expect($html)->toContain('href="/'.$slug.'/zip"');
});
