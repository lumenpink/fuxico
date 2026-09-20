<?php

declare(strict_types=1);

use PicoShare\Config;

test('default configuration values', function () {
    $config = new Config;

    expect($config->publicMode)->toBeFalse();
    expect($config->authTokens)->toBe(['tatanka']);
    expect($config->filenameLength)->toBe(5);
    expect($config->secretFilenameLength)->toBe(16);
    expect($config->maxUploadSize)->toBe(524288000);
});

test('authorization checks with master tokens and bearer prefix', function () {
    $config = new Config;

    expect($config->isAuthorized('tatanka'))->toBeTrue();
    expect($config->isAuthorized('Bearer tatanka'))->toBeTrue();
    expect($config->isAuthorized('wrong_token'))->toBeFalse();
    expect($config->isAuthorized(null))->toBeFalse();
    expect($config->isAuthorized(''))->toBeFalse();
});
