<?php

namespace Tihloh\Prefab\Logs\DTOs;

use InvalidArgumentException;
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

    public const SCOPE_APP = 'APP';
    public const SCOPE_USER = 'USER';
    public const SCOPE_ORGANIZATION = 'ORGANIZATION';

    public const VISIBILITY_PUBLIC = 'PUBLIC';
    public const VISIBILITY_USER = 'USER';
    public const VISIBILITY_ORGANIZATION = 'ORGANIZATION';
    public const VISIBILITY_ADMIN = 'ADMIN';

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
        public string $scopeType = self::SCOPE_APP,
        public ?string $scopePath = null,
        public string $visibility = self::VISIBILITY_ADMIN,
    ) {
        $this->classification = strtoupper(trim($classification ?: 'AUDIT'));
        $this->level = self::normalizeLevel($level);
        $this->scopeType = self::normalizeScopeType($scopeType);
        $this->scopePath = self::normalizeScopePath($scopePath);
        $this->visibility = self::normalizeVisibility($visibility);
        $this->changes = self::normalizeChanges($changes);
        $this->metadata = LogPayloadCodec::sanitize($metadata);
        $this->details = LogPayloadCodec::sanitize($details);

        if ($this->scopeType !== self::SCOPE_APP && $this->scopePath === null) {
            throw new InvalidArgumentException('USER and ORGANIZATION log scopes require scope_path.');
        }
        if ($this->scopeType === self::SCOPE_APP) {
            $this->scopePath = null;
        }

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
            'scope_type' => $this->scopeType,
            'scope_path' => $this->scopePath,
            'visibility' => $this->visibility,
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
        $action = (string) ($data['action'] ?? '');
        $subjectType = (string) ($data['subject_type'] ?? $data['subjectType'] ?? $data['target_type'] ?? '');
        $subjectId = $data['subject_id'] ?? $data['subjectId'] ?? $data['target_id'] ?? null;
        $defaults = self::defaultsForAction($action, $subjectType, $subjectId);

        return new self(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            message: $data['message'] ?? null,
            actorId: $data['actor_id'] ?? $data['actorId'] ?? $data['user_id'] ?? null,
            changes: is_array($data['changes'] ?? null) ? $data['changes'] : [],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : (is_array($data['meta'] ?? null) ? $data['meta'] : []),
            ipAddress: $data['ip_address'] ?? $data['ipAddress'] ?? null,
            userAgent: $data['user_agent'] ?? $data['userAgent'] ?? null,
            occurredAt: $data['occurred_at'] ?? $data['occurredAt'] ?? null,
            classification: (string) ($data['classification'] ?? $data['class'] ?? $defaults['classification']),
            level: self::levelValue($data['level'] ?? $defaults['level']),
            module: isset($data['module']) ? (string) $data['module'] : $defaults['module'],
            status: self::statusValue($data['status'] ?? $defaults['status']),
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
            scopeType: (string) ($data['scope_type'] ?? $data['scopeType'] ?? $defaults['scope_type']),
            scopePath: isset($data['scope_path']) ? (string) $data['scope_path'] : (isset($data['scopePath']) ? (string) $data['scopePath'] : $defaults['scope_path']),
            visibility: (string) ($data['visibility'] ?? $defaults['visibility']),
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

    private static function defaultsForAction(string $action, string $subjectType = '', int|string|null $subjectId = null): array
    {
        $prefix = strtolower((string) strtok($action, '.'));
        $classification = match ($prefix) {
            'auth' => 'AUTH',
            'permission', 'security' => 'SECURITY',
            'system' => 'SYSTEM',
            'error' => 'ERROR',
            'integration', 'api', 'webhook', 'sms', 'mail' => 'INTEGRATION',
            'import', 'export', 'backup', 'restore', 'data' => 'DATA',
            default => 'AUDIT',
        };

        $level = match (true) {
            str_contains($action, 'critical'), str_contains($action, 'fatal') => self::CRITICAL,
            str_contains($action, 'error'), str_contains($action, 'exception') => self::ERROR,
            str_contains($action, 'failed'), str_contains($action, 'warning') => self::WARNING,
            str_contains($action, 'deleted'), str_contains($action, 'permission.') => self::NOTICE,
            default => self::INFO,
        };

        $status = match (true) {
            str_contains($action, 'login_failed'),
            str_contains($action, '.failed'),
            str_ends_with($action, '.error') => 0,
            default => 1,
        };

        $module = match ($prefix) {
            'user' => 'users',
            'permission' => 'permissions',
            default => $prefix !== '' ? $prefix : null,
        };

        $userScoped = strtolower($subjectType) === 'user' && $subjectId !== null;

        return [
            'classification' => $classification,
            'level' => $level,
            'module' => $module,
            'status' => $status,
            'scope_type' => $userScoped ? self::SCOPE_USER : self::SCOPE_APP,
            'scope_path' => $userScoped ? (string) $subjectId : null,
            'visibility' => $userScoped ? self::VISIBILITY_USER : self::VISIBILITY_ADMIN,
        ];
    }

    private static function normalizeLevel(int $level): int
    {
        $allowed = [self::DEBUG, self::INFO, self::NOTICE, self::WARNING, self::ERROR, self::CRITICAL];
        return in_array($level, $allowed, true) ? $level : self::INFO;
    }

    private static function normalizeScopeType(string $scopeType): string
    {
        $scopeType = strtoupper(trim($scopeType));
        return match ($scopeType) {
            self::SCOPE_USER, self::SCOPE_ORGANIZATION => $scopeType,
            default => self::SCOPE_APP,
        };
    }

    private static function normalizeScopePath(?string $scopePath): ?string
    {
        if ($scopePath === null) { return null; }
        $scopePath = trim($scopePath, " .\t\n\r\0\x0B");
        if ($scopePath === '') { return null; }
        if (!preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $scopePath)) {
            throw new InvalidArgumentException('scope_path must be a stable dot-separated key path.');
        }
        return $scopePath;
    }

    private static function normalizeVisibility(string $visibility): string
    {
        $visibility = strtoupper(trim($visibility));
        return match ($visibility) {
            self::VISIBILITY_PUBLIC,
            self::VISIBILITY_USER,
            self::VISIBILITY_ORGANIZATION => $visibility,
            default => self::VISIBILITY_ADMIN,
        };
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
            'scope_type' => $this->scopeType,
            'scope_path' => $this->scopePath,
            'visibility' => $this->visibility,
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
