<?php

declare(strict_types=1);

test('bin/verify-site.sh executes successfully and validates site integrity', function () {
    $rootDir = dirname(__DIR__, 2);
    $script = $rootDir.'/bin/verify-site.sh';

    expect(file_exists($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    $output = [];
    $code = 0;
    exec("bash {$script} 2>&1", $output, $code);

    expect($code)->toBe(0);
    expect(implode("\n", $output))->toContain('Todas as páginas, assets e instaladores foram verificados com sucesso!');
});

test('site/CNAME is configured for fuxico.2lp.in', function () {
    $rootDir = dirname(__DIR__, 2);
    $cname = $rootDir.'/site/CNAME';

    expect(file_exists($cname))->toBeTrue();
    expect(trim((string) file_get_contents($cname)))->toBe('fuxico.2lp.in');
});

test('site/fux-installer and site/install.sh are valid bash scripts and executable', function () {
    $rootDir = dirname(__DIR__, 2);
    $installer = $rootDir.'/site/fux-installer';
    $symlink = $rootDir.'/site/install.sh';

    expect(file_exists($installer))->toBeTrue();
    expect(is_executable($installer))->toBeTrue();
    expect(file_exists($symlink))->toBeTrue();
    expect(is_executable($symlink))->toBeTrue();

    // Check syntax with bash -n
    $output = [];
    $code = 0;
    exec("bash -n {$installer} 2>&1", $output, $code);
    expect($code)->toBe(0);
});

test('all required documentation pages exist in English and Portuguese', function () {
    $rootDir = dirname(__DIR__, 2);
    $siteDir = $rootDir.'/site';

    $pages = [
        'index.html',
        'installation.html',
        'downloads.html',
        'commands.html',
        'protocol.html',
        'pajuba.html',
    ];

    foreach ($pages as $page) {
        $enPage = $siteDir.'/'.$page;
        $ptPage = $siteDir.'/pt/'.$page;

        expect(file_exists($enPage))->toBeTrue("Missing English page: {$page}");
        expect(file_exists($ptPage))->toBeTrue("Missing Portuguese page: pt/{$page}");

        $enContent = (string) file_get_contents($enPage);
        $ptContent = (string) file_get_contents($ptPage);

        // Assert basic HTML structure
        expect($enContent)->toContain('<!DOCTYPE html>');
        expect($ptContent)->toContain('<!DOCTYPE html>');
        expect($enContent)->toContain('assets/style.css');
        expect($ptContent)->toContain('../assets/style.css');
    }
});

test('site/downloads.html contains single-board computer hardware guidance', function () {
    $rootDir = dirname(__DIR__, 2);
    $downloadsEn = (string) file_get_contents($rootDir.'/site/downloads.html');
    $downloadsPt = (string) file_get_contents($rootDir.'/site/pt/downloads.html');

    // Asserts coverage of SBC clones
    expect($downloadsEn)->toContain('Orange Pi');
    expect($downloadsEn)->toContain('Banana Pi');
    expect($downloadsEn)->toContain('Raspberry Pi');
    expect($downloadsEn)->toContain('BeagleBone');

    expect($downloadsPt)->toContain('Orange Pi');
    expect($downloadsPt)->toContain('Banana Pi');
    expect($downloadsPt)->toContain('Raspberry Pi');
    expect($downloadsPt)->toContain('BeagleBone');
});
