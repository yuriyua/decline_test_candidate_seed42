<?php
declare(strict_types=1);

namespace RetryEngine\Lifecycle;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;

final class ChargeLifecycle
{
    /** @var Attempt[] */
    private array $attempts;
    private string $state;
    private ?Attempt $originalAttempt = null;
    private ?Attempt $winningAttempt = null;

    /**
     * @param string $chargeId
     * @param Attempt[] $attempts
     * @param DateTimeImmutable|null $windowEnd
     * @param int $observationBufferDays Days before window end considered incomplete observation (default 7)
     */
    public function __construct(
        public readonly string $chargeId,
        array $attempts,
        ?DateTimeImmutable $windowEnd = null,
        int $observationBufferDays = 7
    ) {
        $windowEnd = $windowEnd ?? new DateTimeImmutable('2026-10-01T00:00:00+00:00');
        $this->attempts = $this->sortAttempts($attempts);
        $this->evaluateLifecycle($windowEnd, $observationBufferDays);
    }

    /**
     * Stable sort: created_at, then attempt_number, then received_at
     * @param Attempt[] $attempts
     * @return Attempt[]
     */
    private function sortAttempts(array $attempts): array
    {
        usort($attempts, function (Attempt $a, Attempt $b) {
            $tA = $a->createdAt->getTimestamp();
            $tB = $b->createdAt->getTimestamp();
            if ($tA !== $tB) {
                return $tA <=> $tB;
            }
            if ($a->attemptNumber !== $b->attemptNumber) {
                return $a->attemptNumber <=> $b->attemptNumber;
            }
            return $a->receivedAt->getTimestamp() <=> $b->receivedAt->getTimestamp();
        });
        return array_values($attempts);
    }

    private function evaluateLifecycle(DateTimeImmutable $windowEnd, int $observationBufferDays): void
    {
        if (empty($this->attempts)) {
            $this->state = ChargeState::INVALID_DATA;
            return;
        }

        // Find attempt_number = 0
        foreach ($this->attempts as $att) {
            if ($att->isOriginal()) {
                $this->originalAttempt = $att;
                break;
            }
        }

        if ($this->originalAttempt === null) {
            $this->state = ChargeState::INVALID_DATA;
            return;
        }

        if ($this->originalAttempt->qualityStatus === 'rejected' || $this->originalAttempt->qualityStatus === 'quarantined') {
            $this->state = ChargeState::INVALID_DATA;
            return;
        }

        // Case 1: Approved initially
        if ($this->originalAttempt->isApproved()) {
            $this->state = ChargeState::APPROVED_INITIALLY;
            $this->winningAttempt = $this->originalAttempt;
            return;
        }

        // Check subsequent attempts
        $hasApprovedRetry = false;
        foreach ($this->attempts as $att) {
            if ($att->isRetry() && $att->isApproved()) {
                $hasApprovedRetry = true;
                $this->winningAttempt = $att;
                break; // First approved retry wins; lifecycle is recovered
            }
        }

        if ($hasApprovedRetry) {
            $this->state = ChargeState::RECOVERED_BY_CURRENT_RETRY;
            return;
        }

        // Unrecovered: check if incomplete observation
        // If created within the buffer window before reporting end and unrecovered
        $cutoffSeconds = $observationBufferDays * 86400;
        $timeRemaining = $windowEnd->getTimestamp() - $this->originalAttempt->createdAt->getTimestamp();
        if ($timeRemaining >= 0 && $timeRemaining < $cutoffSeconds) {
            $this->state = ChargeState::INCOMPLETE_OBSERVATION;
            return;
        }

        $this->state = ChargeState::LOST;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getOriginalAttempt(): ?Attempt
    {
        return $this->originalAttempt;
    }

    public function getWinningAttempt(): ?Attempt
    {
        return $this->winningAttempt;
    }

    /**
     * @return Attempt[]
     */
    public function getAttempts(): array
    {
        return $this->attempts;
    }

    public function isRecovered(): bool
    {
        return $this->state === ChargeState::RECOVERED_BY_CURRENT_RETRY;
    }

    public function isLost(): bool
    {
        return $this->state === ChargeState::LOST;
    }

    public function isApprovedInitially(): bool
    {
        return $this->state === ChargeState::APPROVED_INITIALLY;
    }

    public function isIncomplete(): bool
    {
        return $this->state === ChargeState::INCOMPLETE_OBSERVATION;
    }

    public function getGrossAtRiskEur(): float
    {
        // Gross at risk is the original declined amount
        if ($this->originalAttempt !== null && $this->originalAttempt->isDeclined()) {
            return $this->originalAttempt->amountEur ?? 0.0;
        }
        return 0.0;
    }

    public function getRecoveredEur(): float
    {
        if ($this->state === ChargeState::RECOVERED_BY_CURRENT_RETRY) {
            return $this->originalAttempt->amountEur ?? 0.0;
        }
        return 0.0;
    }

    public function getLostEur(): float
    {
        if ($this->state === ChargeState::LOST) {
            return $this->originalAttempt->amountEur ?? 0.0;
        }
        return 0.0;
    }

    /**
     * Count historical retry attempts (attempt_number >= 1)
     */
    public function getHistoricalRetryCount(): int
    {
        $count = 0;
        foreach ($this->attempts as $att) {
            if ($att->isRetry()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Cost of retries: €0.08 per retry attempt
     */
    public function getHistoricalRetryCost(float $costPerRetry = 0.08): float
    {
        return round($this->getHistoricalRetryCount() * $costPerRetry, 4);
    }

    /**
     * Net recovery = recovered EUR - retry costs
     */
    public function getNetRecovery(float $costPerRetry = 0.08): float
    {
        return round($this->getRecoveredEur() - $this->getHistoricalRetryCost($costPerRetry), 4);
    }
}
