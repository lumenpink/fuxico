<?php

declare(strict_types=1);

use Fuxico\RepoRenderer;

test('renders empty git repository with clone instructions and dual-skin toggle', function () {
    $metadata = [
        'has_commits' => false,
        'default_branch' => 'main',
        'commit_count' => 0,
        'commits' => [],
        'files' => [],
        'readme_content' => null,
        'readme_filename' => null,
        'size' => 0,
    ];

    $html = RepoRenderer::render($metadata, [
        'slug' => 'fuxico-core',
        'base_url' => 'https://fuxico.cc',
    ]);

    expect($html)->toContain('fuxico-core');
    expect($html)->toContain('https://fuxico.cc/fuxico-core.git');
    expect($html)->toContain('Repositório Vazio / Quick Setup');
    expect($html)->toContain('git remote add origin https://fuxico.cc/fuxico-core.git');
    expect($html)->toContain('toggleRepoTheme()');
    expect($html)->toContain('Modo Acadêmico');
});

test('renders populated repository with markdown readme, files, and commits', function () {
    $metadata = [
        'has_commits' => true,
        'default_branch' => 'main',
        'commit_count' => 1,
        'commits' => [
            [
                'hash' => 'a1b2c3d',
                'author' => 'Lumen Pink',
                'date' => '2026-09-28',
                'message' => 'Primeiro fuxico no repositório',
            ],
        ],
        'files' => [
            ['name' => 'README.md', 'size' => 120],
            ['name' => 'main.rs', 'size' => 450],
        ],
        'readme_content' => "# Manifesto Fuxico\n\n> [!NOTE]\n> Transmissão sem caô.\n",
        'readme_filename' => 'README.md',
        'size' => 10240,
    ];

    $html = RepoRenderer::render($metadata, [
        'slug' => 'manifesto',
        'base_url' => 'https://fuxico.cc',
    ]);

    expect($html)->toContain('Manifesto Fuxico');
    expect($html)->toContain('Transmissão sem caô.');
    expect($html)->toContain('Primeiro fuxico no repositório');
    expect($html)->toContain('a1b2c3d');
    expect($html)->toContain('Lumen Pink');
    expect($html)->toContain('README.md');
    expect($html)->toContain('main.rs');
    expect($html)->toContain('https://fuxico.cc/manifesto/zip');
    expect($html)->toContain('https://fuxico.cc/manifesto/raw/README.md');
});
