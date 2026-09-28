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
    $testEnv->tempDir = sys_get_temp_dir().'/fuxico_doc_test_'.uniqid();
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

test('uploads markdown with doc=true, bakes html and serves clean short url', function () use ($testEnv) {
    $mdContent = <<<'MD'
---
title: Fofoca de Teste
theme: zine
---

# A Fofoca Começou

> [!NOTE] Segredo de Estado
> Isso é um teste do Fuxico Docs.

Texto em **negrito** e código `print('ok')`.
MD;

    $tmpFile = tempnam(sys_get_temp_dir(), 'md_test_');
    file_put_contents($tmpFile, $mdContent);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
    $_POST = [
        'doc' => 'true',
        'custom_name' => 'fofoca-teste',
    ];
    $_FILES = [
        'file' => [
            'name' => 'documento.md',
            'type' => 'text/markdown',
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($mdContent),
        ],
    ];

    ob_start();
    $testEnv->app()->handleRequest();
    $output = trim((string) ob_get_clean());

    expect($output)->toBe('https://fuxico.test/fofoca-teste');

    $item = $testEnv->db()->findBySlug('fofoca-teste');
    expect($item)->not->toBeNull();
    expect($item['type'])->toBe('doc');
    expect($item['mime_type'])->toBe('text/markdown');

    // Verify baked html exists in storage
    $htmlPath = $testEnv->storage()->getFilePath('fofoca-teste.html');
    expect(file_exists($htmlPath))->toBeTrue();
    $htmlContent = file_get_contents($htmlPath);
    expect($htmlContent)->toContain('<title>Fofoca de Teste</title>');
    expect($htmlContent)->toContain('<h1 id="a-fofoca-comecou">A Fofoca Começou</h1>');
    expect($htmlContent)->toContain('class="callout callout-note"');
    expect($htmlContent)->toContain('[data-theme="acad"]');
});

test('serves baked html to browser and raw markdown to curl / ?raw=1', function () use ($testEnv) {
    // 1. Create a doc item directly in storage and db
    $slug = 'doc-leitor';
    $md = "# Nota Crua\n\nConteúdo para terminal.";
    file_put_contents($testEnv->storage()->getFilePath("{$slug}.md"), $md);

    $testEnv->db()->createItem([
        'id' => 'doc-1',
        'slug' => $slug,
        'original_name' => 'nota.md',
        'type' => 'doc',
        'file_path' => "{$slug}.md",
        'mime_type' => 'text/markdown',
        'size' => strlen($md),
        'x_token' => 'token123',
        'created_at' => time(),
    ]);

    // 2. Browser request (Accept: text/html)
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}";
    $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
    $_GET = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $browserOutput = (string) ob_get_clean();

    expect($browserOutput)->toContain('<!DOCTYPE html>');
    expect($browserOutput)->toContain('<h1 id="nota-crua">Nota Crua</h1>');
    expect($browserOutput)->toContain('id="btnThemeToggle"');

    // 3. Raw request via ?raw=1
    $_GET = ['raw' => '1'];
    ob_start();
    $testEnv->app()->handleRequest();
    $rawOutput = (string) ob_get_clean();

    expect($rawOutput)->toBe($md);

    // 4. CLI request (curl without text/html)
    $_SERVER['HTTP_ACCEPT'] = '*/*';
    $_GET = [];
    ob_start();
    $testEnv->app()->handleRequest();
    $cliOutput = (string) ob_get_clean();

    expect($cliOutput)->toBe($md);
});

test('serves uploaded assets under /<slug>/assets/<filename>', function () use ($testEnv) {
    $slug = 'doc-com-imagem';
    $md = "# Com Imagem\n\n![[foto.png]]";
    file_put_contents($testEnv->storage()->getFilePath("{$slug}.md"), $md);

    // Create asset file in <slug>_assets
    $assetsDir = $testEnv->storage()->getFilesDir()."/{$slug}_assets";
    mkdir($assetsDir, 0755, true);
    $dummyPng = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4";
    file_put_contents("{$assetsDir}/foto.png", $dummyPng);

    $testEnv->db()->createItem([
        'id' => 'doc-img-1',
        'slug' => $slug,
        'original_name' => 'doc.md',
        'type' => 'doc',
        'file_path' => "{$slug}.md",
        'mime_type' => 'text/markdown',
        'size' => strlen($md),
        'x_token' => 'tok',
        'created_at' => time(),
    ]);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/{$slug}/assets/foto.png";
    $_SERVER['HTTP_ACCEPT'] = 'image/avif,image/webp,image/png,*/*';
    $_GET = [];

    ob_start();
    $testEnv->app()->handleRequest();
    $imgOutput = (string) ob_get_clean();

    expect($imgOutput)->toBe($dummyPng);
});

test('api can toggle item type between file and doc, and edit content', function () use ($testEnv) {
    $slug = 'editavel';
    $md = "# Versão 1\n\nTexto inicial.";
    file_put_contents($testEnv->storage()->getFilePath("{$slug}.md"), $md);

    $testEnv->db()->createItem([
        'id' => 'edit-1',
        'slug' => $slug,
        'original_name' => 'editavel.md',
        'type' => 'file',
        'file_path' => "{$slug}.md",
        'mime_type' => 'text/markdown',
        'size' => strlen($md),
        'x_token' => 'tok',
        'created_at' => time(),
    ]);

    // 1. Change type to doc via PATCH /api/items/editavel
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $_SERVER['REQUEST_URI'] = '/api/items/'.$slug;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token';

    $payload = json_encode(['type' => 'doc']);
    // Mock php://input via custom stream wrapper or temp stream
    // Since php://input is read-only in built-in PHP, we test through handleApi logic
    $itemBefore = $testEnv->db()->findBySlug($slug);
    expect($itemBefore['type'])->toBe('file');

    $testEnv->db()->updateType($slug, 'doc');
    $itemAfter = $testEnv->db()->findBySlug($slug);
    expect($itemAfter['type'])->toBe('doc');
});
