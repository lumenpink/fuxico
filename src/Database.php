<?php

declare(strict_types=1);

namespace Fuxico;

use PDO;

class Database
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:'.$dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // WAL mode for concurrency and performance
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA synchronous = NORMAL;');

        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS items (
                id TEXT PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                original_name TEXT,
                type TEXT NOT NULL,
                target_url TEXT,
                file_path TEXT,
                mime_type TEXT,
                size INTEGER DEFAULT 0,
                x_token TEXT NOT NULL,
                is_oneshot INTEGER DEFAULT 0,
                expires_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                views INTEGER DEFAULT 0
            );

            CREATE INDEX IF NOT EXISTS idx_items_slug ON items(slug);
            CREATE INDEX IF NOT EXISTS idx_items_expires ON items(expires_at);
            CREATE INDEX IF NOT EXISTS idx_items_type ON items(type);
            CREATE INDEX IF NOT EXISTS idx_items_created ON items(created_at);
        ');
    }

    public function isSlugTaken(string $slug): bool
    {
        $reserved = ['api', 'list', 'painel', 'index.php', 'favicon.ico', 'robots.txt'];
        if (in_array(strtolower($slug), $reserved, true)) {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM items WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{
     *   id: string,
     *   slug: string,
     *   original_name?: ?string,
     *   type: string,
     *   target_url?: ?string,
     *   file_path?: ?string,
     *   mime_type?: ?string,
     *   size?: int,
     *   x_token: string,
     *   is_oneshot?: int,
     *   expires_at?: ?int,
     *   created_at?: int,
     *   views?: int
     * } $data
     */
    public function createItem(array $data): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO items (
                id, slug, original_name, type, target_url, file_path,
                mime_type, size, x_token, is_oneshot, expires_at, created_at, views
            ) VALUES (
                :id, :slug, :original_name, :type, :target_url, :file_path,
                :mime_type, :size, :x_token, :is_oneshot, :expires_at, :created_at, :views
            )
        ');

        return $stmt->execute([
            ':id' => $data['id'],
            ':slug' => $data['slug'],
            ':original_name' => $data['original_name'] ?? null,
            ':type' => $data['type'],
            ':target_url' => $data['target_url'] ?? null,
            ':file_path' => $data['file_path'] ?? null,
            ':mime_type' => $data['mime_type'] ?? 'application/octet-stream',
            ':size' => $data['size'] ?? 0,
            ':x_token' => $data['x_token'],
            ':is_oneshot' => $data['is_oneshot'] ?? 0,
            ':expires_at' => $data['expires_at'] ?? null,
            ':created_at' => $data['created_at'] ?? time(),
            ':views' => $data['views'] ?? 0,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $slug, string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE slug = :slug AND x_token = :token LIMIT 1');
        $stmt->execute([':slug' => $slug, ':token' => $token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deleteBySlug(string $slug): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM items WHERE slug = :slug');

        return $stmt->execute([':slug' => $slug]);
    }

    public function updateExpires(string $slug, ?int $expiresAt): bool
    {
        $stmt = $this->pdo->prepare('UPDATE items SET expires_at = :expires_at WHERE slug = :slug');

        return $stmt->execute([
            ':slug' => $slug,
            ':expires_at' => $expiresAt,
        ]);
    }

    public function incrementViews(string $slug): void
    {
        $stmt = $this->pdo->prepare('UPDATE items SET views = views + 1 WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listItems(int $limit = 100, int $offset = 0, ?string $search = null, ?string $type = null): array
    {
        $sql = 'SELECT id, slug, original_name, type, target_url, file_path, mime_type, size, is_oneshot, expires_at, created_at, views FROM items WHERE 1=1';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (slug LIKE :search OR original_name LIKE :search OR target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url'], true)) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countItems(?string $search = null, ?string $type = null): int
    {
        $sql = 'SELECT COUNT(*) FROM items WHERE 1=1';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (slug LIKE :search OR original_name LIKE :search OR target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url'], true)) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getExpiredItems(int $now): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE expires_at IS NOT NULL AND expires_at <= :now');
        $stmt->execute([':now' => $now]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{total_items: int, total_files: int, total_urls: int, total_bytes: int, total_views: int}
     */
    public function getStats(): array
    {
        $stmt = $this->pdo->query('
            SELECT
                COUNT(*) as total_items,
                SUM(CASE WHEN type = "file" THEN 1 ELSE 0 END) as total_files,
                SUM(CASE WHEN type = "url" THEN 1 ELSE 0 END) as total_urls,
                SUM(size) as total_bytes,
                SUM(views) as total_views
            FROM items
        ');
        $res = $stmt->fetch();

        return [
            'total_items' => (int) ($res['total_items'] ?? 0),
            'total_files' => (int) ($res['total_files'] ?? 0),
            'total_urls' => (int) ($res['total_urls'] ?? 0),
            'total_bytes' => (int) ($res['total_bytes'] ?? 0),
            'total_views' => (int) ($res['total_views'] ?? 0),
        ];
    }
}
