<?php
declare(strict_types=1);

namespace RetryEngine\Tests\Unit;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;
use RetryEngine\Policy\DeclineClassifier;
use RetryEngine\Policy\Policy;

final class Stage3PolicyClassifierTest
{
    private Policy $policy;
    private DeclineClassifier $classifier;

    public function __construct()
    {
        $this->policy = Policy::createDefault();
        $this->classifier = new DeclineClassifier($this->policy);
    }

    private function makeAttempt(
        int $attemptNum,
        string $status,
        string $rc,
        ?string $mac = null,
        string $cat = 'soft',
        string $createdIso = '2026-07-01T10:00:00+00:00'
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
            amountMinor: 2999,
            currency: 'EUR',
            createdAt: new DateTimeImmutable($createdIso),
            attemptNumber: $attemptNum,
            status: $status,
            responseCode: $rc,
            merchantAdviceCode: $mac,
            gatewayDeclineCategory: $cat,
            receivedAt: new DateTimeImmutable($createdIso),
            amountEur: 29.99,
            qualityStatus: 'valid'
        );
    }

    public function runAll(): void
    {
        $this->testStatusApprovedDoNotRetryAlreadyApproved();
        $this->testHardDeclineBlocked();
        $this->testSoftDeclineScheduled24h();
        $this->testAttemptLimitReached();
        $this->testUnknownResponseCodeSoftGatewayManualReview();
        $this->testRetryWindowExpired();
        $this->testMastercardMac21HardDecline();
    }

    public function testStatusApprovedDoNotRetryAlreadyApproved(): void
    {
        $att = $this->makeAttempt(0, 'approved', '00');
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');

        $result = $this->classifier->classify($att, [], $now);

        assert($result['category'] === DeclineClassifier::CATEGORY_DO_NOT_RETRY_HARD);
        assert($result['reason_code'] === 'charge_already_approved');
        assert($result['retry_at'] === null);
        echo "  [PASS] Stage 3: Approved attempt -> do_not_retry (charge_already_approved)\n";
    }

    public function testHardDeclineBlocked(): void
    {
        // 14 = invalid card number, 41 = lost card, 43 = stolen card, 54 = expired card, R1 = revoked
        foreach (['14', '41', '43', '54', 'R1', '04', '07', '1A'] as $code) {
            $att = $this->makeAttempt(0, 'declined', $code, null, 'hard');
            $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');

            $result = $this->classifier->classify($att, [], $now);

            assert($result['category'] === DeclineClassifier::CATEGORY_DO_NOT_RETRY_HARD, "Code {$code} must be hard decline");
            assert($result['reason_code'] === 'hard_decline', "Code {$code} reason must be hard_decline");
            assert($result['retry_at'] === null);
        }
        echo "  [PASS] Stage 3: Blocked hard decline codes (14, 41, 43, 54, R1, 1A) -> do_not_retry (hard_decline)\n";
    }

    public function testSoftDeclineScheduled24h(): void
    {
        $createdIso = '2026-07-01T10:00:00+00:00';
        $att = $this->makeAttempt(0, 'declined', '51', null, 'soft', $createdIso);
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');

        $result = $this->classifier->classify($att, [], $now);

        assert($result['category'] === DeclineClassifier::CATEGORY_RETRY_SOFT);
        assert($result['reason_code'] === 'retry_soft_eligible');
        assert($result['retry_at'] !== null);

        $expectedTs = strtotime($createdIso) + 86400; // +24 hours
        assert($result['retry_at']->getTimestamp() === $expectedTs, "Retry time must be created_at + 24h");
        echo "  [PASS] Stage 3: Soft decline (51), attempt 0 -> retry_at = created_at + 24h\n";
    }

    public function testAttemptLimitReached(): void
    {
        // Policy has max 6 attempts. Create 6 prior attempts
        $history = [];
        for ($i = 0; $i < 6; $i++) {
            $history[] = $this->makeAttempt($i, 'declined', '51');
        }
        $latest = $this->makeAttempt(5, 'declined', '51');
        $now = new DateTimeImmutable('2026-07-10T10:00:00+00:00');

        $result = $this->classifier->classify($latest, $history, $now);

        assert($result['category'] === DeclineClassifier::CATEGORY_EXHAUSTED);
        assert($result['reason_code'] === 'attempt_limit_reached');
        assert($result['retry_at'] === null);
        echo "  [PASS] Stage 3: Attempt limit reached -> do_not_retry (attempt_limit_reached)\n";
    }

    public function testUnknownResponseCodeSoftGatewayManualReview(): void
    {
        // Code 99 is unknown/unallowlisted, but gateway labels it soft
        $att = $this->makeAttempt(0, 'declined', '99', null, 'soft');
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');

        $result = $this->classifier->classify($att, [], $now);

        assert($result['category'] === DeclineClassifier::CATEGORY_MANUAL_REVIEW);
        assert($result['reason_code'] === 'unrecognized_response_code');
        assert($result['retry_at'] === null);
        echo "  [PASS] Stage 3: Unknown response code labeled soft -> manual_review (unrecognized_response_code)\n";
    }

    public function testRetryWindowExpired(): void
    {
        // Original attempt 35 days ago (scheme window is 30 days = 2,592,000s)
        $orig = $this->makeAttempt(0, 'declined', '51', null, 'soft', '2026-07-01T10:00:00+00:00');
        $now = new DateTimeImmutable('2026-08-05T10:00:00+00:00'); // 35 days later

        $result = $this->classifier->classify($orig, [$orig], $now);

        assert($result['category'] === DeclineClassifier::CATEGORY_EXHAUSTED);
        assert($result['reason_code'] === 'retry_window_expired');
        echo "  [PASS] Stage 3: Retry time outside scheme window -> do_not_retry (retry_window_expired)\n";
    }

    public function testMastercardMac21HardDecline(): void
    {
        $att = $this->makeAttempt(0, 'declined', '05', '21', 'soft');
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');

        $result = $this->classifier->classify($att, [], $now);

        assert($result['category'] === DeclineClassifier::CATEGORY_DO_NOT_RETRY_HARD);
        assert($result['reason_code'] === 'hard_decline_mac_21');
        echo "  [PASS] Stage 3: Mastercard MAC 21 payment cancellation -> do_not_retry_hard\n";
    }
}
