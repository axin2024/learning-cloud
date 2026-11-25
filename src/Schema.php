<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;
use RuntimeException;

final class Schema
{
    public function __construct(private readonly Store $store)
    {
    }

    public function migrate(): array
    {
        $database = $this->store->one('SELECT DATABASE() AS name')['name'] ?? null;
        if (!$database) {
            throw new RuntimeException('The connection must select a database.');
        }
        $lock = hash('sha256', 'learning-cloud-migrations:' . $database);
        if ((int) $this->store->one('SELECT GET_LOCK(?, 10) AS acquired', [$lock])['acquired'] !== 1) {
            throw new RuntimeException('Could not obtain the migration lock.');
        }
        try {
            $this->store->execute(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    applied_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                ) ENGINE=InnoDB'
            );
            $migrations = $this->migrations();
            $existing = $this->existing();
            $this->verifyHistory($migrations, $existing);
            $applied = [];
            foreach ($migrations as $version => $checksum) {
                if (isset($existing[$version])) {
                    continue;
                }
                $sql = file_get_contents($this->directory() . '/' . $version . '.sql');
                if ($sql === false || hash('sha256', $sql) !== $checksum) {
                    throw new RuntimeException("Migration could not be read or changed during execution: $version");
                }
                foreach (preg_split('/;[ \t]*(?:\r?\n|$)/', trim($sql), -1, PREG_SPLIT_NO_EMPTY) as $statement) {
                    $this->store->execute(trim($statement));
                }
                $this->store->execute('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)', [$version, $checksum]);
                $applied[] = $version;
            }
            return $applied;
        } finally {
            $this->store->one('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function ready(): bool
    {
        try {
            return $this->migrations() === $this->existing();
        } catch (PDOException) {
            return false;
        }
    }

    private function migrations(): array
    {
        $migrations = [];
        foreach (glob($this->directory() . '/*.sql') as $path) {
            $migrations[basename($path, '.sql')] = hash_file('sha256', $path);
        }
        return $migrations;
    }

    private function existing(): array
    {
        $rows = $this->store->all('SELECT version, checksum FROM schema_migrations ORDER BY version');
        return array_column($rows, 'checksum', 'version');
    }

    private function verifyHistory(array $migrations, array $existing): void
    {
        if (array_slice($migrations, 0, count($existing), true) !== $existing) {
            throw new RuntimeException('Applied migration history differs from this installation.');
        }
    }

    private function directory(): string
    {
        return dirname(__DIR__) . '/database/migrations';
    }
}
