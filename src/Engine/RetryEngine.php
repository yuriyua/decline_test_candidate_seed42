<?php
declare(strict_types=1);

namespace RetryEngine\Engine;

use DateTimeImmutable;
use RetryEngine\Contract\Attempt;
use RetryEngine\Model\RecoveryModel;
use RetryEngine\Policy\DeclineClassifier;
use RetryEngine\Policy\Policy;

final class RetryEngine
{
    private AuditLogger $auditLogger;
    private ?RecoveryModel $recoveryModel;

    public function __construct(
        ?RecoveryModel $recoveryModel = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->recoveryModel = $recoveryModel;
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    public function getAuditLogger(): AuditLogger
    {
        return $this->auditLogger;
    }

    /**
     * Pure decision function for subscription payment retry scheduling.
     *
     * @param Attempt $declinedAttempt The newly declined attempt to evaluate
     * @param Attempt[] $chargeHistory Prior attempts for this charge_id
     * @param Policy $policy The versioned policy configuration
     * @param DateTimeImmutable $now Current evaluation time (UTC)
     * @return Decision
     */
    public function decideNextRetry(
        Attempt $declinedAttempt,
        array $chargeHistory,
        Policy $policy,
        DateTimeImmutable $now
    ): Decision {
        // --- Step 1: Input Validation & History Deduplication ---
        if ($declinedAttempt->amountMinor <= 0) {
            $decision = new Decision(
                action: Decision::ACTION_DO_NOT_RETRY,
                retryAt: null,
                reasonCode: 'invalid_amount',
                explanation: 'Declined attempt has invalid non-positive amount (' . $declinedAttempt->amountMinor . ').',
                policyVersion: $policy->version,
                chargeId: $declinedAttempt->chargeId,
                scheduledAttemptNumber: null
            );
            $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
            return $decision;
        }

        // Deduplicate history by event_id
        $dedupedHistory = [];
        foreach ($chargeHistory as $h) {
            $dedupedHistory[$h->eventId] = $h;
        }
        // Ensure consistent chronological order
        usort($dedupedHistory, function (Attempt $a, Attempt $b) {
            $tA = $a->createdAt->getTimestamp();
            $tB = $b->createdAt->getTimestamp();
            if ($tA !== $tB) {
                return $tA <=> $tB;
            }
            if ($a->attemptNumber !== $b->attemptNumber) {
                return $a->attemptNumber <=> $b->attemptNumber;
            }
            return $a->receivedAt->getTimestamp() <=> $b->receivedAt->getTimestamp();
        });

        // --- Step 2: Check if Charge is Already Approved ---
        if ($declinedAttempt->isApproved()) {
            $decision = new Decision(
                action: Decision::ACTION_DO_NOT_RETRY,
                retryAt: null,
                reasonCode: 'charge_already_approved',
                explanation: 'Current attempt is already approved; no further retry needed.',
                policyVersion: $policy->version,
                chargeId: $declinedAttempt->chargeId,
                scheduledAttemptNumber: null
            );
            $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
            return $decision;
        }

        foreach ($dedupedHistory as $h) {
            if ($h->isApproved()) {
                $decision = new Decision(
                    action: Decision::ACTION_DO_NOT_RETRY,
                    retryAt: null,
                    reasonCode: 'charge_already_approved',
                    explanation: 'Charge was already approved on prior attempt ' . $h->attemptNumber . '; lifecycle finished.',
                    policyVersion: $policy->version,
                    chargeId: $declinedAttempt->chargeId,
                    scheduledAttemptNumber: null
                );
                $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
                return $decision;
            }
        }

        // Check Kill Switch (Global or Merchant-level)
        if ($policy->isMerchantKilled($declinedAttempt->merchantId)) {
            $decision = new Decision(
                action: Decision::ACTION_DO_NOT_RETRY,
                retryAt: null,
                reasonCode: 'kill_switch_active',
                explanation: 'Retry execution is disabled by active kill switch for merchant ' . $declinedAttempt->merchantId . '.',
                policyVersion: $policy->version,
                chargeId: $declinedAttempt->chargeId,
                scheduledAttemptNumber: null
            );
            $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
            return $decision;
        }

        // --- Step 3: Determine Final / Soft / Manual Category ---
        $classifier = new DeclineClassifier($policy);
        $classification = $classifier->classify($declinedAttempt, $dedupedHistory, $now);

        if ($classification['category'] === DeclineClassifier::CATEGORY_DO_NOT_RETRY_HARD) {
            $decision = new Decision(
                action: Decision::ACTION_DO_NOT_RETRY,
                retryAt: null,
                reasonCode: $classification['reason_code'],
                explanation: $classification['explanation'],
                policyVersion: $policy->version,
                chargeId: $declinedAttempt->chargeId,
                scheduledAttemptNumber: null
            );
            $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
            return $decision;
        }

        if ($classification['category'] === DeclineClassifier::CATEGORY_EXHAUSTED) {
            $decision = new Decision(
                action: Decision::ACTION_DO_NOT_RETRY,
                retryAt: null,
                reasonCode: $classification['reason_code'],
                explanation: $classification['explanation'],
                policyVersion: $policy->version,
                chargeId: $declinedAttempt->chargeId,
                scheduledAttemptNumber: null
            );
            $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
            return $decision;
        }

        if ($classification['category'] === DeclineClassifier::CATEGORY_MANUAL_REVIEW) {
            $decision = new Decision(
                action: Decision::ACTION_MANUAL_REVIEW,
                retryAt: null,
                reasonCode: $classification['reason_code'],
                explanation: $classification['explanation'],
                policyVersion: $policy->version,
                chargeId: $declinedAttempt->chargeId,
                scheduledAttemptNumber: null
            );
            $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
            return $decision;
        }

        // --- Step 4 & 5: Check Scheme Limits & Scheduled Timing ---
        $retryAt = $classification['retry_at'];
        if ($retryAt === null) {
            $decision = new Decision(
                action: Decision::ACTION_DO_NOT_RETRY,
                retryAt: null,
                reasonCode: 'scheduling_unavailable',
                explanation: 'No valid retry schedule could be computed.',
                policyVersion: $policy->version,
                chargeId: $declinedAttempt->chargeId,
                scheduledAttemptNumber: null
            );
            $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
            return $decision;
        }

        // Ensure retryAt is not in the past relative to $now
        if ($retryAt->getTimestamp() < $now->getTimestamp()) {
            $retryAt = $now;
        }

        // Verify scheduled time does not violate scheme window
        $originalCreatedAt = $declinedAttempt->createdAt;
        foreach ($dedupedHistory as $h) {
            if ($h->isOriginal()) {
                $originalCreatedAt = $h->createdAt;
                break;
            }
        }
        $maxWindow = $policy->getRetryWindow($declinedAttempt->cardScheme);
        if ($retryAt->getTimestamp() > ($originalCreatedAt->getTimestamp() + $maxWindow)) {
            $decision = new Decision(
                action: Decision::ACTION_DO_NOT_RETRY,
                retryAt: null,
                reasonCode: 'retry_window_expired',
                explanation: 'Computed retry time exceeds maximum scheme retry window of ' . $maxWindow . 's.',
                policyVersion: $policy->version,
                chargeId: $declinedAttempt->chargeId,
                scheduledAttemptNumber: null
            );
            $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
            return $decision;
        }

        // Next scheduled attempt number
        $nextAttemptNumber = $declinedAttempt->attemptNumber + 1;

        // --- Step 6: Economic Filter & Expected Net Value ---
        $pSuccess = null;
        $expectedNetValue = null;

        if ($this->recoveryModel !== null) {
            $econ = $this->recoveryModel->evaluateEconomicFilter($declinedAttempt, $nextAttemptNumber);
            $pSuccess = $econ['p_success'];
            $expectedNetValue = $econ['expected_net_eur'];

            if (!$econ['is_viable']) {
                if ($econ['reason'] === 'insufficient_sample_size') {
                    $decision = new Decision(
                        action: Decision::ACTION_MANUAL_REVIEW,
                        retryAt: null,
                        reasonCode: 'insufficient_statistical_sample',
                        explanation: 'Segment has fewer observations than minimum threshold (' . $policy->minSampleSize . '); routing to manual review.',
                        policyVersion: $policy->version,
                        expectedNetValue: $expectedNetValue,
                        pSuccess: $pSuccess,
                        chargeId: $declinedAttempt->chargeId,
                        scheduledAttemptNumber: null
                    );
                    $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
                    return $decision;
                }

                $decision = new Decision(
                    action: Decision::ACTION_DO_NOT_RETRY,
                    retryAt: null,
                    reasonCode: 'expected_net_value_negative',
                    explanation: sprintf(
                        'Expected recovery (€%.2f) is less than retry fee (€%.2f); expected net value is negative (€%.2f).',
                        $econ['expected_recovered_eur'],
                        $econ['expected_fee_eur'],
                        $expectedNetValue
                    ),
                    policyVersion: $policy->version,
                    expectedNetValue: $expectedNetValue,
                    pSuccess: $pSuccess,
                    chargeId: $declinedAttempt->chargeId,
                    scheduledAttemptNumber: null
                );
                $this->auditLogger->logDecision($declinedAttempt, $decision, $now);
                return $decision;
            }
        }

        // --- Step 7: Return Deterministic Retry Decision ---
        $decision = new Decision(
            action: Decision::ACTION_RETRY,
            retryAt: $retryAt,
            reasonCode: 'retry_scheduled',
            explanation: sprintf(
                'Retry #%d scheduled at %s UTC (policy %s).',
                $nextAttemptNumber,
                $retryAt->format(DATE_ATOM),
                $policy->version
            ),
            policyVersion: $policy->version,
            expectedNetValue: $expectedNetValue,
            pSuccess: $pSuccess,
            chargeId: $declinedAttempt->chargeId,
            scheduledAttemptNumber: $nextAttemptNumber
        );

        // --- Step 8: Record Audit Event Without Sensitive Data ---
        $this->auditLogger->logDecision($declinedAttempt, $decision, $now);

        return $decision;
    }
}
