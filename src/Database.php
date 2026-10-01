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
                max_downloads INTEGER NULL,
                hash TEXT NULL,
                expires_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                views INTEGER DEFAULT 0,
                user_id TEXT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_items_slug ON items(slug);
            CREATE INDEX IF NOT EXISTS idx_items_expires ON items(expires_at);
            CREATE INDEX IF NOT EXISTS idx_items_type ON items(type);
            CREATE INDEX IF NOT EXISTS idx_items_created ON items(created_at);

            CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                username TEXT NOT NULL UNIQUE,
                token TEXT NOT NULL UNIQUE,
                role TEXT NOT NULL DEFAULT "member",
                created_at INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_users_token ON users(token);
            CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
        ');

        // Check if user_id, max_downloads, hash columns exist on pre-existing items table
        $columns = $this->pdo->query('PRAGMA table_info(items)')->fetchAll();
        $hasUserId = false;
        $hasMaxDownloads = false;
        $hasHash = false;
        foreach ($columns as $col) {
            $colName = $col['name'] ?? '';
            if ($colName === 'user_id') {
                $hasUserId = true;
            } elseif ($colName === 'max_downloads') {
                $hasMaxDownloads = true;
            } elseif ($colName === 'hash') {
                $hasHash = true;
            }
        }
        if (! $hasUserId) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN user_id TEXT NULL;');
        }
        if (! $hasMaxDownloads) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN max_downloads INTEGER NULL DEFAULT NULL;');
        }
        if (! $hasHash) {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN hash TEXT NULL DEFAULT NULL;');
        }
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_user_id ON items(user_id);');
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
     *   max_downloads?: ?int,
     *   hash?: ?string,
     *   expires_at?: ?int,
     *   created_at?: int,
     *   views?: int,
     *   user_id?: ?string
     * } $data
     */
    public function createItem(array $data): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO items (
                id, slug, original_name, type, target_url, file_path,
                mime_type, size, x_token, is_oneshot, max_downloads, hash, expires_at, created_at, views, user_id
            ) VALUES (
                :id, :slug, :original_name, :type, :target_url, :file_path,
                :mime_type, :size, :x_token, :is_oneshot, :max_downloads, :hash, :expires_at, :created_at, :views, :user_id
            )
        ');

        return $stmt->execute([
            ':id' => $data['id'] ?? bin2hex(random_bytes(8)),
            ':slug' => $data['slug'],
            ':original_name' => $data['original_name'] ?? null,
            ':type' => $data['type'],
            ':target_url' => $data['target_url'] ?? null,
            ':file_path' => $data['file_path'] ?? null,
            ':mime_type' => $data['mime_type'] ?? 'application/octet-stream',
            ':size' => $data['size'] ?? 0,
            ':x_token' => $data['x_token'],
            ':is_oneshot' => $data['is_oneshot'] ?? 0,
            ':max_downloads' => $data['max_downloads'] ?? null,
            ':hash' => $data['hash'] ?? null,
            ':expires_at' => $data['expires_at'] ?? null,
            ':created_at' => $data['created_at'] ?? time(),
            ':views' => $data['views'] ?? 0,
            ':user_id' => $data['user_id'] ?? null,
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
    public function listItems(
        int $limit = 100,
        int $offset = 0,
        ?string $search = null,
        ?string $type = null,
        ?string $userId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $sql = '
            SELECT items.id, items.slug, items.original_name, items.type, items.target_url, items.file_path,
                   items.mime_type, items.size, items.is_oneshot, items.expires_at, items.created_at, items.views,
                   items.user_id, users.username as author
            FROM items
            LEFT JOIN users ON items.user_id = users.id
            WHERE 1=1
        ';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (items.slug LIKE :search OR items.original_name LIKE :search OR items.target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url', 'doc', 'gist', 'git'], true)) {
            $sql .= ' AND items.type = :type';
            $params[':type'] = $type;
        }

        if ($userId === 'none' || $userId === 'anonymous') {
            $sql .= ' AND items.user_id IS NULL';
        } elseif ($userId !== null && trim($userId) !== '') {
            $sql .= ' AND items.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $tsFrom = is_numeric($dateFrom) ? (int) $dateFrom : strtotime(trim($dateFrom).' 00:00:00');
            if ($tsFrom !== false) {
                $sql .= ' AND items.created_at >= :date_from';
                $params[':date_from'] = $tsFrom;
            }
        }

        if ($dateTo !== null && trim($dateTo) !== '') {
            $tsTo = is_numeric($dateTo) ? (int) $dateTo : strtotime(trim($dateTo).' 23:59:59');
            if ($tsTo !== false) {
                $sql .= ' AND items.created_at <= :date_to';
                $params[':date_to'] = $tsTo;
            }
        }

        $sql .= ' ORDER BY items.created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countItems(
        ?string $search = null,
        ?string $type = null,
        ?string $userId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        $sql = 'SELECT COUNT(*) FROM items WHERE 1=1';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (slug LIKE :search OR original_name LIKE :search OR target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url', 'doc', 'gist', 'git'], true)) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        if ($userId === 'none' || $userId === 'anonymous') {
            $sql .= ' AND user_id IS NULL';
        } elseif ($userId !== null && trim($userId) !== '') {
            $sql .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $tsFrom = is_numeric($dateFrom) ? (int) $dateFrom : strtotime(trim($dateFrom).' 00:00:00');
            if ($tsFrom !== false) {
                $sql .= ' AND created_at >= :date_from';
                $params[':date_from'] = $tsFrom;
            }
        }

        if ($dateTo !== null && trim($dateTo) !== '') {
            $tsTo = is_numeric($dateTo) ? (int) $dateTo : strtotime(trim($dateTo).' 23:59:59');
            if ($tsTo !== false) {
                $sql .= ' AND created_at <= :date_to';
                $params[':date_to'] = $tsTo;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<array{id: int|string, slug: string, file_path: ?string, type: string}>
     */
    public function getItemsForBulkDelete(
        ?string $search = null,
        ?string $type = null,
        ?string $userId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $sql = 'SELECT id, slug, file_path, type FROM items WHERE 1=1';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (slug LIKE :search OR original_name LIKE :search OR target_url LIKE :search)';
            $params[':search'] = '%'.trim($search).'%';
        }

        if ($type !== null && in_array($type, ['file', 'url', 'doc', 'gist', 'git'], true)) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        if ($userId === 'none' || $userId === 'anonymous') {
            $sql .= ' AND user_id IS NULL';
        } elseif ($userId !== null && trim($userId) !== '') {
            $sql .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $tsFrom = is_numeric($dateFrom) ? (int) $dateFrom : strtotime(trim($dateFrom).' 00:00:00');
            if ($tsFrom !== false) {
                $sql .= ' AND created_at >= :date_from';
                $params[':date_from'] = $tsFrom;
            }
        }

        if ($dateTo !== null && trim($dateTo) !== '') {
            $tsTo = is_numeric($dateTo) ? (int) $dateTo : strtotime(trim($dateTo).' 23:59:59');
            if ($tsTo !== false) {
                $sql .= ' AND created_at <= :date_to';
                $params[':date_to'] = $tsTo;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param array<int|string> $ids
     */
    public function deleteItemsByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM items WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($ids));

        return $stmt->rowCount();
    }

    public function updateType(string $slug, string $type): bool
    {
        $stmt = $this->pdo->prepare('UPDATE items SET type = :type WHERE slug = :slug');

        return $stmt->execute([':type' => $type, ':slug' => $slug]);
    }

    public function updateContent(string $slug, int $newSize): bool
    {
        $stmt = $this->pdo->prepare('UPDATE items SET size = :size WHERE slug = :slug');

        return $stmt->execute([':size' => $newSize, ':slug' => $slug]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function updateItemParameters(string $slug, array $params): bool
    {
        $allowed = ['expires_at', 'max_downloads', 'is_oneshot', 'original_name', 'target_url', 'hash', 'mime_type'];
        $sets = [];
        $binds = [':slug' => $slug];
        foreach ($params as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[] = "{$k} = :{$k}";
                $binds[":{$k}"] = $v;
            }
        }
        if (empty($sets)) {
            return false;
        }
        $sql = 'UPDATE items SET '.implode(', ', $sets).' WHERE slug = :slug';
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($binds);
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
     * @return array{total_items: int, total_files: int, total_urls: int, total_docs: int, total_gists: int, total_gits: int, total_bytes: int, total_views: int}
     */
    public function getStats(?string $userId = null): array
    {
        $sql = '
            SELECT
                COUNT(*) as total_items,
                SUM(CASE WHEN type = "file" THEN 1 ELSE 0 END) as total_files,
                SUM(CASE WHEN type = "url" THEN 1 ELSE 0 END) as total_urls,
                SUM(CASE WHEN type = "doc" THEN 1 ELSE 0 END) as total_docs,
                SUM(CASE WHEN type = "gist" THEN 1 ELSE 0 END) as total_gists,
                SUM(CASE WHEN type = "git" THEN 1 ELSE 0 END) as total_gits,
                SUM(size) as total_bytes,
                SUM(views) as total_views
            FROM items
        ';
        $params = [];
        if ($userId !== null && trim($userId) !== '') {
            $sql .= ' WHERE user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch();

        return [
            'total_items' => (int) ($res['total_items'] ?? 0),
            'total_files' => (int) ($res['total_files'] ?? 0),
            'total_urls' => (int) ($res['total_urls'] ?? 0),
            'total_docs' => (int) ($res['total_docs'] ?? 0),
            'total_gists' => (int) ($res['total_gists'] ?? 0),
            'total_gits' => (int) ($res['total_gits'] ?? 0),
            'total_bytes' => (int) ($res['total_bytes'] ?? 0),
            'total_views' => (int) ($res['total_views'] ?? 0),
        ];
    }

    /**
     * @param  array{id: string, username: string, token: string, role?: string, created_at?: int}  $data
     */
    public function createUser(array $data): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO users (id, username, token, role, created_at)
            VALUES (:id, :username, :token, :role, :created_at)
        ');

        return $stmt->execute([
            ':id' => $data['id'],
            ':username' => trim($data['username']),
            ':token' => trim($data['token']),
            ':role' => $data['role'] ?? 'member',
            ':created_at' => $data['created_at'] ?? time(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => trim($token)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username COLLATE NOCASE LIMIT 1');
        $stmt->execute([':username' => trim($username)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listUsers(): array
    {
        $stmt = $this->pdo->query('
            SELECT users.id, users.username, users.token, users.role, users.created_at,
                   COUNT(items.id) as items_count
            FROM users
            LEFT JOIN items ON users.id = items.user_id
            GROUP BY users.id
            ORDER BY users.created_at ASC
        ');

        return $stmt->fetchAll();
    }

    public function deleteUser(string $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE items SET user_id = NULL WHERE user_id = :id');
        $stmt->execute([':id' => $id]);

        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');

        return $stmt->execute([':id' => $id]);
    }

    public function updateUserToken(string $id, string $newToken): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET token = :token WHERE id = :id');

        return $stmt->execute([
            ':token' => trim($newToken),
            ':id' => $id,
        ]);
    }
}
