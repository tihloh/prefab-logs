<?php

namespace Tihloh\Prefab\Logs\DTOs;

use Tihloh\Prefab\PrefabRuntime;
use Tihloh\Prefab\Logs\Support\LogPayloadCodec;

final class LogEntry
{
    public const DEBUG = 10;
    public const INFO = 20;
    public const NOTICE = 30;
    public const WARNING = 40;
    public const ERROR = 50;
    public const CRITICAL = 60;

    public function __construct(
        public string $action,
        public string $subjectType,
        public int|string|null $subjectId = null,
        public ?string $message = null,
        public int|string|null $actorId = null,
        public array $changes = [],
        public array $metadata = [],
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $occurredAt = null,
        public string $classification = 'AUDIT',
        public int $level = self::INFO,
        public ?string $module = null,
        public ?int $status = 1,
        public array $details = [],
    ) {
        $this->classification = strtoupper(trim($classification ?: 'AUDIT'));
        $this->level = self::normalizeLevel($level);
        $this->changes = self::normalizeChanges($changes);
        $this->metadata = LogPayloadCodec::sanitize($metadata);
        $this->details = LogPayloadCodec::sanitize($details);

        if ($this->details === []) {
            $this->details = array_filter([
                'message' => $this->message,
                'changes' => $this->changes,
                'meta' => $this->metadata,
            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        }

        PrefabRuntime::traceStart('logs', 'entry', [
            'classification' => $this->classification,
            'level' => $this->level,
            'module' => $this->module,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);
        PrefabRuntime::traceEnd([
            'actor_id' => $actorId,
            'changes' => count($this->changes),
            'status' => $this->status,
        ]);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            action: (string) ($data['action'] ?? ''),
            subjectType: (string) ($data['subject_type'] ?? $data['subjectType'] ?? $data['target_type'] ?? ''),
            subjectId: $data['subject_id'] ?? $data['subjectId'] ?? $data['target_id'] ?? null,
            message: $data['message'] ?? null,
            actorId: $data['actor_id'] ?? $data['actorId'] ?? $data['user_id'] ?? null,
            changes: is_array($data['changes'] ?? null) ? $data['changes'] : [],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : (is_array($data['meta'] ?? null) ? $data['meta'] : []),
            ipAddress: $data['ip_address'] ?? $data['ipAddress'] ?? null,
            userAgent: $data['user_agent'] ?? $data['userAgent'] ?? null,
            occurredAt: $data['occurred_at'] ?? $data['occurredAt'] ?? null,
            classification: (string) ($data['classification'] ?? $data['class'] ?? 'AUDIT'),
            level: self::levelValue($data['level'] ?? self::INFO),
            module: isset($data['module']) ? (string) $data['module'] : null,
            status: self::statusValue($data['status'] ?? 1),
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
        );
    }

    public static function changes(array $before, array $now, array $ignore = []): array
    {
        $changes = [];
        $fields = array_unique([...array_keys($before), ...array_keys($now)]);

        foreach ($fields as $field) {
            if (in_array($field, $ignore, true)) { continue; }
            $old = $before[$field] ?? null;
            $new = $now[$field] ?? null;
            if (self::same($old, $new)) { continue; }
            $changes[$field] = ['old' => $old, 'new' => $new];
        }

        return $changes;
    }

    public static function normalizeChanges(array $changes): array
    {
        $normalized = [];
        foreach ($changes as $field => $change) {
            if (!is_array($change)) { continue; }
            $old = array_key_exists('old', $change) ? $change['old'] : ($change['before'] ?? null);
            $new = array_key_exists('new', $change) ? $change['new'] : ($change['now'] ?? null);
            if (self::same($old, $new)) { continue; }
            $normalized[$field] = LogPayloadCodec::sanitize(['old' => $old, 'new' => $new], (string) $field);
        }
        return $normalized;
    }

    public static function levelValue(mixed $level): int
    {
        if (is_numeric($level)) { return self::normalizeLevel((int) $level); }
        return match (strtoupper(trim((string) $level))) {
            'DEBUG' => self::DEBUG,
            'NOTICE' => self::NOTICE,
            'WARNING', 'WARN' => self::WARNING,
            'ERROR' => self::ERROR,
            'CRITICAL', 'FATAL' => self::CRITICAL,
            default => self::INFO,
        };
    }

    public static function levelName(int $level): string
    {
        return match (self::normalizeLevel($level)) {
            self::DEBUG => 'DEBUG',
            self::NOTICE => 'NOTICE',
            self::WARNING => 'WARNING',
            self::ERROR => 'ERROR',
            self::CRITICAL => 'CRITICAL',
            default => 'INFO',
        };
    }

    private static function normalizeLevel(int $level): int
    {
        $allowed = [self::DEBUG, self::INFO, self::NOTICE, self::WARNING, self::ERROR, self::CRITICAL];
        return in_array($level, $allowed, true) ? $level : self::INFO;
    }

    private static function statusValue(mixed $status): ?int
    {
        if ($status === null || $status === '') { return null; }
        if (is_bool($status)) { return $status ? 1 : 0; }
        if (is_numeric($status)) { return ((int) $status) > 0 ? 1 : 0; }
        return match (strtoupper(trim((string) $status))) {
            'SUCCESS', 'OK', 'PASSED' => 1,
            'FAILED', 'FAIL', 'ERROR' => 0,
            default => null,
        };
    }

    private static function same(mixed $old, mixed $new): bool
    {
        if (is_array($old) || is_array($new)) { return json_encode($old) === json_encode($new); }
        return $old === $new;
    }

    public function toArray(): array
    {
        return [
            'classification' => $this->classification,
            'level' => $this->level,
            'level_name' => self::levelName($this->level),
            'module' => $this->module,
            'action' => $this->action,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'status' => $this->status,
            'message' => $this->message,
            'actor_id' => $this->actorId,
            'changes' => $this->changes,
            'metadata' => $this->metadata,
            'details' => $this->details,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
