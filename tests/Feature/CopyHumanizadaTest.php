<?php

declare(strict_types=1);

test('comunicacao do site, readme e i18n nao contem jargoes tecnicos proibidos', function () {
    $rootDir = dirname(__DIR__, 2);

    $filesToCheck = [
        $rootDir.'/site/index.html',
        $rootDir.'/site/pt/index.html',
        $rootDir.'/site/pt/pajuba.html',
        $rootDir.'/site/pt/downloads.html',
        $rootDir.'/site/pt/installation.html',
        $rootDir.'/site/pt/protocol.html',
        $rootDir.'/site/preview.html',
        $rootDir.'/README.md',
        $rootDir.'/src/I18n.php',
    ];

    $forbiddenTerms = [
        'musl',
        'binário estático',
        'soberania na borda',
        'zero dependências',
    ];

    foreach ($filesToCheck as $file) {
        expect(file_exists($file))->toBeTrue("Arquivo {$file} deve existir.");
        $content = (string) file_get_contents($file);

        foreach ($forbiddenTerms as $term) {
            expect(mb_stripos($content, $term))->toBeFalse(
                'O arquivo '.basename($file)." nao deve conter o jargao técnico '{$term}'."
            );
        }
    }
});

test('site em portugues contem expressoes populares e pajuba acolhedor', function () {
    $rootDir = dirname(__DIR__, 2);
    $indexPt = (string) file_get_contents($rootDir.'/site/pt/index.html');
    $pajubaPt = (string) file_get_contents($rootDir.'/site/pt/pajuba.html');

    expect($indexPt)->toContain('boca a boca');
    expect($pajubaPt)->toContain('Aquendar');
    expect($pajubaPt)->toContain('Desaquendar');
});
