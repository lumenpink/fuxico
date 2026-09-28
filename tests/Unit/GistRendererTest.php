<?php

declare(strict_types=1);

namespace Tests\Unit;

use Fuxico\GistRenderer;

test('renders multi-file gist into standalone html with tabs and dual-skin', function () {
    $files = [
        'app.py' => "import sys\nprint('hello')\n",
        'README.md' => "# Projeto Fuxico\n\n> [!NOTE]\n> callout de teste\n",
        'config.json' => "{\n  \"debug\": true\n}\n",
    ];

    $html = GistRenderer::render($files, [
        'title' => 'Meu Super Gist',
        'slug' => 'test-gist-1',
        'base_url' => 'https://fuxico.cc',
    ]);

    expect($html)->toContain('<!DOCTYPE html>');
    expect($html)->toContain('data-theme="zine"');
    expect($html)->toContain('data-theme="acad"');
    expect($html)->toContain('Meu Super Gist');

    // README first order
    expect(strpos($html, 'README.md'))->toBeLessThan(strpos($html, 'app.py'));

    // Check tabs
    expect($html)->toContain('class="gist-tab');
    expect($html)->toContain('README.md');
    expect($html)->toContain('app.py');
    expect($html)->toContain('config.json');

    // Check code lines and preview
    expect($html)->toContain('gist-md-preview');
    expect($html)->toContain('callout de teste');
    expect($html)->toContain('language-py');
    expect($html)->toContain('language-json');

    // Check quick links
    expect($html)->toContain('https://fuxico.cc/test-gist-1/zip');
    expect($html)->toContain('https://fuxico.cc/test-gist-1/raw/README.md');
    expect($html)->toContain('https://fuxico.cc/test-gist-1/raw/app.py');
});
