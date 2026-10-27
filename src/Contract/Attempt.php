<?php
declare(strict_types=1);

namespace RetryEngine\Contract;

use DateTimeImmutable;
use JsonSerializable;

final class Attempt implements JsonSerializable
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $attemptId,
        public readonly string $chargeId,
        public readonly string $merchantId,
        public readonly string $customerId,
        public readonly string $cardToken,
        public readonly string $cardBin,
        public readonly string $cardScheme,
        public readonly string $cardType,
        public readonly string $cardCountry,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly DateTimeImmutable $createdAt,
        public readonly int $attemptNumber,
        public readonly string $status,
        public readonly string $responseCode,
        public readonly ?string $merchantAdviceCode,
        public readonly string $gatewayDeclineCategory,
        public readonly DateTimeImmutable $receivedAt,
        public readonly ?float $amountEur = null,
        public readonly string $qualityStatus = 'valid' // valid, duplicate, quarantined, rejected, orphan_retry, data_quality_error
    ) {}

    public function isApproved(): bool
    {
        return strtolower($this->status) === 'approved';
    }

    public function isDeclined(): bool
    {
        return strtolower($this->status) === 'declined';
    }

    public function isOriginal(): bool
    {
        return $this->attemptNumber === 0;
    }

    public function isRetry(): bool
    {
        return $this->attemptNumber > 0;
    }

    /**
     * Masks card token for secure logging. Never exposes full token.
     * Example: tok_07fdc4e4038a5950 -> tok_...5950
     */
    public function getMaskedCardToken(): string
    {
        if (strlen($this->cardToken) <= 8) {
            return 'tok_***';
        }
        return substr($this->cardToken, 0, 4) . '...' . substr($this->cardToken, -4);
    }

    /**
     * Masks BIN for secure logging.
     * Example: 566138 -> 566***
     */
    public function getMaskedCardBin(): string
    {
        if (strlen($this->cardBin) <= 3) {
            return '***';
        }
        return substr($this->cardBin, 0, 3) . str_repeat('*', strlen($this->cardBin) - 3);
    }

    /**
     * Export safe record for audit trail without sensitive plaintext card data.
     */
    public function toAuditArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'attempt_id' => $this->attemptId,
            'charge_id' => $this->chargeId,
            'merchant_id' => $this->merchantId,
            'customer_id' => $this->customerId,
            'card_token' => $this->getMaskedCardToken(),
            'card_bin' => $this->getMaskedCardBin(),
            'card_scheme' => $this->cardScheme,
            'card_type' => $this->cardType,
            'card_country' => $this->cardCountry,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'amount_eur' => $this->amountEur !== null ? round($this->amountEur, 4) : null,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'attempt_number' => $this->attemptNumber,
            'status' => $this->status,
            'response_code' => $this->responseCode,
            'merchant_advice_code' => $this->merchantAdviceCode,
            'gateway_decline_category' => $this->gatewayDeclineCategory,
            'received_at' => $this->receivedAt->format(DATE_ATOM),
            'quality_status' => $this->qualityStatus,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toAuditArray();
    }
}
