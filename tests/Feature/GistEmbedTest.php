<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\GistRenderer;
use Fuxico\Storage;

$embedEnv = new class
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

beforeEach(function () use ($embedEnv) {
    $embedEnv->tempDir = sys_get_temp_dir().'/fuxico_embed_test_'.uniqid();
    mkdir($embedEnv->tempDir, 0755, true);

    $embedEnv->dbPath = $embedEnv->tempDir.'/fuxico.sqlite';
    $embedEnv->filesDir = $embedEnv->tempDir.'/files';
    mkdir($embedEnv->filesDir, 0755, true);

    $embedEnv->config = new Config;
    $embedEnv->config->publicMode = false;
    $embedEnv->config->baseUrl = 'https://fuxico.test';
    $embedEnv->config->authTokens = ['test-token'];

    $embedEnv->db = new Database($embedEnv->dbPath);
    $embedEnv->storage = new Storage($embedEnv->tempDir, $embedEnv->filesDir);
    $embedEnv->app = new App($embedEnv->config, $embedEnv->db, $embedEnv->storage);

    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER = [
        'HTTP_HOST' => 'fuxico.test',
        'REQUEST_SCHEME' => 'https',
    ];
});

afterEach(function () use ($embedEnv) {
    if (is_dir($embedEnv->tempDir)) {
        $embedEnv->storage()->deleteDirectory($embedEnv->tempDir);
    }
});

// ---------------------------------------------------------------------------
// Testes unitários de GistRenderer::renderEmbedJs
// ---------------------------------------------------------------------------

test('renderEmbedJs retorna JavaScript válido com IIFE', function () {
    $files = ['hello.txt' => 'Olá, mundo!'];
    $js = GistRenderer::renderEmbedJs($files, [
        'slug' => 'meu-gist',
        'base_url' => 'https://2lp.in',
        'theme' => 'zine',
    ]);

    expect($js)
        ->toContain('(function()')
        ->toContain('document.currentScript')
        ->toContain('.fuxico-gist-embed')
        ->toContain('Olá, mundo!'); // JSON_UNESCAPED_UNICODE preserva UTF-8
});

test('renderEmbedJs filtra arquivo específico via opção file', function () {
    $files = [
        'a.txt' => 'conteudo de A',
        'b.txt' => 'conteudo de B',
    ];
    $js = GistRenderer::renderEmbedJs($files, ['file' => 'a.txt']);

    expect($js)
        ->toContain('conteudo de A')
        ->not->toContain('conteudo de B');
});

test('renderEmbedJs ignora opção file inválida e renderiza todos', function () {
    $files = [
        'a.txt' => 'conteudo A',
        'b.txt' => 'conteudo B',
    ];
    $js = GistRenderer::renderEmbedJs($files, ['file' => 'inexistente.txt']);

    expect($js)
        ->toContain('conteudo A')
        ->toContain('conteudo B');
});

test('renderEmbedJs aplica tema acad corretamente', function () {
    $files = ['readme.md' => '# Oi'];
    $js = GistRenderer::renderEmbedJs($files, ['theme' => 'acad']);

    // No tema acad, o bg é #f6f6f0
    expect($js)->toContain('#f6f6f0');
});

test('renderEmbedJs usa tema zine por padrão', function () {
    $files = ['readme.md' => '# Oi'];
    $js = GistRenderer::renderEmbedJs($files, []);

    // No tema zine, o bg é #121212
    expect($js)->toContain('#121212');
});

test('renderEmbedJs inclui link para o gist original quando gist_url fornecido', function () {
    $files = ['x.py' => 'print("hello")'];
    $js = GistRenderer::renderEmbedJs($files, [
        'gist_url' => 'https://2lp.in/abc123',
    ]);

    expect($js)->toContain('Ver no Fuxico');
});

test('renderEmbedJs escapa conteúdo de arquivo contra XSS', function () {
    $files = ['xss.html' => '<script>alert(1)</script>'];
    $js = GistRenderer::renderEmbedJs($files, []);

    // JSON_HEX_TAG converte < > em \u003C \u003E — não deve conter tags literais nos dados JSON
    expect($js)->not->toContain('<script>alert(1)</script>');
    // PHP json_encode com JSON_HEX_TAG usa maiúsculo: \u003C
    expect($js)->toContain('\u003C');
});

// ---------------------------------------------------------------------------
// Testes de integração: endpoint /<slug>/embed.js via App
// ---------------------------------------------------------------------------

/**
 * Cria um gist com dois arquivos e retorna o slug.
 */
function criarGistFixtura(object $env, string $slug = 'gist-embed-test'): string
{
    $env->db()->createItem([
        'slug' => $slug,
        'original_name' => 'gist-embed-test',
        'type' => 'gist',
        'target_url' => null,
        'file_path' => null,
        'mime_type' => null,
        'size' => 200,
        'is_oneshot' => 0,
        'x_token' => 'xtok-embed',
        'expires_at' => null,
        'created_at' => time(),
        'user_id' => null,
    ]);

    // Cria diretório do gist e arquivos
    $gistDir = $env->filesDir.'/'.$slug.'_gist';
    mkdir($gistDir, 0755, true);
    file_put_contents($gistDir.'/foo.py', 'print("foo")');
    file_put_contents($gistDir.'/bar.txt', 'conteudo bar');

    return $slug;
}

test('GET /<slug>/embed.js retorna JavaScript com conteúdo dos arquivos', function () use ($embedEnv) {
    $slug = criarGistFixtura($embedEnv);

    $_SERVER['REQUEST_URI'] = "/{$slug}/embed.js";
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    $embedEnv->app()->handleRequest();
    $body = ob_get_clean();

    // http_response_code() retorna false quando não setado explicitamente (PHP padrão 200)
    expect(http_response_code())->not->toBe(404);
    expect($body)
        ->toContain('(function()')
        ->toContain('foo.py')
        ->toContain('bar.txt');
});

test('GET /<slug>/embed.js?file=foo.py renderiza apenas arquivo solicitado', function () use ($embedEnv) {
    $slug = criarGistFixtura($embedEnv);

    $_GET['file'] = 'foo.py';
    $_SERVER['REQUEST_URI'] = "/{$slug}/embed.js?file=foo.py";
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    $embedEnv->app()->handleRequest();
    $body = ob_get_clean();

    expect($body)
        ->toContain('foo.py')
        ->not->toContain('bar.txt');
});

test('GET /<slug>/embed.js?theme=acad aplica tema acad', function () use ($embedEnv) {
    $slug = criarGistFixtura($embedEnv);

    $_GET['theme'] = 'acad';
    $_SERVER['REQUEST_URI'] = "/{$slug}/embed.js?theme=acad";
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    $embedEnv->app()->handleRequest();
    $body = ob_get_clean();

    expect($body)->toContain('#f6f6f0');
});

test('GET /slug-invalido/embed.js retorna 404', function () use ($embedEnv) {
    $_SERVER['REQUEST_URI'] = '/slug-invalido/embed.js';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    $embedEnv->app()->handleRequest();
    ob_get_clean();

    expect(http_response_code())->toBe(404);
});

test('GET /<slug-url>/embed.js retorna 404 (tipo incompatível)', function () use ($embedEnv) {
    $embedEnv->db()->createItem([
        'slug' => 'minha-url',
        'original_name' => 'https://example.com',
        'type' => 'url',
        'target_url' => 'https://example.com',
        'file_path' => null,
        'mime_type' => null,
        'size' => 0,
        'is_oneshot' => 0,
        'x_token' => 'xtok-url',
        'expires_at' => null,
        'created_at' => time(),
        'user_id' => null,
    ]);

    $_SERVER['REQUEST_URI'] = '/minha-url/embed.js';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    $embedEnv->app()->handleRequest();
    ob_get_clean();

    expect(http_response_code())->toBe(404);
});
