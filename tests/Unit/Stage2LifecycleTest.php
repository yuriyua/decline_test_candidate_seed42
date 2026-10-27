<?php
declare(strict_types=1);

namespace RetryEngine\Tests\Unit;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;
use RetryEngine\Lifecycle\ChargeLifecycle;
use RetryEngine\Lifecycle\ChargeState;

final class Stage2LifecycleTest
{
    private function createAttempt(
        int $attemptNumber,
        string $status,
        float $amountEur = 10.0,
        string $createdAtIso = '2026-07-01T12:00:00+00:00',
        string $rc = '00'
    ): Attempt {
        return new Attempt(
            eventId: 'evt_' . uniqid(),
            attemptId: 'att_' . uniqid(),
            chargeId: 'ch_test',
            merchantId: 'm_101',
            customerId: 'cus_test',
            cardToken: 'tok_test12345678',
            cardBin: '411111',
            cardScheme: 'visa',
            cardType: 'credit',
            cardCountry: 'DE',
            amountMinor: (int)round($amountEur * 100),
            currency: 'EUR',
            createdAt: new DateTimeImmutable($createdAtIso),
            attemptNumber: $attemptNumber,
            status: $status,
            responseCode: $rc,
            merchantAdviceCode: null,
            gatewayDeclineCategory: $status === 'approved' ? '' : 'soft',
            receivedAt: new DateTimeImmutable($createdAtIso),
            amountEur: $amountEur,
            qualityStatus: 'valid'
        );
    }

    public function runAll(): void
    {
        $this->testOriginalApprovedInitially();
        $this->testOriginalDeclinedRetryApproved();
        $this->testOriginalDeclinedTwoDeclinedRetriesLostWithFee();
        $this->testApprovedRetryThenExtraDeclinedEventRemainsRecovered();
        $this->testIncompleteObservationSeptember30();
    }

    public function testOriginalApprovedInitially(): void
    {
        $att = $this->createAttempt(0, 'approved', 10.0);
        $lc = new ChargeLifecycle('ch_test', [$att]);

        assert($lc->getState() === ChargeState::APPROVED_INITIALLY, "State must be approved_initially");
        assert($lc->getGrossAtRiskEur() === 0.0, "Approved initially has no gross at risk");
        assert($lc->getRecoveredEur() === 0.0, "Approved initially is not in recovered-by-retry");
        assert($lc->getLostEur() === 0.0, "Approved initially is not lost");
        assert($lc->getHistoricalRetryCount() === 0, "No retries executed");
        echo "  [PASS] Stage 2: Original approved (€10) -> approved_initially, not in lost/recovered\n";
    }

    public function testOriginalDeclinedRetryApproved(): void
    {
        $att0 = $this->createAttempt(0, 'declined', 10.0, '2026-07-01T10:00:00+00:00', '51');
        $att1 = $this->createAttempt(1, 'approved', 10.0, '2026-07-02T10:00:00+00:00', '00');

        $lc = new ChargeLifecycle('ch_test', [$att0, $att1]);

        assert($lc->getState() === ChargeState::RECOVERED_BY_CURRENT_RETRY, "State must be recovered_by_current_retry");
        assert($lc->getRecoveredEur() === 10.0, "Recovered amount must be €10.0");
        assert($lc->getLostEur() === 0.0, "Lost amount must be €0.0");
        assert($lc->getHistoricalRetryCount() === 1, "Expected 1 retry");
        assert($lc->getHistoricalRetryCost(0.08) === 0.08, "Retry cost must be €0.08");
        assert($lc->getNetRecovery(0.08) === 9.92, "Net recovery must be €9.92");
        echo "  [PASS] Stage 2: Original declined + retry approved -> recovered_by_current_retry (€10.00)\n";
    }

    public function testOriginalDeclinedTwoDeclinedRetriesLostWithFee(): void
    {
        $att0 = $this->createAttempt(0, 'declined', 10.0, '2026-07-01T10:00:00+00:00', '51');
        $att1 = $this->createAttempt(1, 'declined', 10.0, '2026-07-02T10:00:00+00:00', '51');
        $att2 = $this->createAttempt(2, 'declined', 10.0, '2026-07-03T10:00:00+00:00', '51');

        $lc = new ChargeLifecycle('ch_test', [$att0, $att1, $att2]);

        assert($lc->getState() === ChargeState::LOST, "State must be lost");
        assert($lc->getLostEur() === 10.0, "Lost amount must be €10.0");
        assert($lc->getRecoveredEur() === 0.0, "Recovered amount must be €0.0");
        assert($lc->getHistoricalRetryCount() === 2, "Expected 2 retries");
        assert(abs($lc->getHistoricalRetryCost(0.08) - 0.16) < 0.0001, "Historical retry cost must be €0.16");
        assert(abs($lc->getNetRecovery(0.08) - (-0.16)) < 0.0001, "Net recovery must be -€0.16");
        echo "  [PASS] Stage 2: Original declined + 2 declined retries -> lost (cost €0.16)\n";
    }

    public function testApprovedRetryThenExtraDeclinedEventRemainsRecovered(): void
    {
        $att0 = $this->createAttempt(0, 'declined', 10.0, '2026-07-01T10:00:00+00:00', '51');
        $att1 = $this->createAttempt(1, 'approved', 10.0, '2026-07-02T10:00:00+00:00', '00');
        $att2 = $this->createAttempt(2, 'declined', 10.0, '2026-07-03T10:00:00+00:00', '51'); // stray event

        $lc = new ChargeLifecycle('ch_test', [$att0, $att1, $att2]);

        assert($lc->getState() === ChargeState::RECOVERED_BY_CURRENT_RETRY, "Charge must remain recovered");
        assert($lc->getRecoveredEur() === 10.0, "Recovered amount must remain €10.0");
        assert($lc->getWinningAttempt()->attemptNumber === 1, "Winning attempt was attempt 1");
        echo "  [PASS] Stage 2: Approved retry followed by stray decline remains recovered\n";
    }

    public function testIncompleteObservationSeptember30(): void
    {
        // Created on Sep 30, window ends Oct 1 (only 1 day remaining, buffer is 7 days)
        $att0 = $this->createAttempt(0, 'declined', 10.0, '2026-09-30T15:00:00+00:00', '51');
        $windowEnd = new DateTimeImmutable('2026-10-01T00:00:00+00:00');

        $lc = new ChargeLifecycle('ch_test', [$att0], $windowEnd, 7);

        assert($lc->getState() === ChargeState::INCOMPLETE_OBSERVATION, "State must be incomplete_observation");
        assert($lc->isIncomplete(), "isIncomplete must return true");
        assert(!$lc->isLost(), "Must not be classified as definitively lost");
        echo "  [PASS] Stage 2: September 30 unrecovered charge -> incomplete_observation\n";
    }
}
