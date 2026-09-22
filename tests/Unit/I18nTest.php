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

test('provides authentic pajubá and travesti cultural lexicon', function () {
    $pt = new I18n('pt');
    expect($pt->t('brand_sub'))->toBe('Envios efêmeros e links curtos');
    expect($pt->t('brand_sub_line1'))->toBe('Envios efêmeros');
    expect($pt->t('brand_sub_line2'))->toBe('e links curtos');
    expect($pt->t('cultural_badge'))->toContain('tecnologia travesti latina');
    expect($pt->t('footer_travesti_text'))->toBe('travesti latina');
    expect($pt->t('opt_secret'))->toContain('Aquendar');
    expect($pt->t('opt_burn'))->toContain('Desaquenda');
    expect($pt->t('copy_success'))->toContain('Babado');

    $en = new I18n('en');
    expect($en->t('brand_sub'))->toBe('Ephemeral send & short links');
    expect($en->t('brand_sub_line1'))->toBe('Ephemeral send');
    expect($en->t('brand_sub_line2'))->toBe('& short links');
    expect($en->t('cultural_badge'))->toContain('Travesti & Latin tech');
    expect($en->t('footer_travesti_text'))->toBe('Latin travesti');
    expect($en->t('opt_secret'))->toContain('Aquendar');
});

test('provides descriptive pajubá and technical tooltips in pt and en', function () {
    $pt = new I18n('pt');
    expect($pt->t('tip_secret'))->toContain('Aquendar é guardar');
    expect($pt->t('tip_burn'))->toContain('Desaquenda é soltar');
    expect($pt->t('tip_expires'))->toContain('retenção');

    $en = new I18n('en');
    expect($en->t('tip_secret'))->toContain('Aquendar means to hide');
    expect($en->t('tip_burn'))->toContain('Desaquenda means to release');
});
