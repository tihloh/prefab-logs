# Universal Prefab Logs

Prefab Logs accepts application-specific events while keeping searchable metadata consistent.

## Searchable fields

- `classification`: `AUDIT`, `AUTH`, `SECURITY`, `SYSTEM`, `ERROR`, `INTEGRATION`, or `DATA` by convention. Custom classifications remain allowed.
- `level`: `10 DEBUG`, `20 INFO`, `30 NOTICE`, `40 WARNING`, `50 ERROR`, `60 CRITICAL`.
- `module`: source module such as `auth`, `users`, or `permissions`.
- `action`: stable event name such as `user.updated`.
- `scope_type`: `APP`, `USER`, or `ORGANIZATION`.
- `scope_path`: stable user key or dot-separated organization path. `APP` scope keeps this null.
- `visibility`: `PUBLIC`, `USER`, `ORGANIZATION`, or `ADMIN`.
- `subject_type` / `subject_id`: affected object.
- `actor_id`: actor when known. The actor is separate from the log scope/owner.
- `status`: `1` success, `0` failed, `null` not applicable.
- `message`: short human-readable summary.
- request context such as IP address and user agent.

## Scope and visibility

Scope describes where an event belongs. Visibility describes who may be allowed to see it. Authorization remains the application's/permissions module's responsibility.

```php
// Public application event.
[
    'scope_type' => 'APP',
    'visibility' => 'PUBLIC',
]

// User 25's activity, even if another administrator performed the action.
[
    'actor_id' => 1,
    'scope_type' => 'USER',
    'scope_path' => '25',
    'visibility' => 'USER',
]

// Organization hierarchy event.
[
    'scope_type' => 'ORGANIZATION',
    'scope_path' => 'province.budget.fund-control',
    'visibility' => 'ORGANIZATION',
]
```

Organization paths use stable keys rather than display names. A manager scoped to `province.budget` can query both that exact scope and descendants such as `province.budget.appropriations` and `province.budget.fund-control`.

Wildcards are not stored in log records. Descendant access is implemented as exact-path-or-prefix matching.

Safe defaults:

- user-subject events infer `USER` scope, the user's subject id as `scope_path`, and `USER` visibility;
- all other events default to `APP` scope with `ADMIN` visibility;
- public access must be explicit with `visibility => PUBLIC`.

## Feeds

The built-in PDO repository supports efficient feeds without loading and filtering the whole log table:

```php
$logs->publicLogs();
$logs->app('ADMIN');
$logs->forUser(25, 'USER');
$logs->forOrganization('province.budget', includeDescendants: true, visibility: 'ORGANIZATION');
```

`recent()` remains the unrestricted repository view and should only be exposed after application authorization.

## Automatic module logging

Prefab modules should automatically emit only meaningful audit, security, integration, destructive, state-changing, or important failure events. Routine internal method calls should not become permanent logs.

Every module that supports automatic logging should accept this standard configuration, enabled by default:

```php
'modules' => [
    'auth' => [
        'logging' => ['enabled' => true],
    ],
]
```

Setting `logging.enabled` to `false` disables that module's automatic log emission. Logging remains optional: if no `logger` capability is installed, the module must continue normally. This convention applies to existing and future Prefab modules.

## Details contract

`details` is always an object/associative array. Common keys are:

```php
[
    'message' => 'Optional detail summary',
    'changes' => [
        'amount' => ['old' => 1500, 'new' => 2250],
    ],
    'data' => [],
    'context' => [],
    'error' => [],
    'meta' => [],
]
```

Custom keys are allowed. Values may be normal JSON-compatible strings, numbers, booleans, nulls, arrays, or objects represented as arrays.

## Compact storage

Verbose details are sanitized, JSON encoded, and gzip compressed only when compression makes the payload smaller. A one-byte format marker identifies compressed vs plain JSON. The payload is stored in a BLOB, so Base64 overhead is avoided.

Existing `changes` and `metadata` columns remain for backward compatibility. New records store verbose data in the compact details payload and the repository transparently hydrates the legacy array fields when reading.

## Sensitive values

Keys containing password, secret, token, authorization, API key, or private-key semantics are redacted recursively before storage. Sensitive change fields preserve their `old`/`new` shape while both values become `[REDACTED]`.

## Compatibility inference

Older Prefab modules do not have to know about the new classification, level, status, or user-scope fields. When omitted, Prefab Logs derives useful defaults from stable action names and user subjects. Examples:

- `auth.login` -> `AUTH`, `INFO`, module `auth`, success, user scope.
- `auth.login_failed` with a known user -> `AUTH`, `WARNING`, module `auth`, failed, user scope.
- `permission.granted` -> `SECURITY`, `NOTICE`, module `permissions`, success.
- `user.updated` -> `AUDIT`, `INFO`, module `users`, success, user scope.
- `user.deleted` -> `AUDIT`, `NOTICE`, module `users`, success, user scope.

Modules should provide explicit scope and visibility whenever the inferred defaults are not appropriate.
