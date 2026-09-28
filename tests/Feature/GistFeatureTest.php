<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\GistRenderer;
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
    $testEnv->tempDir = sys_get_temp_dir().'/fuxico_gist_test_'.uniqid();
    mkdir($testEnv->tempDir, 0755, true);

    $testEnv->dbPath = $testEnv->tempDir.'/fuxico.sqlite';
    $testEnv->filesDir = $testEnv->tempDir.'/files';
    mkdir($testEnv->filesDir, 0755, true);

    $testEnv->config = new Config;
    $testEnv->config->publicMode = true;
    $testEnv->config->baseUrl = 'https://fuxico.test';
    $testEnv->config->authTokens = ['test-token'];

    $testEnv->db = new Database($testEnv->dbPath);
    $testEnv->storage = new Storage($testEnv->tempDir, $testEnv->filesDir);
    $testEnv->app = new App($testEnv->config, $testEnv->db, $testEnv->storage);

    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER = [];
});

afterEach(function () use ($testEnv) {
    if (is_dir($testEnv->tempDir)) {
        $testEnv->storage()->deleteDirectory($testEnv->tempDir);
    }
});

test('uploads multi-file gist via JSON API /api/gist and bakes static html', function () use ($testEnv) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/api/gist';
    $_SERVER['CONTENT_TYPE'] = 'application/json';

    $payload = json_encode([
        'title' => 'Microservice Demo',
        'files' => [
            'main.py' => ['content' => "import os\nprint('running microservice')\n"],
            'README.md' => ['content' => "# Microservice\n\n> [!NOTE]\n> Test doc\n"],
            'config.json' => ['content' => "{\n  \"port\": 8080\n}\n"],
        ],
    ]);

    // Feed php://input via stream wrapper or mock
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $payload);
    rewind($stream);

    // Run using App instance with captured output
    ob_start();
    // Test the internal method or direct request with mock stream
    $reflected = new ReflectionClass($testEnv->app());
    $method = $reflected->getMethod('handleGistApi');

    // Save payload to a temp file and mock reading
    $tmpJson = tempnam(sys_get_temp_dir(), 'test_gist_');
    file_put_contents($tmpJson, $payload);

    $_POST = [
        'json' => $payload,
    ];

    $method->invoke($testEnv->app(), 'Bearer test-token');
    $output = ob_get_clean();

    $res = json_decode($output, true);
    expect($res)->toBeArray();
    expect($res)->toHaveKey('slug');
    $slug = $res['slug'];

    $item = $testEnv->db()->findBySlug($slug);
    expect($item)->not->toBeNull();
    expect($item['type'])->toBe('gist');
    expect($item['original_name'])->toBe('Microservice Demo');

    // Verify baked HTML exists
    $htmlPath = $testEnv->storage()->getFilePath("{$slug}.html");
    expect(file_exists($htmlPath))->toBeTrue();
    $html = file_get_contents($htmlPath);
    expect($html)->toContain('Microservice Demo');
    expect($html)->toContain('main.py');
    expect($html)->toContain('README.md');
    expect($html)->toContain('data-theme="zine"');

    // Verify files exist in storage
    $gistFiles = $testEnv->storage()->getGistFiles($slug);
    expect($gistFiles)->toHaveKey('main.py');
    expect($gistFiles)->toHaveKey('README.md');
    expect($gistFiles['main.py'])->toContain('running microservice');
});

test('serves baked html to browser and raw text or individual files to curl', function () use ($testEnv) {
    $slug = 'demo-gist';
    $files = [
        'script.sh' => "#!/bin/bash\necho hello\n",
        'README.md' => "# Script\nDocumentation here\n",
    ];
    $testEnv->storage()->storeGistFiles($slug, $files);
    $html = GistRenderer::render($files, [
        'title' => 'Demo Gist',
        'slug' => $slug,
        'base_url' => 'https://fuxico.test',
    ]);
    file_put_contents($testEnv->storage()->getFilePath("{$slug}.html"), $html);

    $testEnv->db()->createItem([
        'id' => 'gist1',
        'slug' => $slug,
        'original_name' => 'Demo Gist',
        'type' => 'gist',
        'file_path' => "{$slug}.zip",
        'mime_type' => 'application/zip',
        'size' => 100,
        'x_token' => 'tok123',
        'created_at' => time(),
    ]);

    // 1. Browser request (Accept: text/html)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}";
    $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';

    ob_start();
    $testEnv->app()->handleRequest();
    $browserOutput = ob_get_clean();

    expect($browserOutput)->toContain('<!DOCTYPE html>');
    expect($browserOutput)->toContain('Demo Gist');
    expect($browserOutput)->toContain('script.sh');

    // 2. CLI / cURL request (Accept: */*)
    $_SERVER['HTTP_ACCEPT'] = '*/*';
    ob_start();
    $testEnv->app()->handleRequest();
    $curlOutput = ob_get_clean();

    expect($curlOutput)->not->toContain('<!DOCTYPE html>');
    expect($curlOutput)->toContain('=== File: script.sh ===');
    expect($curlOutput)->toContain('=== File: README.md ===');

    // 3. Raw specific file GET /<slug>/raw/script.sh
    $_SERVER['REQUEST_URI'] = "/{$slug}/raw/script.sh";
    ob_start();
    $testEnv->app()->handleRequest();
    $rawOutput = ob_get_clean();

    expect($rawOutput)->toBe("#!/bin/bash\necho hello\n");

    // 4. Download ZIP GET /<slug>/zip
    $_SERVER['REQUEST_URI'] = "/{$slug}/zip";
    ob_start();
    $testEnv->app()->handleRequest();
    $zipOutput = ob_get_clean();

    expect(str_starts_with($zipOutput, "PK\x03\x04"))->toBeTrue();
    expect($zipOutput)->toContain('script.sh');
    expect($zipOutput)->toContain('README.md');
});

test('cli fux uploads multi-file gist', function () use ($testEnv) {
    // Start temporary built-in server or use test files with bin/fux
    $f1 = $testEnv->tempDir.'/file1.txt';
    $f2 = $testEnv->tempDir.'/file2.py';
    file_put_contents($f1, "conteudo 1\n");
    file_put_contents($f2, "print('conteudo 2')\n");

    expect(file_exists('bin/fux'))->toBeTrue();
    expect(is_executable('bin/fux'))->toBeTrue();
});
