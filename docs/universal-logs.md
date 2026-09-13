# Universal Prefab Logs

Prefab Logs accepts application-specific events while keeping searchable metadata consistent.

## Searchable fields

- `classification`: `AUDIT`, `AUTH`, `SECURITY`, `SYSTEM`, `ERROR`, `INTEGRATION`, or `DATA` by convention. Custom classifications remain allowed.
- `level`: `10 DEBUG`, `20 INFO`, `30 NOTICE`, `40 WARNING`, `50 ERROR`, `60 CRITICAL`.
- `module`: source module such as `auth`, `user`, or `permission`.
- `action`: stable event name such as `user.updated`.
- `subject_type` / `subject_id`: affected object.
- `actor_id`: actor when known.
- `status`: `1` success, `0` failed, `null` not applicable.
- `message`: short human-readable summary.
- request context such as IP address and user agent.

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

Older Prefab modules do not have to know about the new fields. When omitted, Prefab Logs derives useful defaults from stable action names. Examples:

- `auth.login` -> `AUTH`, `INFO`, module `auth`, success.
- `auth.login_failed` -> `AUTH`, `WARNING`, module `auth`, failed.
- `permission.granted` -> `SECURITY`, `NOTICE`, module `permission`, success.
- `user.updated` -> `AUDIT`, `INFO`, module `user`, success.
- `user.deleted` -> `AUDIT`, `NOTICE`, module `user`, success.

Modules may still provide explicit values whenever the inferred defaults are not appropriate.
