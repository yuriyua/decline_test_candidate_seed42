<?php
declare(strict_types=1);

namespace RetryEngine\Quality;

final class DataQualityReport
{
    public int $totalRows = 0;
    public int $validEventsCount = 0;
    public int $duplicateEventsCount = 0;
    public int $conflictDuplicatesCount = 0;
    public int $orphanRetriesCount = 0;
    public int $multiAttemptZeroCount = 0;
    public int $invalidAmountCount = 0;
    public int $invalidCurrencyCount = 0;
    public int $invalidStatusCount = 0;
    public int $invalidTimestampCount = 0;
    public int $missingFxCount = 0;
    public int $lateEventsCount = 0; // received_at > created_at + 1h
    public float $excludedEur = 0.0;

    /** @var array<string, array> */
    public array $quarantinedEvents = [];

    /** @var array<string, string> */
    public array $errors = [];

    public function recordDuplicate(string $eventId, bool $isConflict, float $eurValue = 0.0, ?array $details = null): void
    {
        $this->duplicateEventsCount++;
        if ($isConflict) {
            $this->conflictDuplicatesCount++;
            $this->quarantinedEvents[$eventId] = $details ?? ['event_id' => $eventId, 'reason' => 'conflicting_payload'];
            $this->excludedEur += $eurValue;
        }
    }

    public function recordRejected(string $reason, float $eurValue = 0.0): void
    {
        $this->excludedEur += $eurValue;
        $this->errors[] = $reason;
    }

    public function summary(): array
    {
        return [
            'total_raw_rows' => $this->totalRows,
            'valid_events' => $this->validEventsCount,
            'duplicate_events' => $this->duplicateEventsCount,
            'conflict_duplicates' => $this->conflictDuplicatesCount,
            'quarantined_events' => count($this->quarantinedEvents),
            'orphan_retries' => $this->orphanRetriesCount,
            'multiple_attempt_zeros' => $this->multiAttemptZeroCount,
            'invalid_amounts' => $this->invalidAmountCount,
            'invalid_currencies' => $this->invalidCurrencyCount,
            'invalid_statuses' => $this->invalidStatusCount,
            'invalid_timestamps' => $this->invalidTimestampCount,
            'missing_fx_rates' => $this->missingFxCount,
            'late_events_over_1h' => $this->lateEventsCount,
            'excluded_eur_due_to_dq' => round($this->excludedEur, 2),
        ];
    }

    public function formatMarkdown(): string
    {
        $s = $this->summary();
        $md = "### Data Quality & Ingestion Report\n\n";
        $md .= "| Metric | Count / Value |\n";
        $md .= "| --- | ---: |\n";
        $md .= "| Total Input Rows | " . number_format($s['total_raw_rows']) . " |\n";
        $md .= "| Valid Processed Events | " . number_format($s['valid_events']) . " |\n";
        $md .= "| Duplicate Events (Redelivery) | " . number_format($s['duplicate_events']) . " |\n";
        $md .= "| Conflict Duplicates (Payload Mismatch) | " . number_format($s['conflict_duplicates']) . " |\n";
        $md .= "| Quarantined Events | " . number_format($s['quarantined_events']) . " |\n";
        $md .= "| Orphan Retries (Missing Original) | " . number_format($s['orphan_retries']) . " |\n";
        $md .= "| Multiple Attempt 0 Charges | " . number_format($s['multiple_attempt_zeros']) . " |\n";
        $md .= "| Invalid Amounts (<= 0) | " . number_format($s['invalid_amounts']) . " |\n";
        $md .= "| Missing FX Rates | " . number_format($s['missing_fx_rates']) . " |\n";
        $md .= "| Late Events (>1h delivery delay) | " . number_format($s['late_events_over_1h']) . " |\n";
        $md .= "| **Total Excluded EUR (Data Quality)** | **€" . number_format($s['excluded_eur_due_to_dq'], 2) . "** |\n";
        return $md;
    }
}
