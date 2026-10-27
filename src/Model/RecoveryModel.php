<?php
declare(strict_types=1);

namespace RetryEngine\Model;

use RetryEngine\Contract\Attempt;
use RetryEngine\Lifecycle\ChargeLifecycle;
use RetryEngine\Lifecycle\ChargeState;
use RetryEngine\Policy\Policy;

final class RecoveryModel
{
    /** @var array<string, array{total: int, successes: int, p_success: float}> */
    private array $segmentStats = [];

    /** @var array<string, array{total: int, successes: int, p_success: float}> */
    private array $codeStatsFallback = [];

    private float $globalSuccessRate = 0.15;
    private int $globalTotal = 0;

    public function __construct(
        private readonly Policy $policy
    ) {}

    /**
     * Train the model on completed cohorts (excluding incomplete observations).
     *
     * @param ChargeLifecycle[] $lifecycles
     * @param int|null $trainEndTimestamp
     */
    public function train(array $lifecycles, ?int $trainEndTimestamp = null): void
    {
        $segments = [];
        $codes = [];
        $totalAll = 0;
        $successAll = 0;

        foreach ($lifecycles as $lc) {
            // Exclude incomplete observations from training
            if ($lc->getState() === ChargeState::INCOMPLETE_OBSERVATION) {
                continue;
            }
            if ($lc->getState() === ChargeState::INVALID_DATA) {
                continue;
            }

            $orig = $lc->getOriginalAttempt();
            if ($orig === null || $orig->isApproved()) {
                continue; // only declined charges have retries
            }

            if ($trainEndTimestamp !== null && $orig->createdAt->getTimestamp() > $trainEndTimestamp) {
                continue; // holdout split
            }

            $attempts = $lc->getAttempts();
            for ($i = 1; $i < count($attempts); $i++) {
                $prev = $attempts[$i - 1];
                $curr = $attempts[$i];

                $rc = $prev->responseCode;
                $scheme = $prev->cardScheme;
                $attNum = $curr->attemptNumber;
                $segKey = "{$rc}|{$attNum}|{$scheme}";
                $codeKey = $rc;

                $isSuccess = $curr->isApproved();

                if (!isset($segments[$segKey])) {
                    $segments[$segKey] = ['total' => 0, 'successes' => 0];
                }
                $segments[$segKey]['total']++;
                if ($isSuccess) {
                    $segments[$segKey]['successes']++;
                }

                if (!isset($codes[$codeKey])) {
                    $codes[$codeKey] = ['total' => 0, 'successes' => 0];
                }
                $codes[$codeKey]['total']++;
                if ($isSuccess) {
                    $codes[$codeKey]['successes']++;
                }

                $totalAll++;
                if ($isSuccess) {
                    $successAll++;
                    break; // stop counting once approved
                }
            }
        }

        foreach ($segments as $k => $d) {
            $this->segmentStats[$k] = [
                'total' => $d['total'],
                'successes' => $d['successes'],
                'p_success' => $d['total'] > 0 ? $d['successes'] / $d['total'] : 0.0,
            ];
        }

        foreach ($codes as $k => $d) {
            $this->codeStatsFallback[$k] = [
                'total' => $d['total'],
                'successes' => $d['successes'],
                'p_success' => $d['total'] > 0 ? $d['successes'] / $d['total'] : 0.0,
            ];
        }

        $this->globalTotal = $totalAll;
        $this->globalSuccessRate = $totalAll > 0 ? $successAll / $totalAll : 0.15;
    }

    /**
     * Manually configure a segment probability for unit testing and deterministic checks.
     */
    public function setSegmentProbability(string $responseCode, int $attemptNumber, string $scheme, float $pSuccess, int $sampleSize = 100): void
    {
        $key = "{$responseCode}|{$attemptNumber}|{$scheme}";
        $this->segmentStats[$key] = [
            'total' => $sampleSize,
            'successes' => (int)round($sampleSize * $pSuccess),
            'p_success' => $pSuccess,
        ];
    }

    /**
     * Predicts success probability with conservative fallback for small samples.
     *
     * @return array{p_success: float, sample_size: int, tier: string, is_insufficient: bool}
     */
    public function predictSuccessProbability(string $responseCode, int $attemptNumber, string $scheme): array
    {
        $segKey = "{$responseCode}|{$attemptNumber}|{$scheme}";
        $minSample = $this->policy->minSampleSize;

        // Tier 1: Exact segment match (Code + Attempt + Scheme)
        if (isset($this->segmentStats[$segKey])) {
            $seg = $this->segmentStats[$segKey];
            if ($seg['total'] >= $minSample) {
                return [
                    'p_success' => $seg['p_success'],
                    'sample_size' => $seg['total'],
                    'tier' => 'exact_segment',
                    'is_insufficient' => false,
                ];
            }
        }

        // Tier 2: Broader fallback (Response Code level)
        if (isset($this->codeStatsFallback[$responseCode])) {
            $fallback = $this->codeStatsFallback[$responseCode];
            if ($fallback['total'] >= $minSample) {
                return [
                    'p_success' => $fallback['p_success'],
                    'sample_size' => $fallback['total'],
                    'tier' => 'code_fallback',
                    'is_insufficient' => false,
                ];
            }
        }

        // Tier 3: Insufficient sample size
        $sampleCount = $this->segmentStats[$segKey]['total'] ?? ($this->codeStatsFallback[$responseCode]['total'] ?? 0);
        return [
            'p_success' => 0.0,
            'sample_size' => $sampleCount,
            'tier' => 'insufficient_sample',
            'is_insufficient' => true,
        ];
    }

    /**
     * Evaluates economic viability of retrying:
     * Expected Recovered Value = amount_eur * p_success
     * Expected Net Value = expected_recovered_value - retry_fee (0.08)
     *
     * @return array{
     *     is_viable: bool,
     *     p_success: float,
     *     sample_size: int,
     *     tier: string,
     *     expected_recovered_eur: float,
     *     expected_fee_eur: float,
     *     expected_net_eur: float,
     *     reason: string
     * }
     */
    public function evaluateEconomicFilter(Attempt $attempt, int $nextAttemptNumber): array
    {
        $amountEur = $attempt->amountEur ?? 0.0;
        $fee = $this->policy->retryFeeEur;

        $prob = $this->predictSuccessProbability($attempt->responseCode, $nextAttemptNumber, $attempt->cardScheme);

        if ($prob['is_insufficient']) {
            return [
                'is_viable' => false,
                'p_success' => 0.0,
                'sample_size' => $prob['sample_size'],
                'tier' => $prob['tier'],
                'expected_recovered_eur' => 0.0,
                'expected_fee_eur' => $fee,
                'expected_net_eur' => -$fee,
                'reason' => 'insufficient_sample_size',
            ];
        }

        $pSuccess = $prob['p_success'];
        $expectedRecovered = round($amountEur * $pSuccess, 4);
        $expectedNet = round($expectedRecovered - $fee, 4);

        if ($expectedNet <= 0.0) {
            return [
                'is_viable' => false,
                'p_success' => $pSuccess,
                'sample_size' => $prob['sample_size'],
                'tier' => $prob['tier'],
                'expected_recovered_eur' => $expectedRecovered,
                'expected_fee_eur' => $fee,
                'expected_net_eur' => $expectedNet,
                'reason' => 'expected_net_value_negative',
            ];
        }

        return [
            'is_viable' => true,
            'p_success' => $pSuccess,
            'sample_size' => $prob['sample_size'],
            'tier' => $prob['tier'],
            'expected_recovered_eur' => $expectedRecovered,
            'expected_fee_eur' => $fee,
            'expected_net_eur' => $expectedNet,
            'reason' => 'economically_viable',
        ];
    }
}
