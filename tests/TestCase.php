<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use PicoShare\App;
use PicoShare\Config;
use PicoShare\Database;
use PicoShare\Storage;

abstract class TestCase extends BaseTestCase
{
    public string $tempDir = '';

    public string $baseDir = '';

    public string $dataDir = '';

    public string $filesDir = '';

    public string $dbPath = '';

    public ?Config $config = null;

    public ?Database $db = null;

    public ?Storage $storage = null;

    public ?App $app = null;
}
