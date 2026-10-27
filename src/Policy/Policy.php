<?php
declare(strict_types=1);

namespace RetryEngine\Policy;

final class Policy
{
    public function __construct(
        public readonly string $version = '1.0.0',
        /** @var array<string, int> Max total attempts including original by scheme (up to 5 retries, within scheme 15-attempt/30-day rule) */
        public readonly array $maxAttemptsByScheme = [
            'visa' => 6,
            'mastercard' => 6,
            'default' => 6,
        ],
        /** @var array<string, int> Min delay in seconds by response code */
        public readonly array $minDelayByReason = [
            '91' => 3600,    // 1 hour for switch inoperative / technical outage
            '96' => 3600,    // 1 hour for system malfunction
            '51' => 86400,   // 24 hours for insufficient funds
            '05' => 86400,   // 24 hours for generic soft decline
            'default' => 86400,
        ],
        /** @var array<string, int> Max retry window in seconds from original attempt */
        public readonly array $retryWindowByReason = [
            'visa' => 2592000,       // 30 calendar days
            'mastercard' => 2592000, // 30 calendar days
            'default' => 2592000,
        ],
        /** @var string[] Hard decline response codes */
        public readonly array $blockedResponseCodes = [
            '04', // Pick-up card
            '07', // Pick-up card, special condition / fraud
            '14', // Invalid card number
            '1A', // Customer authentication required (3DS)
            '41', // Lost card
            '43', // Stolen card
            '54', // Expired card
            '57', // Transaction not permitted to cardholder
            '59', // Suspected fraud
            '62', // Restricted card
            'R1', // Revocation of authorization order
        ],
        /** @var string[] Hard decline MAC codes */
        public readonly array $blockedMerchantAdviceCodes = [
            '21', // Payment cancellation
            '03', // Cardholder action required / Do not try again
        ],
        /** @var string[] Soft decline codes eligible for automated retry */
        public readonly array $allowedSoftResponseCodes = [
            '51', // Insufficient funds
            '91', // Switch inoperative
            '96', // System malfunction
            '05', // Generic soft decline (issuer do not honor without hard MAC)
        ],
        /** @var float Cost per retry attempt in EUR */
        public readonly float $retryFeeEur = 0.08,
        /** @var float Min expected success rate threshold */
        public readonly float $expectedSuccessThreshold = 0.02,
        /** @var int Minimum observations in historical segment to avoid fallback */
        public readonly int $minSampleSize = 30,
        /** @var array<string, mixed> Merchant overrides */
        public readonly array $merchantOverrides = [],
        /** @var bool Global kill switch to instantly halt retries */
        public readonly bool $globalKillSwitch = false,
        /** @var array<string, bool> Merchant-level kill switches */
        public readonly array $merchantKillSwitches = []
    ) {}

    public static function createDefault(): self
    {
        return new self();
    }

    public function isMerchantKilled(string $merchantId): bool
    {
        if ($this->globalKillSwitch) {
            return true;
        }
        return $this->merchantKillSwitches[$merchantId] ?? false;
    }

    public function getMaxAttempts(string $scheme): int
    {
        $key = strtolower($scheme);
        return $this->maxAttemptsByScheme[$key] ?? $this->maxAttemptsByScheme['default'];
    }

    public function getMinDelay(string $responseCode): int
    {
        return $this->minDelayByReason[$responseCode] ?? $this->minDelayByReason['default'];
    }

    public function getRetryWindow(string $scheme): int
    {
        $key = strtolower($scheme);
        return $this->retryWindowByReason[$key] ?? $this->retryWindowByReason['default'];
    }
}
