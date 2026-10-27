<?php
declare(strict_types=1);

namespace RetryEngine\Policy;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;

final class DeclineClassifier
{
    public const CATEGORY_DO_NOT_RETRY_HARD = 'do_not_retry_hard';
    public const CATEGORY_RETRY_AFTER_SPECIFIC_TIME = 'retry_after_specific_time';
    public const CATEGORY_RETRY_SOFT = 'retry_soft';
    public const CATEGORY_MANUAL_REVIEW = 'manual_review';
    public const CATEGORY_EXHAUSTED = 'exhausted';

    public function __construct(
        private readonly Policy $policy
    ) {}

    /**
     * Classifies a declined attempt based on charge history and policy rules.
     *
     * @param Attempt $attempt
     * @param Attempt[] $history
     * @param DateTimeImmutable $now
     * @return array{category: string, reason_code: string, explanation: string, retry_at: ?DateTimeImmutable}
     */
    public function classify(Attempt $attempt, array $history, DateTimeImmutable $now): array
    {
        // 1. Check if charge is already approved
        if ($attempt->isApproved()) {
            return [
                'category' => self::CATEGORY_DO_NOT_RETRY_HARD,
                'reason_code' => 'charge_already_approved',
                'explanation' => 'Charge is already approved on current attempt; no further retries permitted.',
                'retry_at' => null,
            ];
        }

        foreach ($history as $h) {
            if ($h->isApproved()) {
                return [
                    'category' => self::CATEGORY_DO_NOT_RETRY_HARD,
                    'reason_code' => 'charge_already_approved',
                    'explanation' => 'Charge was already approved in prior attempt ' . $h->attemptNumber . '; lifecycle finished.',
                    'retry_at' => null,
                ];
            }
        }

        // 2. Priority check: Merchant Advice Code (MAC)
        $mac = $attempt->merchantAdviceCode;
        if ($mac !== null && $mac !== '') {
            if (in_array($mac, $this->policy->blockedMerchantAdviceCodes, true)) {
                return [
                    'category' => self::CATEGORY_DO_NOT_RETRY_HARD,
                    'reason_code' => 'hard_decline_mac_' . $mac,
                    'explanation' => "Mastercard merchant advice code {$mac} prohibits further retries (payment cancellation/cardholder action required).",
                    'retry_at' => null,
                ];
            }

            if ($mac === '02') {
                // MAC 02: Try again later.
                // If specific time is needed, check min delay
            }
        }

        // 3. Check blocked hard response codes
        $rc = $attempt->responseCode;
        if (in_array($rc, $this->policy->blockedResponseCodes, true)) {
            return [
                'category' => self::CATEGORY_DO_NOT_RETRY_HARD,
                'reason_code' => 'hard_decline',
                'explanation' => "Response code {$rc} is classified as a non-retryable hard decline by card scheme policy.",
                'retry_at' => null,
            ];
        }

        // 4. Check gateway decline category: if hard, block
        if (strtolower($attempt->gatewayDeclineCategory) === 'hard') {
            return [
                'category' => self::CATEGORY_DO_NOT_RETRY_HARD,
                'reason_code' => 'hard_decline',
                'explanation' => "Gateway decline category is hard for response code {$rc}.",
                'retry_at' => null,
            ];
        }

        // 5. Check scheme attempt limits
        $maxAttempts = $this->policy->getMaxAttempts($attempt->cardScheme);
        // Current attempt count is count of existing attempts in history + current attempt if not in history
        $existingAttemptNumbers = [];
        foreach ($history as $h) {
            $existingAttemptNumbers[$h->attemptNumber] = true;
        }
        $existingAttemptNumbers[$attempt->attemptNumber] = true;
        $totalAttempts = count($existingAttemptNumbers);

        if ($totalAttempts >= $maxAttempts) {
            return [
                'category' => self::CATEGORY_EXHAUSTED,
                'reason_code' => 'attempt_limit_reached',
                'explanation' => "Maximum attempt limit of {$maxAttempts} reached for scheme {$attempt->cardScheme}.",
                'retry_at' => null,
            ];
        }

        // 6. Check if code is on explicit soft allowlist
        if (!in_array($rc, $this->policy->allowedSoftResponseCodes, true)) {
            return [
                'category' => self::CATEGORY_MANUAL_REVIEW,
                'reason_code' => 'unrecognized_response_code',
                'explanation' => "Response code {$rc} labeled as soft by gateway is not on policy allowlist; manual review required.",
                'retry_at' => null,
            ];
        }

        // 7. Calculate candidate retry time
        $minDelaySeconds = $this->policy->getMinDelay($rc);
        $candidateRetryTimestamp = $attempt->createdAt->getTimestamp() + $minDelaySeconds;

        // If candidate time is in the past relative to now, shift to next future slot
        if ($candidateRetryTimestamp < $now->getTimestamp()) {
            $candidateRetryTimestamp = $now->getTimestamp();
        }

        // 8. Check retry window from original attempt
        $originalCreatedAt = $attempt->createdAt;
        foreach ($history as $h) {
            if ($h->isOriginal()) {
                $originalCreatedAt = $h->createdAt;
                break;
            }
        }

        $retryWindowSeconds = $this->policy->getRetryWindow($attempt->cardScheme);
        $maxAllowedTimestamp = $originalCreatedAt->getTimestamp() + $retryWindowSeconds;

        if ($candidateRetryTimestamp > $maxAllowedTimestamp) {
            return [
                'category' => self::CATEGORY_EXHAUSTED,
                'reason_code' => 'retry_window_expired',
                'explanation' => "Scheduled retry time would exceed scheme retry window of {$retryWindowSeconds} seconds from original attempt.",
                'retry_at' => null,
            ];
        }

        $retryAt = (new DateTimeImmutable())->setTimestamp($candidateRetryTimestamp);

        return [
            'category' => self::CATEGORY_RETRY_SOFT,
            'reason_code' => 'retry_soft_eligible',
            'explanation' => "Soft decline ({$rc}) is eligible for scheduled retry in accordance with card scheme policy.",
            'retry_at' => $retryAt,
        ];
    }
}
