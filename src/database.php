<?php

declare(strict_types=1);

/**
 * TechGrow Ltd — database layer.
 *
 * Provides a single PDO connection to a local SQLite database that lives
 * OUTSIDE the public web root (../storage). The database file and its
 * schema are created automatically on first use.
 */

/**
 * Absolute path to the SQLite database file.
 * Kept in /storage, one level above /public, so it is never web-served.
 */
function db_path(): string
{
    return dirname(__DIR__) . '/storage/subscribers.sqlite';
}

/**
 * Return a shared PDO connection, creating the storage directory,
 * database file and schema on first call.
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = db_path();
    $dir  = dirname($path);

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create storage directory.');
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Pragmas: better concurrency + enforce foreign keys.
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    init_schema($pdo);

    // Lock the database file down to the owner/group where supported.
    if (is_file($path)) {
        @chmod($path, 0660);
    }

    return $pdo;
}

/**
 * Create the subscribers table and indexes if they do not exist.
 */
function init_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS subscribers (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            email       TEXT    NOT NULL,
            message     TEXT,
            ip_address  TEXT,
            user_agent  TEXT,
            created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
        )'
    );

    // Case-insensitive unique index prevents duplicate emails
    // (e.g. Foo@x.com and foo@x.com are treated as the same).
    $pdo->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_subscribers_email
            ON subscribers (email COLLATE NOCASE)'
    );
}

/**
 * Check whether an email is already subscribed.
 */
function email_exists(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM subscribers WHERE email = :email COLLATE NOCASE LIMIT 1'
    );
    $stmt->execute([':email' => $email]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Insert a new subscriber using a prepared statement.
 * Returns true on success, false if the email already exists.
 */
function add_subscriber(
    PDO $pdo,
    string $name,
    string $email,
    string $message,
    string $ip,
    string $userAgent
): bool {
    if (email_exists($pdo, $email)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO subscribers (name, email, message, ip_address, user_agent)
         VALUES (:name, :email, :message, :ip, :ua)'
    );

    try {
        $stmt->execute([
            ':name'    => $name,
            ':email'   => $email,
            ':message' => $message !== '' ? $message : null,
            ':ip'      => $ip !== '' ? $ip : null,
            ':ua'      => $userAgent !== '' ? $userAgent : null,
        ]);
    } catch (PDOException $e) {
        // 23000 = integrity constraint violation (unique email race).
        if ($e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }

    return true;
}

/* ----------------------------------------------------------------------
 | Admin / reporting queries
 * ------------------------------------------------------------------- */

/**
 * Escape LIKE wildcards so a search term is matched literally.
 * Used with `ESCAPE '\'` in the queries below.
 */
function escape_like(string $term): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
}

/**
 * Count subscribers, optionally filtered by a name/email search term.
 */
function count_subscribers(PDO $pdo, string $search = ''): int
{
    if ($search === '') {
        return (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM subscribers
         WHERE name LIKE :q ESCAPE '\\' OR email LIKE :q ESCAPE '\\'"
    );
    $stmt->bindValue(':q', '%' . escape_like($search) . '%');
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/**
 * Count subscribers created within the last N days (UTC).
 */
function count_subscribers_last_days(PDO $pdo, int $days): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM subscribers WHERE created_at >= datetime('now', :modifier)"
    );
    $stmt->execute([':modifier' => '-' . max(0, $days) . ' days']);

    return (int) $stmt->fetchColumn();
}

/**
 * Count subscribers created today (UTC).
 */
function count_subscribers_today(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM subscribers WHERE created_at >= date('now')"
    )->fetchColumn();
}

/**
 * Fetch a page of subscribers (newest first), optionally filtered.
 */
function get_subscribers(PDO $pdo, string $search, int $limit, int $offset): array
{
    $limit  = max(1, $limit);
    $offset = max(0, $offset);

    if ($search === '') {
        $stmt = $pdo->prepare(
            'SELECT * FROM subscribers ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM subscribers
             WHERE name LIKE :q ESCAPE '\\' OR email LIKE :q ESCAPE '\\'
             ORDER BY id DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':q', '%' . escape_like($search) . '%');
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Delete a subscriber by id. Returns true if a row was removed.
 */
function delete_subscriber(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM subscribers WHERE id = :id');
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}
