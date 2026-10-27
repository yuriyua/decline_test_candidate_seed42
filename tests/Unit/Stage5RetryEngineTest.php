<?php
declare(strict_types=1);

namespace RetryEngine\Tests\Unit;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;
use RetryEngine\Engine\AuditLogger;
use RetryEngine\Engine\Decision;
use RetryEngine\Engine\RetryEngine;
use RetryEngine\Model\RecoveryModel;
use RetryEngine\Policy\Policy;

final class Stage5RetryEngineTest
{
    private Policy $policy;
    private RecoveryModel $model;
    private RetryEngine $engine;
    private AuditLogger $logger;

    public function __construct()
    {
        $this->policy = Policy::createDefault();
        $this->model = new RecoveryModel($this->policy);
        // Pre-configure high probability segment for code 51
        $this->model->setSegmentProbability('51', 1, 'visa', 0.20, 100);
        $this->logger = new AuditLogger();
        $this->engine = new RetryEngine($this->model, $this->logger);
    }

    private function makeAttempt(
        int $attNum,
        string $status,
        string $rc = '51',
        float $eur = 30.0,
        string $createdIso = '2026-07-01T10:00:00+00:00'
    ): Attempt {
        return new Attempt(
            eventId: 'evt_' . uniqid(),
            attemptId: 'att_' . uniqid(),
            chargeId: 'ch_idem_test',
            merchantId: 'm_101',
            customerId: 'cus_test',
            cardToken: 'tok_07fdc4e4038a5950',
            cardBin: '566138',
            cardScheme: 'visa',
            cardType: 'credit',
            cardCountry: 'DE',
            amountMinor: (int)round($eur * 100),
            currency: 'EUR',
            createdAt: new DateTimeImmutable($createdIso),
            attemptNumber: $attNum,
            status: $status,
            responseCode: $rc,
            merchantAdviceCode: null,
            gatewayDeclineCategory: $status === 'approved' ? '' : 'soft',
            receivedAt: new DateTimeImmutable($createdIso),
            amountEur: $eur,
            qualityStatus: 'valid'
        );
    }

    public function runAll(): void
    {
        $this->testIdempotencySameInputReturnsIdenticalDecision();
        $this->testNowLaterThanComputedPolicyTimeShiftsToNow();
        $this->testHistoryContainsApprovedChargeAlreadyApproved();
        $this->testCardAndTokenMaskingInAuditLog();
    }

    public function testIdempotencySameInputReturnsIdenticalDecision(): void
    {
        $attempt = $this->makeAttempt(0, 'declined', '51', 30.0);
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');

        $decision1 = $this->engine->decideNextRetry($attempt, [$attempt], $this->policy, $now);
        $decision2 = $this->engine->decideNextRetry($attempt, [$attempt], $this->policy, $now);

        assert($decision1->action === $decision2->action, "Actions must be identical");
        assert($decision1->reasonCode === $decision2->reasonCode, "Reason codes must match");
        assert($decision1->retryAt?->getTimestamp() === $decision2->retryAt?->getTimestamp(), "Retry timestamps must be identical");
        assert($decision1->expectedNetValue === $decision2->expectedNetValue, "Expected net values must match");
        echo "  [PASS] Stage 5: Idempotency - duplicate calls return identical schedule decision\n";
    }

    public function testNowLaterThanComputedPolicyTimeShiftsToNow(): void
    {
        // Created at 10:00, policy delay +24h = 10:00 next day
        // But 'now' is 15:00 next day (5 hours later)
        $attempt = $this->makeAttempt(0, 'declined', '51', 30.0, '2026-07-01T10:00:00+00:00');
        $now = new DateTimeImmutable('2026-07-02T15:00:00+00:00');

        $decision = $this->engine->decideNextRetry($attempt, [$attempt], $this->policy, $now);

        assert($decision->action === Decision::ACTION_RETRY);
        assert($decision->retryAt !== null);
        assert($decision->retryAt->getTimestamp() === $now->getTimestamp(), "Retry time must not be scheduled in the past; shifted to now");
        echo "  [PASS] Stage 5: 'now' later than policy retry time shifts to current valid moment\n";
    }

    public function testHistoryContainsApprovedChargeAlreadyApproved(): void
    {
        $declined = $this->makeAttempt(0, 'declined', '51');
        $approved = $this->makeAttempt(1, 'approved', '00');

        $now = new DateTimeImmutable('2026-07-03T10:00:00+00:00');
        $decision = $this->engine->decideNextRetry($declined, [$declined, $approved], $this->policy, $now);

        assert($decision->action === Decision::ACTION_DO_NOT_RETRY);
        assert($decision->reasonCode === 'charge_already_approved');
        echo "  [PASS] Stage 5: History contains approved attempt -> do_not_retry: charge_already_approved\n";
    }

    public function testCardAndTokenMaskingInAuditLog(): void
    {
        $this->logger->clear();
        $attempt = $this->makeAttempt(0, 'declined', '51', 30.0);
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');

        $this->engine->decideNextRetry($attempt, [$attempt], $this->policy, $now);
        $logs = $this->logger->getLogs();

        assert(count($logs) === 1);
        $record = $logs[0];

        // Must NOT contain full token 'tok_07fdc4e4038a5950'
        assert(!in_array('tok_07fdc4e4038a5950', $record, true), "Full card token must NOT exist in audit log");
        // Must contain masked token 'tok_...5950'
        assert($record['masked_card_token'] === 'tok_...5950', "Token must be masked");
        // Must contain masked BIN '566***'
        assert($record['masked_card_bin'] === '566***', "BIN must be masked");
        assert($record['reason_code'] === 'retry_scheduled');
        assert($record['policy_version'] === $this->policy->version);
        echo "  [PASS] Stage 5: PCI security - Card token & BIN strictly masked in audit logs\n";
    }
}
