<?php
declare(strict_types=1);

namespace RetryEngine\Engine;

use DateTimeImmutable;
use JsonSerializable;

final class Decision implements JsonSerializable
{
    public const ACTION_RETRY = 'retry';
    public const ACTION_DO_NOT_RETRY = 'do_not_retry';
    public const ACTION_MANUAL_REVIEW = 'manual_review';

    public function __construct(
        public readonly string $action, // retry, do_not_retry, manual_review
        public readonly ?DateTimeImmutable $retryAt,
        public readonly string $reasonCode,
        public readonly string $explanation,
        public readonly string $policyVersion,
        public readonly ?float $expectedNetValue = null,
        public readonly ?float $pSuccess = null,
        public readonly ?string $chargeId = null,
        public readonly ?int $scheduledAttemptNumber = null
    ) {}

    public function isRetry(): bool
    {
        return $this->action === self::ACTION_RETRY;
    }

    public function isDoNotRetry(): bool
    {
        return $this->action === self::ACTION_DO_NOT_RETRY;
    }

    public function isManualReview(): bool
    {
        return $this->action === self::ACTION_MANUAL_REVIEW;
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'retry_at' => $this->retryAt?->format(DATE_ATOM),
            'reason_code' => $this->reasonCode,
            'explanation' => $this->explanation,
            'policy_version' => $this->policyVersion,
            'expected_net_value' => $this->expectedNetValue !== null ? round($this->expectedNetValue, 4) : null,
            'p_success' => $this->pSuccess !== null ? round($this->pSuccess, 4) : null,
            'charge_id' => $this->chargeId,
            'scheduled_attempt_number' => $this->scheduledAttemptNumber,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
