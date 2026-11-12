<?php
declare(strict_types=1);

namespace Tihloh\Prefab\Logs\Presenters;

use DateTimeImmutable;

/**
 * Framework-independent audit history presentation.
 *
 * Applications supply reference labels and their own authorization decision.
 * Prefab Logs never grants permissions or fetches application-specific tables.
 * The original evidence stays in the log store and is returned only when the
 * host explicitly grants technical-detail access.
 */
final class AuditTrailPresenter
{
    /**
     * @param callable|null $referenceResolver fn(string $kind, string $id): ?string
     * @param callable|null $labelResolver fn(string $field): string
     * @param callable|null $valueResolver fn(string $field, mixed $value): string
     * @param callable|null $summaryResolver fn(array $entry): ?string
     */
    public function __construct(
        private $referenceResolver = null,
        private $labelResolver = null,
        private $valueResolver = null,
        private $summaryResolver = null,
    ) {}

    public function present(array $entry, bool $mayViewTechnicalDetails = false): array
    {
        $summary = $this->describe($entry);
        $result = [
            'title' => (string) ($entry['title'] ?? $this->label((string) ($entry['action'] ?? 'Activity'))),
            'summary' => $summary,
            'time' => $entry['time'] ?? $entry['occurred_at'] ?? $entry['created_at'] ?? null,
            'actor' => $entry['actor'] ?? null,
            'source' => $entry['source'] ?? 'system',
            'has_technical_details' => $mayViewTechnicalDetails
                && (!empty($entry['changes']) || !empty($entry['metadata']) || !empty($entry['message'])),
        ];
        if ($mayViewTechnicalDetails) {
            $result['changes'] = $this->changeRows(is_array($entry['changes'] ?? null) ? $entry['changes'] : []);
            $result['metadata'] = $this->metadataRows(is_array($entry['metadata'] ?? null) ? $entry['metadata'] : []);
            $result['original_message'] = (string) ($entry['message'] ?? '');
        }
        return $result;
    }

    public function many(array $entries, bool $mayViewTechnicalDetails = false): array
    {
        return array_map(fn(array $entry): array => $this->present($entry, $mayViewTechnicalDetails), $entries);
    }

    public function describe(array $entry): string
    {
        if ($this->summaryResolver) {
            $custom = ($this->summaryResolver)($entry);
            if (is_string($custom) && trim($custom) !== '') return trim($custom);
        }
        $action = strtolower(trim((string) ($entry['action'] ?? '')));
        $source = (string) ($entry['source'] ?? 'system');
        $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];

        // Expense/ledger events follow a reusable signed-amount convention.
        // Reference names are application-provided, never guessed from IDs.
        if (str_starts_with($action, 'expense.')) {
            $after = is_array($metadata['after'] ?? null) ? $metadata['after'] : [];
            $subject = $this->reference('object', $after['objid'] ?? $metadata['objid'] ?? null)
                ?: 'the selected expense object';
            $program = $this->reference('program', $after['progid'] ?? $metadata['progid']
                ?? $metadata['destination_program'] ?? null);
            $where = $program !== '' ? ' under '.$program : '';
            $rawAmount = $after['amount'] ?? $metadata['amount'] ?? null;
            $value = is_numeric($rawAmount)
                ? (($rawAmount < 0 ? '−' : '+').'₱'.number_format(abs((float) $rawAmount), 2)) : '';
            $amount = $value !== '' ? ' for '.$value : '';
            $date = self::date($after['date'] ?? $metadata['date'] ?? null);
            $effective = $date !== '' ? ' effective '.$date : '';
            return match ($action) {
                'expense.adjusted', 'expense.adjustment_added' =>
                    'Recorded a balance adjustment'.$amount.' to '.$subject.$where.$effective.'.',
                'expense.adjustment_updated' =>
                    'Updated the balance adjustment for '.$subject.$where.$amount.$effective.'.',
                'expense.added' => 'Added an expense allocation'.$amount.' for '.$subject.$where.'.',
                'expense.deleted' => 'Deleted an expense allocation'.$amount.' for '.$subject.$where.'.',
                'expense.edited', 'expense.updated' =>
                    'Updated the expense allocation for '.$subject.$where.$amount.'.',
                'expense.group_moved' =>
                    'Moved '.(int) ($metadata['entry_count'] ?? 0).' expense allocation(s) from '.
                    ($this->reference('program', $metadata['source_program'] ?? null) ?: 'the previous program')
                    .' to '.($this->reference('program', $metadata['destination_program'] ?? null)
                        ?: 'another program').'.',
                default => 'Updated expense allocation records for this document.',
            };
        }

        $title = trim((string) ($entry['title'] ?? ''));
        if ($source === 'routing') {
            if (preg_match('/^Forward\s*[—-]\s*(.+)$/iu', $title, $parts)) {
                return 'Document forwarded: '.$parts[1].'.';
            }
            if (preg_match('/^Return\s*[—-]\s*(.+)$/iu', $title, $parts)) {
                return 'Document returned: '.$parts[1].'.';
            }
            return $title !== '' ? rtrim($title, '.').'.' : 'A workflow step was recorded.';
        }
        if ($source === 'document' || $action === 'received') return 'The document was received and recorded.';
        if ($source === 'legacy') return 'A change was recorded in the legacy document system.';
        if (in_array($action, ['request.updated', 'document.updated'], true)) {
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            $labels = array_map(fn($field): string => $this->label((string) $field),
                array_slice(array_keys($changes), 0, 3));
            return $labels
                ? 'Updated document information: '.implode(', ', $labels)
                    .(count($changes) > 3 ? ', and other fields' : '').'.'
                : 'Updated the document information.';
        }
        if ($title !== '' && $title !== 'Recorded activity') return rtrim($title, '.').'.';
        return 'Document activity was recorded.';
    }

    public function changeRows(array $changes): array
    {
        $rows = [];
        foreach ($changes as $field => $change) {
            $key = (string) $field;
            if (is_array($change) && (array_key_exists('before', $change)
                || array_key_exists('now', $change) || array_key_exists('old', $change)
                || array_key_exists('new', $change))) {
                $old = array_key_exists('before', $change) ? 'before' : 'old';
                $new = array_key_exists('now', $change) ? 'now' : 'new';
                $rows[] = [
                    'label' => $this->label($key),
                    'previous' => array_key_exists($old, $change)
                        ? $this->value($key, $change[$old]) : 'Not recorded',
                    'current' => array_key_exists($new, $change)
                        ? $this->value($key, $change[$new]) : 'Not recorded',
                    'comparison' => true,
                ];
            } else {
                $rows[] = [
                    'label' => $this->label($key),
                    'current' => $this->value($key, $change),
                    'comparison' => false,
                ];
            }
        }
        return $rows;
    }

    public function metadataRows(array $metadata): array
    {
        $rows = [];
        foreach ($metadata as $key => $value) {
            if ($key === 'remarks') continue;
            $rows[] = ['label' => $this->label((string) $key),
                'value' => $this->value((string) $key, $value)];
        }
        return $rows;
    }

    private function reference(string $type, mixed $id): string
    {
        if ($id === null || $id === '' || !$this->referenceResolver) return '';
        $name = ($this->referenceResolver)($type, (string) $id);
        return is_string($name) ? trim($name) : '';
    }

    private function label(string $field): string
    {
        if ($this->labelResolver) return (string) ($this->labelResolver)($field);
        return ucfirst((string) preg_replace('/[_\-.]+/', ' ', $field));
    }

    private function value(string $field, mixed $value): string
    {
        if ($this->valueResolver) return (string) ($this->valueResolver)($field, $value);
        if ($value === null || $value === '') return 'Not set';
        if (is_bool($value)) return $value ? 'Yes' : 'No';
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        return (string) $value;
    }

    private static function date(mixed $raw): string
    {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) return '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $date && $date->format('Y-m-d') === $raw ? $date->format('M j, Y') : '';
    }
}
