<?php

declare(strict_types=1);

use Fuxico\Config;

test('default configuration values', function () {
    $config = new Config;

    expect($config->publicMode)->toBeFalse();
    expect($config->authTokens)->toBe([]);
    expect($config->filenameLength)->toBe(5);
    expect($config->secretFilenameLength)->toBe(16);
    expect($config->maxUploadSize)->toBe(524288000);
});

test('authorization checks with master tokens and bearer prefix', function () {
    $config = new Config;
    $config->authTokens = ['test-master-token'];

    expect($config->isAuthorized('test-master-token'))->toBeTrue();
    expect($config->isAuthorized('Bearer test-master-token'))->toBeTrue();
    expect($config->isAuthorized('wrong_token'))->toBeFalse();
    expect($config->isAuthorized(null))->toBeFalse();
    expect($config->isAuthorized(''))->toBeFalse();
});
