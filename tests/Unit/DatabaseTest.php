<?php

declare(strict_types=1);

use Fuxico\Database;

$testEnv = new class
{
    public string $dbPath = '';

    public ?Database $db = null;

    public function db(): Database
    {
        assert($this->db !== null);

        return $this->db;
    }
};

beforeEach(function () use ($testEnv) {
    $testEnv->dbPath = sys_get_temp_dir().'/fuxico_test_'.uniqid().'.sqlite';
    $testEnv->db = new Database($testEnv->dbPath);
});

afterEach(function () use ($testEnv) {
    if (file_exists($testEnv->dbPath)) {
        unlink($testEnv->dbPath);
    }
});

test('creates and retrieves items by slug', function () use ($testEnv) {
    $data = [
        'id' => 'uuid-1',
        'slug' => 'abcde',
        'original_name' => 'photo.png',
        'type' => 'file',
        'file_path' => 'abcde.png',
        'mime_type' => 'image/png',
        'size' => 12345,
        'x_token' => 'sec-token-123',
        'is_oneshot' => 0,
        'expires_at' => time() + 3600,
        'created_at' => time(),
        'views' => 0,
    ];

    $created = $testEnv->db()->createItem($data);
    expect($created)->toBeTrue();

    $item = $testEnv->db()->findBySlug('abcde');
    expect($item)->not->toBeNull();
    expect($item['original_name'])->toBe('photo.png');
    expect($item['x_token'])->toBe('sec-token-123');
    expect($testEnv->db()->isSlugTaken('abcde'))->toBeTrue();
    expect($testEnv->db()->isSlugTaken('unknown'))->toBeFalse();
});

test('validates x_token via findByToken', function () use ($testEnv) {
    $data = [
        'id' => 'uuid-2',
        'slug' => 'link1',
        'type' => 'url',
        'target_url' => 'https://example.com',
        'x_token' => 'token-abc',
        'is_oneshot' => 1,
        'created_at' => time(),
    ];

    $testEnv->db()->createItem($data);

    expect($testEnv->db()->findByToken('link1', 'token-abc'))->not->toBeNull();
    expect($testEnv->db()->findByToken('link1', 'wrong-token'))->toBeNull();
});

test('deletes and updates expiration', function () use ($testEnv) {
    $data = [
        'id' => 'uuid-3',
        'slug' => 'todel',
        'type' => 'file',
        'x_token' => 'tok',
        'created_at' => time(),
    ];

    $testEnv->db()->createItem($data);

    $newExpires = time() + 7200;
    $testEnv->db()->updateExpires('todel', $newExpires);
    $item = $testEnv->db()->findBySlug('todel');
    expect((int) $item['expires_at'])->toBe($newExpires);

    $testEnv->db()->deleteBySlug('todel');
    expect($testEnv->db()->findBySlug('todel'))->toBeNull();
});

test('aggregates stats correctly', function () use ($testEnv) {
    $testEnv->db()->createItem([
        'id' => '1', 'slug' => 'f1', 'type' => 'file', 'size' => 100, 'x_token' => 't1', 'created_at' => time(), 'views' => 2,
    ]);
    $testEnv->db()->createItem([
        'id' => '2', 'slug' => 'u1', 'type' => 'url', 'size' => 0, 'x_token' => 't2', 'created_at' => time(), 'views' => 5,
    ]);

    $stats = $testEnv->db()->getStats();
    expect($stats['total_items'])->toBe(2);
    expect($stats['total_files'])->toBe(1);
    expect($stats['total_urls'])->toBe(1);
    expect($stats['total_bytes'])->toBe(100);
    expect($stats['total_views'])->toBe(7);
});
