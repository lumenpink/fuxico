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
    $testEnv->tempDir = sys_get_temp_dir().'/fuxico_resenha_test_'.uniqid();
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

test('returns 503 if resenha option is requested but RESENHA_API_URL is missing', function () use ($testEnv) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
    $_POST = [
        'shorten' => 'https://example.com/artigo-poluido',
        'resenha' => 'true',
    ];
    $_FILES = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $output = trim((string) ob_get_clean());

    expect(http_response_code())->toBe(503);
    expect($output)->toContain('RESENHA_API_URL');
});

test('distills article via resenha and creates fuxico doc with assets', function () use ($testEnv) {
    $mockServerPort = 23149;
    $mockScript = tempnam(sys_get_temp_dir(), 'resenha_mock_');
    file_put_contents($mockScript, "<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'title' => 'Artigo Destilado na Borda',
    'source' => 'https://example.com/artigo-poluido',
    'domain' => 'example.com',
    'markdown' => \"# Artigo Destilado na Borda\\n\\nTexto limpo sem anúncios.\\n\\n![Figura 1](assets/foto.jpg)\",
    'assets' => [
        [
            'filename' => 'foto.jpg',
            'mime_type' => 'image/jpeg',
            'data_base64' => base64_encode('fake-jpg-binary-data'),
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
");

    $proc = proc_open(
        sprintf('php -S 127.0.0.1:%d %s', $mockServerPort, escapeshellarg($mockScript)),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    usleep(250000); // 250ms

    try {
        putenv("RESENHA_API_URL=http://127.0.0.1:{$mockServerPort}");
        $testEnv->config->resenhaUrl = "http://127.0.0.1:{$mockServerPort}";

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        $_POST = [
            'shorten' => 'https://example.com/artigo-poluido',
            'resenha' => 'true',
            'custom_name' => 'artigo-resenhado',
        ];
        $_FILES = [];

        ob_start();
        $testEnv->app()->handleRequest();
        $output = trim((string) ob_get_clean());

        expect(http_response_code())->toBe(200);
        expect($output)->toBe('https://fuxico.test/artigo-resenhado');

        $item = $testEnv->db()->findBySlug('artigo-resenhado');
        expect($item)->not->toBeNull();
        expect($item['type'])->toBe('doc');
        expect($item['original_name'])->toBe('Artigo Destilado na Borda');

        // Confirma existência do HTML compilado
        $htmlPath = $testEnv->storage()->getFilePath('artigo-resenhado.html');
        expect(file_exists($htmlPath))->toBeTrue();
        expect(file_get_contents($htmlPath))->toContain('Artigo Destilado na Borda');

        // Confirma extração do asset na pasta {slug}_assets
        $assetPath = $testEnv->storage()->getFilesDir().'/artigo-resenhado_assets/foto.jpg';
        expect(file_exists($assetPath))->toBeTrue();
        expect(file_get_contents($assetPath))->toBe('fake-jpg-binary-data');
    } finally {
        proc_terminate($proc);
        putenv('RESENHA_API_URL');
        if (file_exists($mockScript)) {
            unlink($mockScript);
        }
    }
});
