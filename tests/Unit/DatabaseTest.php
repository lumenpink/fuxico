<?php

declare(strict_types=1);

use PicoShare\Database;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir().'/picoshare_test_'.uniqid().'.sqlite';
    $this->db = new Database($this->dbPath);
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

test('creates and retrieves items by slug', function () {
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

    $created = $this->db->createItem($data);
    expect($created)->toBeTrue();

    $item = $this->db->findBySlug('abcde');
    expect($item)->not->toBeNull();
    expect($item['original_name'])->toBe('photo.png');
    expect($item['x_token'])->toBe('sec-token-123');
    expect($this->db->isSlugTaken('abcde'))->toBeTrue();
    expect($this->db->isSlugTaken('unknown'))->toBeFalse();
});

test('validates x_token via findByToken', function () {
    $data = [
        'id' => 'uuid-2',
        'slug' => 'link1',
        'type' => 'url',
        'target_url' => 'https://example.com',
        'x_token' => 'token-abc',
        'is_oneshot' => 1,
        'created_at' => time(),
    ];

    $this->db->createItem($data);

    expect($this->db->findByToken('link1', 'token-abc'))->not->toBeNull();
    expect($this->db->findByToken('link1', 'wrong-token'))->toBeNull();
});

test('deletes and updates expiration', function () {
    $data = [
        'id' => 'uuid-3',
        'slug' => 'todel',
        'type' => 'file',
        'x_token' => 'tok',
        'created_at' => time(),
    ];

    $this->db->createItem($data);

    $newExpires = time() + 7200;
    $this->db->updateExpires('todel', $newExpires);
    $item = $this->db->findBySlug('todel');
    expect((int) $item['expires_at'])->toBe($newExpires);

    $this->db->deleteBySlug('todel');
    expect($this->db->findBySlug('todel'))->toBeNull();
});

test('aggregates stats correctly', function () {
    $this->db->createItem([
        'id' => '1', 'slug' => 'f1', 'type' => 'file', 'size' => 100, 'x_token' => 't1', 'created_at' => time(), 'views' => 2,
    ]);
    $this->db->createItem([
        'id' => '2', 'slug' => 'u1', 'type' => 'url', 'size' => 0, 'x_token' => 't2', 'created_at' => time(), 'views' => 5,
    ]);

    $stats = $this->db->getStats();
    expect($stats['total_items'])->toBe(2);
    expect($stats['total_files'])->toBe(1);
    expect($stats['total_urls'])->toBe(1);
    expect($stats['total_bytes'])->toBe(100);
    expect($stats['total_views'])->toBe(7);
});
