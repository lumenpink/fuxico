<?php

declare(strict_types=1);

use Fuxico\I18n;

test('defaults to english when no accept-language provided', function () {
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE'], $_GET['lang']);
    putenv('FUXICO_LANG');

    $i18n = new I18n;
    expect($i18n->getLang())->toBe('en');
    expect($i18n->t('drop_idle'))->toBe('Spill the beans here.');
});

test('detects portuguese from HTTP_ACCEPT_LANGUAGE header', function () {
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7';

    $i18n = new I18n;
    expect($i18n->getLang())->toBe('pt');
    expect($i18n->t('drop_idle'))->toBe('Larga a pedrada aqui.');
    expect($i18n->t('btn_upload'))->toBe('Solta o verbo ⚡');
});

test('respects explicit query parameter override', function () {
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
    $_GET['lang'] = 'pt';

    $i18n = new I18n;
    expect($i18n->getLang())->toBe('pt');
    expect($i18n->t('btn_upload'))->toBe('Solta o verbo ⚡');

    unset($_GET['lang']);
});

test('falls back gracefully to english for missing keys', function () {
    $i18n = new I18n('pt');
    expect($i18n->t('non_existent_key_xyz'))->toBe('non_existent_key_xyz');
});
