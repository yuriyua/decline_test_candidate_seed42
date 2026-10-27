<?php
declare(strict_types=1);

namespace RetryEngine\Report;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;
use RetryEngine\Engine\Decision;
use RetryEngine\Engine\RetryEngine;
use RetryEngine\Lifecycle\ChargeLifecycle;
use RetryEngine\Lifecycle\ChargeState;
use RetryEngine\Model\RecoveryModel;
use RetryEngine\Policy\Policy;
use RetryEngine\Quality\DataQualityReport;

final class AnalyticsReport
{
    /**
     * @param ChargeLifecycle[] $lifecycles
     * @param DataQualityReport $dqReport
     * @param Policy $policy
     */
    public function __construct(
        public readonly array $lifecycles,
        public readonly DataQualityReport $dqReport,
        public readonly Policy $policy
    ) {}

    /**
     * Overall financial metrics across 90-day window.
     */
    public function getGlobalMetrics(): array
    {
        $approvedInitiallyCount = 0;
        $approvedInitiallyEur = 0.0;
        $declinedInitiallyCount = 0;
        $grossAtRiskEur = 0.0;
        $recoveredCount = 0;
        $recoveredEur = 0.0;
        $lostCount = 0;
        $lostEur = 0.0;
        $incompleteCount = 0;
        $incompleteEur = 0.0;
        $historicalRetriesCount = 0;

        foreach ($this->lifecycles as $lc) {
            $state = $lc->getState();
            $orig = $lc->getOriginalAttempt();
            $origEur = $orig?->amountEur ?? 0.0;

            if ($state === ChargeState::APPROVED_INITIALLY) {
                $approvedInitiallyCount++;
                $approvedInitiallyEur += $origEur;
                continue;
            }

            $declinedInitiallyCount++;
            $grossAtRiskEur += $origEur;
            $historicalRetriesCount += $lc->getHistoricalRetryCount();

            if ($state === ChargeState::RECOVERED_BY_CURRENT_RETRY) {
                $recoveredCount++;
                $recoveredEur += $origEur;
            } elseif ($state === ChargeState::LOST) {
                $lostCount++;
                $lostEur += $origEur;
            } elseif ($state === ChargeState::INCOMPLETE_OBSERVATION) {
                $incompleteCount++;
                $incompleteEur += $origEur;
            }
        }

        $feePerRetry = $this->policy->retryFeeEur;
        $historicalRetryCostEur = round($historicalRetriesCount * $feePerRetry, 2);
        $netRecoveryEur = round($recoveredEur - $historicalRetryCostEur, 2);

        return [
            'total_charges' => count($this->lifecycles),
            'approved_initially_count' => $approvedInitiallyCount,
            'approved_initially_eur' => round($approvedInitiallyEur, 2),
            'primary_declines_count' => $declinedInitiallyCount,
            'gross_at_risk_eur' => round($grossAtRiskEur, 2),
            'recovered_count' => $recoveredCount,
            'recovered_eur' => round($recoveredEur, 2),
            'recovery_rate_pct' => $declinedInitiallyCount > 0 ? round(($recoveredCount / $declinedInitiallyCount) * 100, 2) : 0.0,
            'lost_count' => $lostCount,
            'lost_eur' => round($lostEur, 2),
            'incomplete_observation_count' => $incompleteCount,
            'incomplete_observation_eur' => round($incompleteEur, 2),
            'historical_retry_attempts' => $historicalRetriesCount,
            'historical_retry_fees_eur' => $historicalRetryCostEur,
            'net_recovery_eur' => $netRecoveryEur,
            'excluded_dq_eur' => round($this->dqReport->excludedEur, 2),
        ];
    }

    /**
     * Compute breakdowns by merchant, currency, scheme, response_code, gateway_category.
     */
    public function getBreakdownBy(string $dimension): array
    {
        $groups = [];
        $feePerRetry = $this->policy->retryFeeEur;

        foreach ($this->lifecycles as $lc) {
            $orig = $lc->getOriginalAttempt();
            if ($orig === null || $orig->isApproved()) {
                continue; // only declined charges contribute to decline risk and recovery
            }

            $key = match ($dimension) {
                'merchant' => $orig->merchantId,
                'currency' => $orig->currency,
                'scheme' => $orig->cardScheme,
                'response_code' => $orig->responseCode,
                'gateway_category' => $orig->gatewayDeclineCategory ?: 'unspecified',
                default => 'other',
            };

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'declines' => 0,
                    'gross_at_risk_eur' => 0.0,
                    'recovered_count' => 0,
                    'recovered_eur' => 0.0,
                    'lost_count' => 0,
                    'lost_eur' => 0.0,
                    'retries_count' => 0,
                    'retries_fees_eur' => 0.0,
                    'net_recovery_eur' => 0.0,
                    'recovery_rate_pct' => 0.0,
                ];
            }

            $eur = $orig->amountEur ?? 0.0;
            $retries = $lc->getHistoricalRetryCount();

            $groups[$key]['declines']++;
            $groups[$key]['gross_at_risk_eur'] += $eur;
            $groups[$key]['retries_count'] += $retries;

            if ($lc->getState() === ChargeState::RECOVERED_BY_CURRENT_RETRY) {
                $groups[$key]['recovered_count']++;
                $groups[$key]['recovered_eur'] += $eur;
            } elseif ($lc->getState() === ChargeState::LOST) {
                $groups[$key]['lost_count']++;
                $groups[$key]['lost_eur'] += $eur;
            }
        }

        foreach ($groups as $k => &$g) {
            $fees = round($g['retries_count'] * $feePerRetry, 2);
            $g['retries_fees_eur'] = $fees;
            $g['net_recovery_eur'] = round($g['recovered_eur'] - $fees, 2);
            $g['gross_at_risk_eur'] = round($g['gross_at_risk_eur'], 2);
            $g['recovered_eur'] = round($g['recovered_eur'], 2);
            $g['lost_eur'] = round($g['lost_eur'], 2);
            $g['recovery_rate_pct'] = $g['declines'] > 0 ? round(($g['recovered_count'] / $g['declines']) * 100, 2) : 0.0;
        }
        unset($g);

        uasort($groups, fn($a, $b) => $b['gross_at_risk_eur'] <=> $a['gross_at_risk_eur']);
        return $groups;
    }

    /**
     * Conduct time-based holdout evaluation comparing baseline historical retries vs candidate engine decisions.
     */
    public function evaluateHoldoutCohort(RecoveryModel $recoveryModel): array
    {
        $tTrainEnd = strtotime('2026-08-15T23:59:59+00:00');
        $tHoldoutStart = strtotime('2026-08-16T00:00:00+00:00');
        $tHoldoutEnd = strtotime('2026-09-23T23:59:59+00:00'); // excludes last 7 days incomplete observation

        // 1. Train model on train cohort
        $recoveryModel->train($this->lifecycles, $tTrainEnd);

        // 2. Evaluate on Holdout cohort
        $engine = new RetryEngine($recoveryModel);
        $fee = $this->policy->retryFeeEur;

        $holdoutCharges = 0;
        $baselineRetries = 0;
        $baselineRecoveredEur = 0.0;
        $baselineFeesEur = 0.0;

        $candidateOpportunityEur = 0.0;
        $candidateRetries = 0;
        $candidateRecoveredEur = 0.0;
        $candidateFeesEur = 0.0;
        $avoidedHardRetries = 0;

        foreach ($this->lifecycles as $lc) {
            $orig = $lc->getOriginalAttempt();
            if ($orig === null || $orig->isApproved()) {
                continue;
            }

            $origTs = $orig->createdAt->getTimestamp();
            if ($origTs < $tHoldoutStart || $origTs > $tHoldoutEnd) {
                continue; // outside holdout window
            }

            $holdoutCharges++;
            $amountEur = $orig->amountEur ?? 0.0;

            // Historical baseline performance
            $retriesDone = $lc->getHistoricalRetryCount();
            $baselineRetries += $retriesDone;
            $baselineFeesEur += $retriesDone * $fee;
            if ($lc->isRecovered()) {
                $baselineRecoveredEur += $amountEur;
            }

            // Candidate engine evaluation on this charge
            $attempts = $lc->getAttempts();
            $chargeHistory = [];
            $wasRecoveredByCandidate = false;

            for ($i = 0; $i < count($attempts); $i++) {
                $curr = $attempts[$i];

                if ($i === 0) {
                    $chargeHistory[] = $curr;
                    continue;
                }

                $prev = $attempts[$i - 1];
                // Ask candidate engine for decision
                $decision = $engine->decideNextRetry($prev, $chargeHistory, $this->policy, $prev->createdAt);

                if ($decision->isRetry()) {
                    $candidateRetries++;
                    $candidateOpportunityEur += $amountEur;
                    $candidateFeesEur += $fee;

                    if ($curr->isApproved()) {
                        $candidateRecoveredEur += $amountEur;
                        $wasRecoveredByCandidate = true;
                        break; // recovered, lifecycle finished
                    }
                } else {
                    // Engine decided DO NOT RETRY (e.g. hard decline, zero success code, exhausted, negative net)
                    $avoidedHardRetries++;
                    break; // Stop retrying
                }

                $chargeHistory[] = $curr;
            }
        }

        $baselineNet = round($baselineRecoveredEur - $baselineFeesEur, 2);
        $candidateNet = round($candidateRecoveredEur - $candidateFeesEur, 2);
        $incrementalNetGain = round($candidateNet - $baselineNet, 2);
        $feesSaved = round($baselineFeesEur - $candidateFeesEur, 2);

        return [
            'period' => '2026-08-16 to 2026-09-23 (Holdout Cohort, 39 days)',
            'holdout_declined_charges' => $holdoutCharges,
            'baseline_retry_count' => $baselineRetries,
            'baseline_retry_fees_eur' => round($baselineFeesEur, 2),
            'baseline_recovered_eur' => round($baselineRecoveredEur, 2),
            'baseline_net_recovery_eur' => $baselineNet,
            'candidate_retry_count' => $candidateRetries,
            'candidate_retry_fees_eur' => round($candidateFeesEur, 2),
            'candidate_recovered_eur' => round($candidateRecoveredEur, 2),
            'candidate_net_recovery_eur' => $candidateNet,
            'incremental_net_gain_eur' => $incrementalNetGain,
            'retries_saved_count' => ($baselineRetries - $candidateRetries),
            'fees_saved_eur' => $feesSaved,
        ];
    }
}
