<?php
declare(strict_types=1);

namespace RetryEngine\Tests\Unit;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;
use RetryEngine\Model\RecoveryModel;
use RetryEngine\Policy\Policy;

final class Stage4RecoveryModelTest
{
    private Policy $policy;
    private RecoveryModel $model;

    public function __construct()
    {
        $this->policy = Policy::createDefault();
        $this->model = new RecoveryModel($this->policy);
    }

    private function makeAttempt(float $amountEur, string $rc = '51', int $attNum = 0): Attempt
    {
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
            createdAt: new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            attemptNumber: $attNum,
            status: 'declined',
            responseCode: $rc,
            merchantAdviceCode: null,
            gatewayDeclineCategory: 'soft',
            receivedAt: new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            amountEur: $amountEur,
            qualityStatus: 'valid'
        );
    }

    public function runAll(): void
    {
        $this->testAmountOneEuroLowProbabilityNegativeNet();
        $this->testAmountTwentyEuroTenPercentPositiveNet();
        $this->testInsufficientSampleFallback();
    }

    public function testAmountOneEuroLowProbabilityNegativeNet(): void
    {
        // €1 amount, p_success = 0.01 (1%), fee = €0.08
        // Expected gross = 1 * 0.01 = 0.01
        // Expected net = 0.01 - 0.08 = -0.07 -> Not viable!
        $this->model->setSegmentProbability('51', 1, 'visa', 0.01, 100);

        $attempt = $this->makeAttempt(1.0, '51', 0);
        $result = $this->model->evaluateEconomicFilter($attempt, 1);

        assert($result['is_viable'] === false, "Economic filter must reject negative expected net");
        assert($result['reason'] === 'expected_net_value_negative');
        assert(abs($result['expected_net_eur'] - (-0.07)) < 0.001, "Expected net must be -€0.07");
        echo "  [PASS] Stage 4: Amount €1 with p=1% -> expected net -€0.07 (rejected)\n";
    }

    public function testAmountTwentyEuroTenPercentPositiveNet(): void
    {
        // €20 amount, p_success = 0.10 (10%), fee = €0.08
        // Expected gross = 20 * 0.10 = €2.00
        // Expected net = 2.00 - 0.08 = €1.92 -> Viable!
        $this->model->setSegmentProbability('51', 1, 'visa', 0.10, 100);

        $attempt = $this->makeAttempt(20.0, '51', 0);
        $result = $this->model->evaluateEconomicFilter($attempt, 1);

        assert($result['is_viable'] === true, "Economic filter must accept positive expected net");
        assert($result['reason'] === 'economically_viable');
        assert(abs($result['expected_net_eur'] - 1.92) < 0.001, "Expected net must be €1.92");
        echo "  [PASS] Stage 4: Amount €20 with p=10% -> expected net €1.92 (viable)\n";
    }

    public function testInsufficientSampleFallback(): void
    {
        // New model without segment stats
        $freshModel = new RecoveryModel($this->policy);
        $attempt = $this->makeAttempt(50.0, '99', 1); // code 99 has no observations

        $result = $freshModel->evaluateEconomicFilter($attempt, 2);

        assert($result['is_viable'] === false);
        assert($result['reason'] === 'insufficient_sample_size');
        echo "  [PASS] Stage 4: Segment with insufficient observations -> insufficient_sample_size\n";
    }
}
