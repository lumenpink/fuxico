<?php

declare(strict_types=1);

namespace Tests;

use Fuxico\App;
use Fuxico\Config;
use Fuxico\Database;
use Fuxico\Storage;
use PHPUnit\Framework\TestCase as BaseTestCase;

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
