<?php

declare(strict_types=1);

use Fuxico\Config;
use Fuxico\I18n;
use Fuxico\Views;

test('rendered dashboard and web ui contain syntactically valid javascript', function () {
    $config = new Config;
    $i18n = new I18n;

    $pages = [
        'Dashboard' => Views::renderDashboardUI($config, $i18n),
        'WebUI' => Views::renderWebUI($config, $i18n),
    ];

    foreach ($pages as $name => $html) {
        preg_match_all('/<script>(.*?)<\/script>/s', $html, $matches);
        expect($matches[1])->not->toBeEmpty();

        foreach ($matches[1] as $idx => $js) {
            if (shell_exec('which node 2>/dev/null')) {
                $tmpFile = sys_get_temp_dir()."/test_js_syntax_{$idx}.js";
                file_put_contents($tmpFile, $js);
                $code = 0;
                $output = [];
                exec('node --check '.escapeshellarg($tmpFile).' 2>&1', $output, $code);
                @unlink($tmpFile);
                expect($code)->toBe(0, "Syntax error in {$name} script #{$idx}: ".implode("\n", $output));
            }
        }
    }
});
