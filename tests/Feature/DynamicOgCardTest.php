<?php

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\OgCard;
use Fuxico\Storage;
use Fuxico\Views;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuxico_og_test_'.bin2hex(random_bytes(6));
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/files', 0755, true);

    $this->config = new Config;
    $this->config->storageDir = $this->tempDir.'/files';
    $this->config->dbPath = $this->tempDir.'/fuxico.db';
    $this->config->baseUrl = 'https://fuxico.2lp.in';

    $this->db = new Database($this->config->dbPath);
    $this->storage = new Storage($this->tempDir, $this->config->storageDir);
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
        }
        rmdir($this->tempDir);
    }
});

it('renders dynamic Open Graph PNG image with 1200x630 dimensions', function () {
    $item = [
        'slug' => 'fofoca123',
        'original_name' => 'fanzine-travesti.pdf',
        'type' => 'file',
        'mime_type' => 'application/pdf',
        'size' => 2048576,
        'created_at' => time(),
        'views' => 42,
    ];

    $pngData = OgCard::generatePng($item, $this->config);
    expect($pngData)->not->toBeEmpty();

    $info = getimagesizefromstring($pngData);
    expect($info)->not->toBeFalse();
    expect($info[0])->toBe(1200);
    expect($info[1])->toBe(630);
    expect($info['mime'])->toBe('image/png');
});

it('includes open graph and twitter card meta tags in renderPreDownload view', function () {
    $item = [
        'slug' => 'teste-og',
        'original_name' => 'relatorio-anual.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
        'created_at' => time(),
    ];

    $html = Views::renderPreDownload($item, []);

    expect($html)->toContain('<meta property="og:title"');
    expect($html)->toContain('<meta property="og:image"');
    expect($html)->toContain('teste-og/og.png');
    expect($html)->toContain('<meta name="twitter:card" content="summary_large_image"');
});

it('handles /<slug>/og.png HTTP route returning PNG binary', function () {
    $filePath = $this->tempDir.'/files/arquivo.txt';
    file_put_contents($filePath, "conteudo de teste");

    $this->db->createItem([
        'slug' => 'card-teste',
        'x_token' => 'token123',
        'type' => 'file',
        'file_path' => 'arquivo.txt',
        'original_name' => 'manifesto-zine.txt',
        'mime_type' => 'text/plain',
        'size' => 17,
        'expires_at' => time() + 3600,
    ]);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/card-teste/og.png';
    $_SERVER['HTTP_ACCEPT'] = 'image/png';

    ob_start();
    $app = new App($this->config, $this->db, $this->storage);
    $app->handleRequest();
    $output = ob_get_clean();

    $info = @getimagesizefromstring($output);
    expect($info)->not->toBeFalse();
    expect($info[0])->toBe(1200);
    expect($info[1])->toBe(630);
});
