<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\OgCard;
use Fuxico\Storage;
use Fuxico\Views;

$ogEnv = new class
{
    public string $tempDir = '';

    public ?Config $config = null;

    public ?Database $db = null;

    public ?Storage $storage = null;
};

beforeEach(function () use ($ogEnv) {
    $ogEnv->tempDir = sys_get_temp_dir().'/fuxico_og_test_'.bin2hex(random_bytes(6));
    mkdir($ogEnv->tempDir, 0755, true);
    mkdir($ogEnv->tempDir.'/files', 0755, true);

    $ogEnv->config = new Config;
    $ogEnv->config->dataDir = $ogEnv->tempDir;
    $ogEnv->config->filesDir = $ogEnv->tempDir.'/files';
    $ogEnv->config->dbPath = $ogEnv->tempDir.'/fuxico.db';
    $ogEnv->config->baseUrl = 'https://fuxico.2lp.in';

    $ogEnv->db = new Database($ogEnv->config->dbPath);
    $ogEnv->storage = new Storage($ogEnv->tempDir, $ogEnv->config->filesDir);
});

afterEach(function () use ($ogEnv) {
    http_response_code(200);
    if ($ogEnv->tempDir !== '' && is_dir($ogEnv->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($ogEnv->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
        }
        rmdir($ogEnv->tempDir);
    }
});

it('renders dynamic Open Graph PNG image with 1200x630 dimensions', function () use ($ogEnv) {
    $item = [
        'slug' => 'fofoca123',
        'original_name' => 'fanzine-travesti.pdf',
        'type' => 'file',
        'mime_type' => 'application/pdf',
        'size' => 2048576,
        'created_at' => time(),
        'views' => 42,
    ];

    $pngData = OgCard::generatePng($item, $ogEnv->config);
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

it('handles /<slug>/og.png HTTP route returning PNG binary', function () use ($ogEnv) {
    $filePath = $ogEnv->tempDir.'/files/arquivo.txt';
    file_put_contents($filePath, 'conteudo de teste');

    $ogEnv->db->createItem([
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
    $app = new App($ogEnv->config, $ogEnv->db, $ogEnv->storage);
    $app->handleRequest();
    $output = ob_get_clean();

    $info = @getimagesizefromstring($output);
    expect($info)->not->toBeFalse();
    expect($info[0])->toBe(1200);
    expect($info[1])->toBe(630);
});

it('handles /og/<slug> alternative route and query ?og=1', function () use ($ogEnv) {
    $ogEnv->db->createItem([
        'slug' => 'fofoca-alt',
        'x_token' => 'tok456',
        'type' => 'gist',
        'original_name' => 'snippet.py',
        'size' => 128,
        'expires_at' => time() + 3600,
    ]);

    // Teste 1: /og/fofoca-alt
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/og/fofoca-alt';
    ob_start();
    $app = new App($ogEnv->config, $ogEnv->db, $ogEnv->storage);
    $app->handleRequest();
    $output = ob_get_clean();
    $info = @getimagesizefromstring($output);
    expect($info)->not->toBeFalse();
    expect($info[0])->toBe(1200);

    // Teste 2: /fofoca-alt?og=1
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/fofoca-alt?og=1';
    $_GET['og'] = '1';
    ob_start();
    $app = new App($ogEnv->config, $ogEnv->db, $ogEnv->storage);
    $app->handleRequest();
    $output2 = ob_get_clean();
    unset($_GET['og']);
    $info2 = @getimagesizefromstring($output2);
    expect($info2)->not->toBeFalse();
});

it('returns 404 for non-existent or expired item og cards', function () use ($ogEnv) {
    // 1. Inexistente
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/og/fantasma-xyz';
    ob_start();
    $app = new App($ogEnv->config, $ogEnv->db, $ogEnv->storage);
    $app->handleRequest();
    $out = ob_get_clean();
    expect(http_response_code())->toBe(404);

    // 2. Expirado
    $ogEnv->db->createItem([
        'slug' => 'fofoca-velha',
        'x_token' => 'tok789',
        'type' => 'file',
        'original_name' => 'velho.txt',
        'size' => 10,
        'expires_at' => time() - 3600, // expirado
    ]);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/fofoca-velha/og.png';
    ob_start();
    $app->handleRequest();
    $out2 = ob_get_clean();
    expect(http_response_code())->toBe(404);
});
