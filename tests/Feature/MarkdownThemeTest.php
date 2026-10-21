<?php

declare(strict_types=1);

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\I18n;
use Fuxico\Storage;
use Fuxico\Views;

$themeEnv = new class
{
    public string $tempDir = '';

    public ?Config $config = null;

    public ?Database $db = null;

    public ?Storage $storage = null;

    public ?App $app = null;

    public ?I18n $i18n = null;

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

    public function config(): Config
    {
        assert($this->config !== null);

        return $this->config;
    }

    public function i18n(): I18n
    {
        assert($this->i18n !== null);

        return $this->i18n;
    }
};

beforeEach(function () use ($themeEnv) {
    $themeEnv->tempDir = sys_get_temp_dir().'/fuxico_theme_'.uniqid();
    $dataDir = $themeEnv->tempDir.'/data';
    $filesDir = $dataDir.'/files';
    $dbPath = $dataDir.'/fuxico.sqlite';

    $themeEnv->config = new Config;
    $themeEnv->config->dataDir = $dataDir;
    $themeEnv->config->filesDir = $filesDir;
    $themeEnv->config->dbPath = $dbPath;
    $themeEnv->config->baseUrl = 'http://localhost:8080';
    $themeEnv->config->authTokens = ['master-token'];

    $themeEnv->db = new Database($dbPath);
    $themeEnv->storage = new Storage($dataDir, $filesDir);
    $themeEnv->i18n = new I18n;
    $views = new Views($themeEnv->i18n, $themeEnv->config);

    $themeEnv->app = new App($themeEnv->config, $themeEnv->db, $themeEnv->storage, $themeEnv->i18n, $views);

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = 'localhost:8080';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-token';
});

afterEach(function () use ($themeEnv) {
    if (is_dir($themeEnv->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeEnv->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($themeEnv->tempDir);
    }
});

test('uploading markdown file with theme=acad sets data-theme="acad" in baked HTML and saves theme in item data', function () use ($themeEnv) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'fux_md_');
    file_put_contents($tmpFile, "# Artigo Científico\n\nConteúdo acadêmico relevante.\n");

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
    $_FILES['file'] = [
        'name' => 'paper.md',
        'type' => 'text/markdown',
        'tmp_name' => $tmpFile,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmpFile),
    ];
    $_POST = [
        'doc' => '1',
        'theme' => 'acad',
    ];

    ob_start();
    $themeEnv->app()->handleRequest();
    $output = trim(ob_get_clean());

    expect(http_response_code())->toBe(200);
    $slug = basename($output);

    $item = $themeEnv->db()->findBySlug($slug);
    expect($item)->not->toBeNull();
    expect($item['type'])->toBe('doc');

    $itemData = json_decode($item['data'] ?? '{}', true);
    expect($itemData['theme'] ?? '')->toBe('acad');

    $htmlPath = $themeEnv->storage()->getFilePath("{$slug}.html");
    expect(file_exists($htmlPath))->toBeTrue();
    $bakedHtml = file_get_contents($htmlPath);
    expect($bakedHtml)->toContain('data-theme="acad"');

    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

test('PATCH /api/items/{slug} with theme=acad updates theme and rebakes HTML with data-theme="acad"', function () use ($themeEnv) {
    $slug = 'fux-doc-theme-test';
    $filePath = "{$slug}.md";
    $mdContent = "# Título Original\n\nTexto qualquer.";
    file_put_contents($themeEnv->storage()->getFilePath($filePath), $mdContent);

    // Initial item created as doc with default zine theme
    $themeEnv->db()->createItem([
        'id' => uniqid('item_'),
        'slug' => $slug,
        'type' => 'doc',
        'file_path' => $filePath,
        'original_name' => 'nota.md',
        'mime_type' => 'text/markdown',
        'size' => strlen($mdContent),
        'x_token' => 'tok-theme-test',
        'created_at' => time(),
        'data' => json_encode(['theme' => 'zine']),
    ]);

    // Initial bake
    $htmlContent = '<!DOCTYPE html><html lang="pt-BR" data-theme="zine"><body><h1>Título Original</h1></body></html>';
    file_put_contents($themeEnv->storage()->getFilePath("{$slug}.html"), $htmlContent);

    // PATCH /api/items/{slug} with theme=acad
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $_SERVER['REQUEST_URI'] = "/api/items/{$slug}";
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer master-token';
    $_POST = [
        'theme' => 'acad',
    ];

    ob_start();
    $themeEnv->app()->handleRequest();
    ob_get_clean();

    expect(http_response_code())->toBe(200);

    $updated = $themeEnv->db()->findBySlug($slug);
    $updatedData = json_decode($updated['data'] ?? '{}', true);
    expect($updatedData['theme'] ?? '')->toBe('acad');

    // Rebaked HTML must have data-theme="acad"
    $rebakedHtml = file_get_contents($themeEnv->storage()->getFilePath("{$slug}.html"));
    expect($rebakedHtml)->toContain('data-theme="acad"');
});

test('renderWebUI has 2-column options-grid, advanced options drawer, and default eternal expiration', function () use ($themeEnv) {
    $html = Views::renderWebUI($themeEnv->config(), $themeEnv->i18n());

    // Grid 2x2: CSS has repeat(2, 1fr)
    expect($html)->toContain('grid-template-columns: repeat(2, 1fr)');

    // Advanced drawer exists in both tabs
    expect($html)->toContain('<details class="advanced-drawer"');
    expect($html)->toContain('class="advanced-summary"');

    // Eternal expiration is selected by default in optExpires
    expect($html)->toMatch('/<select id="optExpires"[^>]*>[\s\S]*?<option value="" selected>/');
});

test('renderDashboardUI has theme selector in editItemModal for doc items', function () use ($themeEnv) {
    $html = Views::renderDashboardUI($themeEnv->config(), $themeEnv->i18n());

    expect($html)->toContain('id="editItemThemeGroup"');
    expect($html)->toContain('id="editItemThemeSelect"');
    expect($html)->toContain('value="acad"');
    expect($html)->toContain('value="zine"');
});
