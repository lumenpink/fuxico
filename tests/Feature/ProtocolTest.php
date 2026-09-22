<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\I18n;
use Fuxico\Storage;
use Fuxico\Views;

$testEnv = new class
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

beforeEach(function () use ($testEnv) {
    $testEnv->tempDir = sys_get_temp_dir().'/fuxico_feat_'.uniqid();
    $dataDir = $testEnv->tempDir.'/data';
    $filesDir = $dataDir.'/files';
    $dbPath = $dataDir.'/fuxico.sqlite';

    $testEnv->config = new Config;
    $testEnv->config->dataDir = $dataDir;
    $testEnv->config->filesDir = $filesDir;
    $testEnv->config->dbPath = $dbPath;
    $testEnv->config->baseUrl = 'http://localhost:8080';
    $testEnv->config->authTokens = ['test-secret-token'];

    $testEnv->db = new Database($dbPath);
    $testEnv->storage = new Storage($dataDir, $filesDir);
    $testEnv->app = new App($testEnv->config, $testEnv->db, $testEnv->storage);
});

afterEach(function () use ($testEnv) {
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

    $deleteDir($testEnv->tempDir);
});

test('expiration parser correctly handles hours, epochs, and duration strings', function () use ($testEnv) {
    $now = time();

    // Hours (0x0.st style)
    $in24h = $testEnv->app()->parseExpiration('24');
    expect($in24h)->toBeGreaterThanOrEqual($now + 86390);

    // Duration strings (rustypaste style)
    $in10m = $testEnv->app()->parseExpiration('10m');
    expect($in10m)->toBeGreaterThanOrEqual($now + 590);
    expect($in10m)->toBeLessThanOrEqual($now + 610);

    $in7d = $testEnv->app()->parseExpiration('7d');
    expect($in7d)->toBeGreaterThanOrEqual($now + (7 * 86400) - 10);

    // Milliseconds epoch (0x0.st style)
    $futureMs = ($now + 3600) * 1000;
    $in1h = $testEnv->app()->parseExpiration((string) $futureMs);
    expect($in1h)->toBeGreaterThanOrEqual($now + 3590);
});

test('slug generator generates short and secret high-entropy slugs', function () use ($testEnv) {
    $standardSlug = $testEnv->app()->generateSlug(false);
    expect(strlen($standardSlug))->toBe(5);

    $secretSlug = $testEnv->app()->generateSlug(true);
    expect(strlen($secretSlug))->toBe(16);
});

test('purges expired items and removes their physical files', function () use ($testEnv) {
    $tmpFile = $testEnv->tempDir.'/dummy.txt';
    file_put_contents($tmpFile, 'temporary');
    $testEnv->storage()->storeUploadedFile($tmpFile, 'exp1.txt');

    $testEnv->db()->createItem([
        'id' => '1',
        'slug' => 'exp1',
        'type' => 'file',
        'file_path' => 'exp1.txt',
        'x_token' => 'tok1',
        'expires_at' => time() - 100, // already expired
        'created_at' => time() - 200,
    ]);

    expect($testEnv->storage()->fileExists('exp1.txt'))->toBeTrue();

    $purged = $testEnv->app()->purgeExpired();
    expect($purged)->toBe(1);
    expect($testEnv->storage()->fileExists('exp1.txt'))->toBeFalse();
    expect($testEnv->db()->findBySlug('exp1'))->toBeNull();
});

test('handles file upload and 0x0.st management deletion end-to-end', function () use ($testEnv) {
    $uploadTmp = $testEnv->tempDir.'/upload.txt';
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
    $testEnv->app()->handleRequest();
    $output = ob_get_clean();

    expect($output)->toContain('http://localhost:8080/');
    preg_match('#http://localhost:8080/([a-zA-Z0-9_\-]+)\.txt#', $output, $matches);
    expect($matches)->not->toBeEmpty();
    $slug = $matches[1];

    $item = $testEnv->db()->findBySlug($slug);
    expect($item)->not->toBeNull();
    expect($item['mime_type'])->toBe('text/plain');
    $xToken = $item['x_token'];

    // Test 0x0.st management deletion: POST /<slug> with token & delete
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = "/{$slug}.txt";
    $_POST = ['token' => $xToken, 'delete' => ''];
    $_FILES = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $delOutput = ob_get_clean();

    expect($delOutput)->toBe("File deleted.\n");
    expect($testEnv->db()->findBySlug($slug))->toBeNull();
});

test('handles URL shortening and redirection', function () use ($testEnv) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-secret-token';
    $_POST = ['shorten' => 'https://github.com/lumenpink', 'customName' => 'mylink'];
    $_FILES = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $output = ob_get_clean();

    expect($output)->toBe("http://localhost:8080/mylink\n");

    $item = $testEnv->db()->findBySlug('mylink');
    expect($item)->not->toBeNull();
    expect($item['type'])->toBe('url');
    expect($item['target_url'])->toBe('https://github.com/lumenpink');
});

test('handles rustypaste list and delete endpoints', function () use ($testEnv) {
    // Create test item
    $testEnv->db()->createItem([
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
    $testEnv->app()->handleRequest();
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
    $testEnv->app()->handleRequest();
    $delOutput = ob_get_clean();

    expect($delOutput)->toBe("File deleted.\n");
    expect($testEnv->db()->findBySlug('rpfile'))->toBeNull();
});

test('dashTab is hidden on private instances and protected by auth', function () use ($testEnv) {
    // 1. Private mode Web UI rendering
    $testEnv->config->publicMode = false;
    $html = Views::renderWebUI($testEnv->config);
    expect($html)->toContain('id="dashTabBtn" onclick="switchTab(\'dashTab\', this)" style="display:none;"');
    expect($html)->toContain('class="trans-stripe"');
    expect($html)->toContain('class="trans-stripe bottom"');
    expect($html)->toContain('box-shadow: 8px 12px 0px #0a080d, 8px 12px 0px 2px var(--electric-pink);');
    expect($html)->toContain('https://en.wikipedia.org/wiki/Travesti_(gender_identity)');
    expect($html)->toContain('>travesti</a> :)');

    $i18nPt = new I18n('pt');
    $htmlPt = Views::renderWebUI($testEnv->config, $i18nPt);
    expect($htmlPt)->toContain('Orgulhosamente produzido por uma');
    expect($htmlPt)->toContain('https://pt.wikipedia.org/wiki/Travesti_(identidade_de_g%C3%AAnero)');
    expect($htmlPt)->toContain('>travesti</a> :)');

    // 2. Private mode API /api/stats without token returns 401
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/stats';
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_POST = [];
    $_FILES = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $resPrivate = ob_get_clean();
    $dataPrivate = json_decode((string) $resPrivate, true);
    expect($dataPrivate)->toHaveKey('error');
    expect($dataPrivate['error'])->toBe('Unauthorized');

    // 3. Public mode Web UI rendering reveals tab
    $testEnv->config->publicMode = true;
    $htmlPublic = Views::renderWebUI($testEnv->config);
    expect($htmlPublic)->toContain('id="dashTabBtn" onclick="switchTab(\'dashTab\', this)" >');

    // 4. Public mode API /api/stats is readable without token
    ob_start();
    $testEnv->app()->handleRequest();
    $resPublic = ob_get_clean();
    $dataPublic = json_decode((string) $resPublic, true);
    expect($dataPublic)->toHaveKey('total_files');
    expect($dataPublic)->toHaveKey('total_urls');

    // Reset back to private
    $testEnv->config->publicMode = false;
});
