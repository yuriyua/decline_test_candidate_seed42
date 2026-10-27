<?php
declare(strict_types=1);

namespace RetryEngine\Engine;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;

final class AuditLogger
{
    /** @var array<int, array> */
    private array $logs = [];

    /**
     * Records an audit event ensuring all card details are masked.
     */
    public function logDecision(
        Attempt $attempt,
        Decision $decision,
        DateTimeImmutable $evaluatedAt
    ): array {
        $record = [
            'timestamp' => $evaluatedAt->format(DATE_ATOM),
            'charge_id' => $attempt->chargeId,
            'attempt_id' => $attempt->attemptId,
            'merchant_id' => $attempt->merchantId,
            'customer_id' => $attempt->customerId,
            'masked_card_token' => $attempt->getMaskedCardToken(),
            'masked_card_bin' => $attempt->getMaskedCardBin(),
            'card_scheme' => $attempt->cardScheme,
            'amount_eur' => $attempt->amountEur !== null ? round($attempt->amountEur, 2) : null,
            'declined_response_code' => $attempt->responseCode,
            'merchant_advice_code' => $attempt->merchantAdviceCode,
            'action' => $decision->action,
            'scheduled_retry_at' => $decision->retryAt?->format(DATE_ATOM),
            'reason_code' => $decision->reasonCode,
            'explanation' => $decision->explanation,
            'policy_version' => $decision->policyVersion,
            'expected_net_value' => $decision->expectedNetValue !== null ? round($decision->expectedNetValue, 4) : null,
            'p_success' => $decision->pSuccess !== null ? round($decision->pSuccess, 4) : null,
        ];

        $this->logs[] = $record;
        return $record;
    }

    /**
     * @return array<int, array>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    public function clear(): void
    {
        $this->logs = [];
    }

    /**
     * Export audit trail to CSV file.
     */
    public function exportCsv(string $filePath): void
    {
        if (empty($this->logs)) {
            return;
        }

        $fp = fopen($filePath, 'w');
        fputcsv($fp, array_keys($this->logs[0]));
        foreach ($this->logs as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
    }
}
