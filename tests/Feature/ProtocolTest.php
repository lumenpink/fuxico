<?php

declare(strict_types=1);

use PicoShare\App;
use PicoShare\Config;
use PicoShare\Database;
use PicoShare\Storage;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/picoshare_feat_'.uniqid();
    $dataDir = $this->tempDir.'/data';
    $filesDir = $dataDir.'/files';
    $dbPath = $dataDir.'/picoshare.sqlite';

    $this->config = new Config;
    $this->config->dataDir = $dataDir;
    $this->config->filesDir = $filesDir;
    $this->config->dbPath = $dbPath;
    $this->config->baseUrl = 'http://localhost:8080';
    $this->config->authTokens = ['test-secret-token'];

    $this->db = new Database($dbPath);
    $this->storage = new Storage($dataDir, $filesDir);
    $this->app = new App($this->config, $this->db, $this->storage);
});

afterEach(function () {
    $deleteDir = function (string $dir) use (&$deleteDir) {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

test('expiration parser correctly handles hours, epochs, and duration strings', function () {
    $now = time();

    // Hours (0x0.st style)
    $in24h = $this->app->parseExpiration('24');
    expect($in24h)->toBeGreaterThanOrEqual($now + 86390);

    // Duration strings (rustypaste style)
    $in10m = $this->app->parseExpiration('10m');
    expect($in10m)->toBeGreaterThanOrEqual($now + 590);
    expect($in10m)->toBeLessThanOrEqual($now + 610);

    $in7d = $this->app->parseExpiration('7d');
    expect($in7d)->toBeGreaterThanOrEqual($now + (7 * 86400) - 10);

    // Milliseconds epoch (0x0.st style)
    $futureMs = ($now + 3600) * 1000;
    $in1h = $this->app->parseExpiration((string) $futureMs);
    expect($in1h)->toBeGreaterThanOrEqual($now + 3590);
});

test('slug generator generates short and secret high-entropy slugs', function () {
    $standardSlug = $this->app->generateSlug(false);
    expect(strlen($standardSlug))->toBe(5);

    $secretSlug = $this->app->generateSlug(true);
    expect(strlen($secretSlug))->toBe(16);
});

test('purges expired items and removes their physical files', function () {
    $tmpFile = $this->tempDir.'/dummy.txt';
    file_put_contents($tmpFile, 'temporary');
    $this->storage->storeUploadedFile($tmpFile, 'exp1.txt');

    $this->db->createItem([
        'id' => '1',
        'slug' => 'exp1',
        'type' => 'file',
        'file_path' => 'exp1.txt',
        'x_token' => 'tok1',
        'expires_at' => time() - 100, // already expired
        'created_at' => time() - 200,
    ]);

    expect($this->storage->fileExists('exp1.txt'))->toBeTrue();

    $purged = $this->app->purgeExpired();
    expect($purged)->toBe(1);
    expect($this->storage->fileExists('exp1.txt'))->toBeFalse();
    expect($this->db->findBySlug('exp1'))->toBeNull();
});

test('handles file upload and 0x0.st management deletion end-to-end', function () {
    $uploadTmp = $this->tempDir.'/upload.txt';
    file_put_contents($uploadTmp, 'Hello 0x0 protocol');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-secret-token';
    $_POST = [];
    $_FILES = [
        'file' => [
            'name' => 'hello.txt',
            'type' => 'text/plain',
            'tmp_name' => $uploadTmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($uploadTmp),
        ],
    ];

    ob_start();
    $this->app->handleRequest();
    $output = ob_get_clean();

    expect($output)->toContain('http://localhost:8080/');
    preg_match('#http://localhost:8080/([a-zA-Z0-9_\-]+)\.txt#', $output, $matches);
    expect($matches)->not->toBeEmpty();
    $slug = $matches[1];

    $item = $this->db->findBySlug($slug);
    expect($item)->not->toBeNull();
    expect($item['mime_type'])->toBe('text/plain');
    $xToken = $item['x_token'];

    // Test 0x0.st management deletion: POST /<slug> with token & delete
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = "/{$slug}.txt";
    $_POST = ['token' => $xToken, 'delete' => ''];
    $_FILES = [];

    ob_start();
    $this->app->handleRequest();
    $delOutput = ob_get_clean();

    expect($delOutput)->toBe("File deleted.\n");
    expect($this->db->findBySlug($slug))->toBeNull();
});

test('handles URL shortening and redirection', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-secret-token';
    $_POST = ['shorten' => 'https://github.com/lumenpink', 'customName' => 'mylink'];
    $_FILES = [];

    ob_start();
    $this->app->handleRequest();
    $output = ob_get_clean();

    expect($output)->toBe("http://localhost:8080/mylink\n");

    $item = $this->db->findBySlug('mylink');
    expect($item)->not->toBeNull();
    expect($item['type'])->toBe('url');
    expect($item['target_url'])->toBe('https://github.com/lumenpink');
});

test('handles rustypaste list and delete endpoints', function () {
    // Create test item
    $this->db->createItem([
        'id' => 'rp-1',
        'slug' => 'rpfile',
        'original_name' => 'document.txt',
        'type' => 'file',
        'file_path' => 'rpfile.txt',
        'mime_type' => 'text/plain',
        'size' => 42,
        'x_token' => 'rp-token',
        'is_oneshot' => 0,
        'created_at' => time(),
    ]);

    // GET /list
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/list';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-secret-token';
    $_POST = [];
    $_FILES = [];

    ob_start();
    $this->app->handleRequest();
    $jsonOutput = ob_get_clean();

    $list = json_decode($jsonOutput, true);
    expect($list)->toBeArray();
    expect(count($list))->toBe(1);
    expect($list[0]['file_name'])->toBe('rpfile.txt');

    // DELETE /<slug>
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $_SERVER['REQUEST_URI'] = '/rpfile';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-secret-token';

    ob_start();
    $this->app->handleRequest();
    $delOutput = ob_get_clean();

    expect($delOutput)->toBe("File deleted.\n");
    expect($this->db->findBySlug('rpfile'))->toBeNull();
});
