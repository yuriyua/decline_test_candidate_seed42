<?php
declare(strict_types=1);

namespace RetryEngine;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;
use RetryEngine\Lifecycle\ChargeLifecycle;
use RetryEngine\Quality\DataQualityReport;

final class DataLoader
{
    /** @var array<string, array<string, float>> date => [currency => rate] */
    private array $fxRates = [];

    public function loadFxRates(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("FX rates file not found: {$filePath}");
        }

        $fp = fopen($filePath, 'r');
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            // date,currency,rate_to_eur
            if (count($row) >= 3) {
                $this->fxRates[$row[0]][strtoupper($row[1])] = (float)$row[2];
            }
        }
        fclose($fp);
    }

    public function getCurrencyExponent(string $currency): ?int
    {
        return match (strtoupper($currency)) {
            'EUR', 'USD', 'GBP', 'PLN' => 2,
            'JPY' => 0,
            default => null,
        };
    }

    public function convertToEur(int $amountMinor, string $currency, string $dateIso): ?float
    {
        $exp = $this->getCurrencyExponent($currency);
        if ($exp === null) {
            return null;
        }

        $curr = strtoupper($currency);
        if ($curr === 'EUR') {
            return $amountMinor / (10 ** $exp);
        }

        $dateKey = substr($dateIso, 0, 10);
        if (!isset($this->fxRates[$dateKey][$curr])) {
            return null; // Missing FX rate
        }

        $rate = $this->fxRates[$dateKey][$curr];
        return ($amountMinor / (10 ** $exp)) * $rate;
    }

    /**
     * Loads attempts from CSV, performing data contract validation and deduplication.
     *
     * @param string $filePath
     * @param DataQualityReport $dqReport
     * @return array{attempts: Attempt[], charges: array<string, Attempt[]>}
     */
    public function loadAttempts(string $filePath, DataQualityReport $dqReport): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Attempts file not found: {$filePath}");
        }

        $fp = fopen($filePath, 'r');
        $header = fgetcsv($fp);
        if ($header === false) {
            fclose($fp);
            return ['attempts' => [], 'charges' => []];
        }

        $headerMap = array_flip($header);

        $eventsById = [];
        $rawRecords = [];
        $quarantinedIds = [];

        // First pass: detect duplicate and conflicting event_ids
        while (($row = fgetcsv($fp)) !== false) {
            $dqReport->totalRows++;
            $eventId = $row[$headerMap['event_id']] ?? '';

            // Compute business payload excluding ingestion delivery metadata (received_at)
            $payload = [
                $row[$headerMap['attempt_id']] ?? '',
                $row[$headerMap['charge_id']] ?? '',
                $row[$headerMap['merchant_id']] ?? '',
                $row[$headerMap['customer_id']] ?? '',
                $row[$headerMap['card_token']] ?? '',
                $row[$headerMap['card_bin']] ?? '',
                $row[$headerMap['card_scheme']] ?? '',
                $row[$headerMap['card_type']] ?? '',
                $row[$headerMap['card_country']] ?? '',
                $row[$headerMap['amount_minor']] ?? '',
                $row[$headerMap['currency']] ?? '',
                $row[$headerMap['created_at']] ?? '',
                $row[$headerMap['attempt_number']] ?? '',
                $row[$headerMap['status']] ?? '',
                $row[$headerMap['response_code']] ?? '',
                $row[$headerMap['merchant_advice_code']] ?? '',
                $row[$headerMap['gateway_decline_category']] ?? '',
            ];
            $payloadStr = implode('|', $payload);

            if (isset($eventsById[$eventId])) {
                if ($eventsById[$eventId]['payload'] !== $payloadStr) {
                    // Conflict duplicate: different payload for same event_id
                    $quarantinedIds[$eventId] = true;
                    $dqReport->recordDuplicate($eventId, true, 0.0, [
                        'event_id' => $eventId,
                        'reason' => 'payload_conflict_between_webhooks',
                    ]);
                } else {
                    // Pure redelivery duplicate
                    $dqReport->recordDuplicate($eventId, false);
                }
                continue;
            }

            $eventsById[$eventId] = [
                'payload' => $payloadStr,
                'row' => $row,
            ];
            $rawRecords[] = $row;
        }
        fclose($fp);

        $validAttempts = [];
        $charges = [];
        $chargeHasZero = [];
        $chargeHasRetry = [];

        // Second pass: instantiate validated normalized Attempt entities
        foreach ($rawRecords as $row) {
            $eventId = $row[$headerMap['event_id']];

            // If event was marked quarantined due to conflict, exclude from valid processing
            if (isset($quarantinedIds[$eventId])) {
                $amountMinor = (int)($row[$headerMap['amount_minor']] ?? 0);
                $curr = $row[$headerMap['currency']] ?? 'EUR';
                $createdIso = $row[$headerMap['created_at']] ?? '';
                $eurVal = $this->convertToEur($amountMinor, $curr, $createdIso) ?? 0.0;
                $dqReport->excludedEur += $eurVal;
                continue;
            }

            $amountMinor = (int)($row[$headerMap['amount_minor']] ?? 0);
            $curr = $row[$headerMap['currency']] ?? '';
            $createdIso = $row[$headerMap['created_at']] ?? '';
            $receivedIso = $row[$headerMap['received_at']] ?? '';
            $attemptNumber = (int)($row[$headerMap['attempt_number']] ?? 0);
            $chargeId = $row[$headerMap['charge_id']] ?? '';
            $status = $row[$headerMap['status']] ?? '';

            // Validation: amount
            if ($amountMinor <= 0) {
                $dqReport->invalidAmountCount++;
                $dqReport->recordRejected("Amount must be strictly positive: {$amountMinor}", 0.0);
                continue;
            }

            // Validation: currency exponent
            if ($this->getCurrencyExponent($curr) === null) {
                $dqReport->invalidCurrencyCount++;
                $dqReport->recordRejected("Unknown currency: {$curr}", 0.0);
                continue;
            }

            // Validation: FX rate
            $amountEur = $this->convertToEur($amountMinor, $curr, $createdIso);
            $qualityStatus = 'valid';
            if ($amountEur === null) {
                $dqReport->missingFxCount++;
                $qualityStatus = 'data_quality_error';
                $dqReport->recordRejected("Missing FX rate for {$curr} on {$createdIso}", 0.0);
                continue;
            }

            // Validation: late event (> 1 hour delivery delay)
            $createdTs = strtotime($createdIso);
            $receivedTs = strtotime($receivedIso);
            if ($receivedTs - $createdTs > 3600) {
                $dqReport->lateEventsCount++;
            }

            $attempt = new Attempt(
                eventId: $eventId,
                attemptId: $row[$headerMap['attempt_id']] ?? '',
                chargeId: $chargeId,
                merchantId: $row[$headerMap['merchant_id']] ?? '',
                customerId: $row[$headerMap['customer_id']] ?? '',
                cardToken: $row[$headerMap['card_token']] ?? '',
                cardBin: $row[$headerMap['card_bin']] ?? '',
                cardScheme: $row[$headerMap['card_scheme']] ?? '',
                cardType: $row[$headerMap['card_type']] ?? '',
                cardCountry: $row[$headerMap['card_country']] ?? '',
                amountMinor: $amountMinor,
                currency: $curr,
                createdAt: new DateTimeImmutable($createdIso),
                attemptNumber: $attemptNumber,
                status: $status,
                responseCode: $row[$headerMap['response_code']] ?? '',
                merchantAdviceCode: !empty($row[$headerMap['merchant_advice_code']]) ? $row[$headerMap['merchant_advice_code']] : null,
                gatewayDeclineCategory: $row[$headerMap['gateway_decline_category']] ?? '',
                receivedAt: new DateTimeImmutable($receivedIso),
                amountEur: $amountEur,
                qualityStatus: $qualityStatus
            );

            $validAttempts[] = $attempt;
            $charges[$chargeId][] = $attempt;

            if ($attemptNumber === 0) {
                if (isset($chargeHasZero[$chargeId])) {
                    $dqReport->multiAttemptZeroCount++;
                }
                $chargeHasZero[$chargeId] = true;
            } else {
                $chargeHasRetry[$chargeId] = true;
            }

            $dqReport->validEventsCount++;
        }

        // Check for orphan retries (charges with attempt_number >= 1 but no attempt_number = 0)
        foreach ($chargeHasRetry as $cid => $trueVal) {
            if (!isset($chargeHasZero[$cid])) {
                $dqReport->orphanRetriesCount++;
                // Mark attempts in that charge as orphan_retry
                if (isset($charges[$cid])) {
                    foreach ($charges[$cid] as $idx => $att) {
                        $charges[$cid][$idx] = new Attempt(
                            eventId: $att->eventId,
                            attemptId: $att->attemptId,
                            chargeId: $att->chargeId,
                            merchantId: $att->merchantId,
                            customerId: $att->customerId,
                            cardToken: $att->cardToken,
                            cardBin: $att->cardBin,
                            cardScheme: $att->cardScheme,
                            cardType: $att->cardType,
                            cardCountry: $att->cardCountry,
                            amountMinor: $att->amountMinor,
                            currency: $att->currency,
                            createdAt: $att->createdAt,
                            attemptNumber: $att->attemptNumber,
                            status: $att->status,
                            responseCode: $att->responseCode,
                            merchantAdviceCode: $att->merchantAdviceCode,
                            gatewayDeclineCategory: $att->gatewayDeclineCategory,
                            receivedAt: $att->receivedAt,
                            amountEur: $att->amountEur,
                            qualityStatus: 'orphan_retry'
                        );
                    }
                }
            }
        }

        return [
            'attempts' => $validAttempts,
            'charges' => $charges,
        ];
    }

    /**
     * Builds ChargeLifecycle objects for all charges.
     *
     * @param array<string, Attempt[]> $charges
     * @param DateTimeImmutable|null $windowEnd
     * @return ChargeLifecycle[]
     */
    public function buildLifecycles(array $charges, ?DateTimeImmutable $windowEnd = null): array
    {
        $lifecycles = [];
        foreach ($charges as $cid => $attempts) {
            $lifecycles[$cid] = new ChargeLifecycle($cid, $attempts, $windowEnd);
        }
        return $lifecycles;
    }
}
