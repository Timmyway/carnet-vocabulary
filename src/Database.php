<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create data directory: $dir");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        self::migrate($pdo);
        return $pdo;
    }

    /** Versioned migrations, tracked with SQLite's PRAGMA user_version. Append new ones, never edit old ones. */
    private static function migrate(PDO $pdo): void
    {
        $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        foreach (self::migrations() as $version => $sql) {
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            $pdo->exec($sql);
            $pdo->exec('PRAGMA user_version = ' . (int) $version);
            $pdo->commit();
        }
    }

    private static function migrations(): array
    {
        return [
            1 => <<<'SQL'
            CREATE TABLE words (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                term TEXT NOT NULL,
                translation TEXT NOT NULL,
                example TEXT,
                tag TEXT,
                box INTEGER NOT NULL DEFAULT 1,
                next_review_at TEXT NOT NULL,
                correct_count INTEGER NOT NULL DEFAULT 0,
                wrong_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE INDEX idx_words_due ON words(next_review_at);
            CREATE INDEX idx_words_tag ON words(tag);
            SQL,
            2 => <<<'SQL'
            CREATE INDEX idx_words_term ON words(term COLLATE NOCASE);
            CREATE INDEX idx_words_created ON words(created_at, id);
            SQL,
        ];
    }
}
