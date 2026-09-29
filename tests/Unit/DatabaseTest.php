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

test('manages users, roles and user_id post filtering', function () use ($testEnv) {
    // Create users
    $user1 = [
        'id' => 'u1',
        'username' => 'lorena',
        'token' => 'fux_lorena_123',
        'role' => 'admin',
        'created_at' => time(),
    ];
    $user2 = [
        'id' => 'u2',
        'username' => 'babalu',
        'token' => 'fux_babalu_456',
        'role' => 'member',
        'created_at' => time(),
    ];

    expect($testEnv->db()->createUser($user1))->toBeTrue();
    expect($testEnv->db()->createUser($user2))->toBeTrue();

    // Find users
    $found = $testEnv->db()->findUserByToken('fux_lorena_123');
    expect($found)->not->toBeNull();
    expect($found['username'])->toBe('lorena');
    expect($found['role'])->toBe('admin');

    $foundByUname = $testEnv->db()->findUserByUsername('LORENA');
    expect($foundByUname)->not->toBeNull();
    expect($foundByUname['id'])->toBe('u1');

    // Create items for user2 and anonymous
    $testEnv->db()->createItem([
        'id' => 'item-u2',
        'slug' => 'babalu-secret',
        'type' => 'file',
        'x_token' => 'xtok1',
        'created_at' => time(),
        'user_id' => 'u2',
    ]);
    $testEnv->db()->createItem([
        'id' => 'item-anon',
        'slug' => 'anon-note',
        'type' => 'doc',
        'x_token' => 'xtok2',
        'created_at' => time(),
        'user_id' => null,
    ]);

    // List all (admin view)
    $all = $testEnv->db()->listItems();
    expect(count($all))->toBe(2);

    // List user2 only (member view)
    $u2Items = $testEnv->db()->listItems(100, 0, null, null, 'u2');
    expect(count($u2Items))->toBe(1);
    expect($u2Items[0]['slug'])->toBe('babalu-secret');
    expect($u2Items[0]['author'])->toBe('babalu');
    expect($testEnv->db()->countItems(null, null, 'u2'))->toBe(1);

    // List user1 (has no items yet)
    $u1Items = $testEnv->db()->listItems(100, 0, null, null, 'u1');
    expect(count($u1Items))->toBe(0);

    // Users list with items count
    $usersList = $testEnv->db()->listUsers();
    expect(count($usersList))->toBe(2);
    $babaluEntry = array_values(array_filter($usersList, fn ($u) => $u['username'] === 'babalu'))[0];
    expect((int) $babaluEntry['items_count'])->toBe(1);

    // Update user token
    $testEnv->db()->updateUserToken('u2', 'fux_babalu_new_token');
    expect($testEnv->db()->findUserByToken('fux_babalu_456'))->toBeNull();
    expect($testEnv->db()->findUserByToken('fux_babalu_new_token'))->not->toBeNull();

    // Delete user (unlinks items)
    $testEnv->db()->deleteUser('u2');
    expect($testEnv->db()->findUserById('u2'))->toBeNull();
    $itemAfter = $testEnv->db()->findBySlug('babalu-secret');
    expect($itemAfter['user_id'])->toBeNull();
});
