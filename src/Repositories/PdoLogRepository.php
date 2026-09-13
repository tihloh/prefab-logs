<?php

namespace Tihloh\Prefab\Logs\Repositories;

use PDO;
use RuntimeException;
use Tihloh\Prefab\DatabaseInterface;
use Tihloh\Prefab\PdoDatabaseAdapter;
use Tihloh\Prefab\Logs\Contracts\LogRepositoryInterface;
use Tihloh\Prefab\Logs\DTOs\LogEntry;
use Tihloh\Prefab\Logs\Support\LogPayloadCodec;

final class PdoLogRepository implements LogRepositoryInterface
{
    private DatabaseInterface $database;

    public function __construct(
        DatabaseInterface|PDO $database,
        private string $table = 'prefab_logs',
    ) {
        $this->database = $database instanceof PDO ? new PdoDatabaseAdapter($database) : $database;
        $this->assertIdentifier($this->table);
        $this->ensureSchema();
        $this->upgradeSchema();
    }

    public function record(LogEntry $entry): int|string
    {
        $sql = "INSERT INTO {$this->table}
            (classification, level, module, action, subject_type, subject_id, actor_id, status, message, changes, metadata, details, ip_address, user_agent, occurred_at, created_at)
            VALUES
            (:classification, :level, :module, :action, :subject_type, :subject_id, :actor_id, :status, :message, :changes, :metadata, :details, :ip_address, :user_agent, :occurred_at, CURRENT_TIMESTAMP)";

        $this->database->statement($sql, [
            'classification' => $entry->classification,
            'level' => $entry->level,
            'module' => $entry->module,
            'action' => $entry->action,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId !== null ? (string) $entry->subjectId : null,
            'actor_id' => $entry->actorId !== null ? (string) $entry->actorId : null,
            'status' => $entry->status,
            'message' => $entry->message,
            // New entries keep verbose structured data in the compressed details payload.
            // These columns remain for backward compatibility with older installations.
            'changes' => '{}',
            'metadata' => '{}',
            'details' => LogPayloadCodec::encode($entry->details),
            'ip_address' => $entry->ipAddress,
            'user_agent' => $entry->userAgent,
            'occurred_at' => $entry->occurredAt,
        ]);

        return $this->database->lastInsertId();
    }

    public function find(int|string $id): ?array
    {
        $sql = $this->driver() === 'sqlsrv'
            ? "SELECT TOP 1 * FROM {$this->table} WHERE id = :id"
            : "SELECT * FROM {$this->table} WHERE id = :id LIMIT 1";
        $rows = $this->database->select($sql, ['id' => $id]);
        return isset($rows[0]) ? $this->decode($rows[0]) : null;
    }

    public function recent(int $limit = 100, int $offset = 0): array
    {
        return array_map(fn (array $row) => $this->decode($row), $this->database->select($this->pagedSql(null, $limit, $offset)));
    }

    public function forSubject(string $subjectType, int|string $subjectId, int $limit = 100): array
    {
        $rows = $this->database->select(
            $this->pagedSql('subject_type = :type AND subject_id = :id', $limit),
            ['type' => $subjectType, 'id' => (string) $subjectId],
        );
        return array_map(fn (array $row) => $this->decode($row), $rows);
    }

    public function forActor(int|string $actorId, int $limit = 100): array
    {
        $rows = $this->database->select(
            $this->pagedSql('actor_id = :actor_id', $limit),
            ['actor_id' => (string) $actorId],
        );
        return array_map(fn (array $row) => $this->decode($row), $rows);
    }

    private function pagedSql(?string $where, int $limit, int $offset = 0): string
    {
        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);
        $whereSql = $where ? " WHERE {$where}" : '';
        if ($this->driver() === 'sqlsrv') {
            return "SELECT * FROM {$this->table}{$whereSql} ORDER BY id DESC OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
        return "SELECT * FROM {$this->table}{$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
    }

    private function ensureSchema(): void
    {
        $sql = match ($this->driver()) {
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                classification TEXT NOT NULL DEFAULT 'AUDIT', level INTEGER NOT NULL DEFAULT 20, module TEXT NULL,
                action TEXT NOT NULL, subject_type TEXT NOT NULL, subject_id TEXT NULL, actor_id TEXT NULL,
                status INTEGER NULL DEFAULT 1, message TEXT NULL, changes TEXT NOT NULL DEFAULT '{}', metadata TEXT NOT NULL DEFAULT '{}',
                details BLOB NULL, ip_address TEXT NULL, user_agent TEXT NULL, occurred_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id BIGSERIAL PRIMARY KEY,
                classification VARCHAR(32) NOT NULL DEFAULT 'AUDIT', level SMALLINT NOT NULL DEFAULT 20, module VARCHAR(64) NULL,
                action VARCHAR(191) NOT NULL, subject_type VARCHAR(64) NOT NULL, subject_id VARCHAR(191) NULL, actor_id VARCHAR(191) NULL,
                status SMALLINT NULL DEFAULT 1, message TEXT NULL, changes JSONB NOT NULL DEFAULT '{}'::jsonb, metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                details BYTEA NULL, ip_address VARCHAR(64) NULL, user_agent TEXT NULL, occurred_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            'sqlsrv' => "IF OBJECT_ID(N'{$this->table}', N'U') IS NULL
                CREATE TABLE {$this->table} (
                    id BIGINT IDENTITY(1,1) PRIMARY KEY,
                    classification NVARCHAR(32) NOT NULL DEFAULT 'AUDIT', level SMALLINT NOT NULL DEFAULT 20, module NVARCHAR(64) NULL,
                    action NVARCHAR(191) NOT NULL, subject_type NVARCHAR(64) NOT NULL, subject_id NVARCHAR(191) NULL, actor_id NVARCHAR(191) NULL,
                    status SMALLINT NULL DEFAULT 1, message NVARCHAR(MAX) NULL, changes NVARCHAR(MAX) NOT NULL DEFAULT '{}', metadata NVARCHAR(MAX) NOT NULL DEFAULT '{}',
                    details VARBINARY(MAX) NULL, ip_address NVARCHAR(64) NULL, user_agent NVARCHAR(MAX) NULL, occurred_at DATETIME2 NULL,
                    created_at DATETIME2 NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
            'mysql' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                classification VARCHAR(32) NOT NULL DEFAULT 'AUDIT', level TINYINT UNSIGNED NOT NULL DEFAULT 20, module VARCHAR(64) NULL,
                action VARCHAR(191) NOT NULL, subject_type VARCHAR(64) NOT NULL, subject_id VARCHAR(191) NULL, actor_id VARCHAR(191) NULL,
                status TINYINT UNSIGNED NULL DEFAULT 1, message TEXT NULL, changes JSON NOT NULL, metadata JSON NOT NULL,
                details MEDIUMBLOB NULL, ip_address VARCHAR(64) NULL, user_agent TEXT NULL, occurred_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_prefab_logs_subject (subject_type, subject_id), KEY idx_prefab_logs_actor (actor_id),
                KEY idx_prefab_logs_action (action), KEY idx_prefab_logs_class_level (classification, level),
                KEY idx_prefab_logs_module (module), KEY idx_prefab_logs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            default => throw new RuntimeException("Unsupported log database driver '{$this->driver()}'."),
        };
        $this->database->statement($sql);
        $this->ensureIndexes();
    }

    private function upgradeSchema(): void
    {
        $columns = $this->columnNames();
        $definitions = match ($this->driver()) {
            'sqlite' => [
                'classification' => "TEXT NOT NULL DEFAULT 'AUDIT'", 'level' => 'INTEGER NOT NULL DEFAULT 20',
                'module' => 'TEXT NULL', 'status' => 'INTEGER NULL DEFAULT 1', 'details' => 'BLOB NULL',
            ],
            'pgsql' => [
                'classification' => "VARCHAR(32) NOT NULL DEFAULT 'AUDIT'", 'level' => 'SMALLINT NOT NULL DEFAULT 20',
                'module' => 'VARCHAR(64) NULL', 'status' => 'SMALLINT NULL DEFAULT 1', 'details' => 'BYTEA NULL',
            ],
            'sqlsrv' => [
                'classification' => "NVARCHAR(32) NOT NULL DEFAULT 'AUDIT'", 'level' => 'SMALLINT NOT NULL DEFAULT 20',
                'module' => 'NVARCHAR(64) NULL', 'status' => 'SMALLINT NULL DEFAULT 1', 'details' => 'VARBINARY(MAX) NULL',
            ],
            'mysql' => [
                'classification' => "VARCHAR(32) NOT NULL DEFAULT 'AUDIT'", 'level' => 'TINYINT UNSIGNED NOT NULL DEFAULT 20',
                'module' => 'VARCHAR(64) NULL', 'status' => 'TINYINT UNSIGNED NULL DEFAULT 1', 'details' => 'MEDIUMBLOB NULL',
            ],
            default => [],
        };

        foreach ($definitions as $name => $definition) {
            if (!in_array(strtolower($name), $columns, true)) {
                $this->database->statement("ALTER TABLE {$this->table} ADD COLUMN {$name} {$definition}");
            }
        }
    }

    private function columnNames(): array
    {
        $rows = match ($this->driver()) {
            'sqlite' => $this->database->select("PRAGMA table_info({$this->table})"),
            'pgsql' => $this->database->select(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table',
                ['table' => $this->table],
            ),
            'sqlsrv' => $this->database->select(
                'SELECT COLUMN_NAME AS column_name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :table',
                ['table' => $this->table],
            ),
            'mysql' => $this->database->select("SHOW COLUMNS FROM {$this->table}"),
            default => [],
        };

        return array_values(array_filter(array_map(static function (array $row): ?string {
            $name = $row['name'] ?? $row['column_name'] ?? $row['Field'] ?? $row['field'] ?? null;
            return $name !== null ? strtolower((string) $name) : null;
        }, $rows)));
    }

    private function ensureIndexes(): void
    {
        if (in_array($this->driver(), ['sqlite', 'pgsql'], true)) {
            foreach ([
                'subject' => '(subject_type, subject_id)', 'actor' => '(actor_id)', 'action' => '(action)',
                'class_level' => '(classification, level)', 'module' => '(module)', 'created' => '(created_at)',
            ] as $name => $columns) {
                $this->database->statement("CREATE INDEX IF NOT EXISTS idx_{$this->table}_{$name} ON {$this->table}{$columns}");
            }
        }
    }

    private function decode(array $row): array
    {
        $details = LogPayloadCodec::decode($row['details'] ?? null);
        $changes = $this->decodeJson($row['changes'] ?? null);
        $metadata = $this->decodeJson($row['metadata'] ?? null);

        if ($changes === [] && is_array($details['changes'] ?? null)) { $changes = $details['changes']; }
        if ($metadata === [] && is_array($details['meta'] ?? null)) { $metadata = $details['meta']; }
        if (($row['message'] ?? null) === null && isset($details['message'])) { $row['message'] = (string) $details['message']; }

        $row['classification'] = strtoupper((string) ($row['classification'] ?? 'AUDIT'));
        $row['level'] = (int) ($row['level'] ?? LogEntry::INFO);
        $row['level_name'] = LogEntry::levelName($row['level']);
        $row['module'] = $row['module'] ?? null;
        $row['status'] = isset($row['status']) ? (int) $row['status'] : null;
        $row['changes'] = $changes;
        $row['metadata'] = $metadata;
        $row['details'] = $details;
        return $row;
    }

    private function decodeJson(mixed $value): array
    {
        if ($value === null || $value === '') { return []; }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function driver(): string
    {
        return $this->database->driver();
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Unsafe SQL identifier: {$identifier}");
        }
    }
}
