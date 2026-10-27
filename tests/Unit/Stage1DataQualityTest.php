<?php
declare(strict_types=1);

namespace RetryEngine\Tests\Unit;

use RetryEngine\DataLoader;
use RetryEngine\Quality\DataQualityReport;

final class Stage1DataQualityTest
{
    private DataLoader $loader;

    public function __construct()
    {
        $this->loader = new DataLoader();
    }

    public function runAll(): void
    {
        $this->testDuplicateEventsOneCountedOneDuplicate();
        $this->testConflictingEventsQuarantined();
        $this->testNegativeOrZeroAmountRejected();
        $this->testMissingFxRateProducesDataQualityError();
        $this->testOrphanRetryMarked();
        $this->testValidEurMinorUnitConversion();
    }

    public function testDuplicateEventsOneCountedOneDuplicate(): void
    {
        $csvContent = "event_id,attempt_id,charge_id,merchant_id,customer_id,card_token,card_bin,card_scheme,card_type,card_country,amount_minor,currency,created_at,attempt_number,status,response_code,merchant_advice_code,gateway_decline_category,received_at\n"
            . "evt_1,att_1,ch_1,m_101,cus_1,tok_12345678,411111,visa,credit,US,1000,EUR,2026-07-01T00:00:00+00:00,0,approved,00,,,2026-07-01T00:00:05+00:00\n"
            . "evt_1,att_1,ch_1,m_101,cus_1,tok_12345678,411111,visa,credit,US,1000,EUR,2026-07-01T00:00:00+00:00,0,approved,00,,,2026-07-01T00:00:10+00:00\n";

        $tmp = tempnam(sys_get_temp_dir(), 'test_dq_dup_');
        file_put_contents($tmp, $csvContent);

        $dq = new DataQualityReport();
        $result = $this->loader->loadAttempts($tmp, $dq);
        unlink($tmp);

        assert(count($result['attempts']) === 1, "Expected 1 valid attempt processed");
        assert($dq->duplicateEventsCount === 1, "Expected 1 duplicate event recorded");
        assert($dq->conflictDuplicatesCount === 0, "Expected 0 conflict duplicates");
        echo "  [PASS] Stage 1: Duplicate events - one counted, one duplicate\n";
    }

    public function testConflictingEventsQuarantined(): void
    {
        // Same event_id, different status (e.g. approved vs declined)
        $csvContent = "event_id,attempt_id,charge_id,merchant_id,customer_id,card_token,card_bin,card_scheme,card_type,card_country,amount_minor,currency,created_at,attempt_number,status,response_code,merchant_advice_code,gateway_decline_category,received_at\n"
            . "evt_conflict,att_1,ch_1,m_101,cus_1,tok_12345678,411111,visa,credit,US,1000,EUR,2026-07-01T00:00:00+00:00,0,approved,00,,,2026-07-01T00:00:05+00:00\n"
            . "evt_conflict,att_1,ch_1,m_101,cus_1,tok_12345678,411111,visa,credit,US,1000,EUR,2026-07-01T00:00:00+00:00,0,declined,05,,soft,2026-07-01T00:00:10+00:00\n";

        $tmp = tempnam(sys_get_temp_dir(), 'test_dq_conf_');
        file_put_contents($tmp, $csvContent);

        $dq = new DataQualityReport();
        $result = $this->loader->loadAttempts($tmp, $dq);
        unlink($tmp);

        assert(count($result['attempts']) === 0, "Conflicting event must be excluded from valid attempts");
        assert($dq->conflictDuplicatesCount === 1, "Expected 1 conflict duplicate");
        assert(isset($dq->quarantinedEvents['evt_conflict']), "Event must be quarantined");
        echo "  [PASS] Stage 1: Conflicting event_id payloads quarantined and excluded\n";
    }

    public function testNegativeOrZeroAmountRejected(): void
    {
        $csvContent = "event_id,attempt_id,charge_id,merchant_id,customer_id,card_token,card_bin,card_scheme,card_type,card_country,amount_minor,currency,created_at,attempt_number,status,response_code,merchant_advice_code,gateway_decline_category,received_at\n"
            . "evt_zero,att_1,ch_1,m_101,cus_1,tok_12345678,411111,visa,credit,US,0,EUR,2026-07-01T00:00:00+00:00,0,approved,00,,,2026-07-01T00:00:05+00:00\n"
            . "evt_neg,att_2,ch_2,m_101,cus_1,tok_12345678,411111,visa,credit,US,-500,EUR,2026-07-01T00:00:00+00:00,0,approved,00,,,2026-07-01T00:00:05+00:00\n";

        $tmp = tempnam(sys_get_temp_dir(), 'test_dq_rej_');
        file_put_contents($tmp, $csvContent);

        $dq = new DataQualityReport();
        $result = $this->loader->loadAttempts($tmp, $dq);
        unlink($tmp);

        assert(count($result['attempts']) === 0, "Non-positive amounts must be rejected");
        assert($dq->invalidAmountCount === 2, "Expected 2 rejected invalid amounts");
        echo "  [PASS] Stage 1: Zero or negative amounts rejected\n";
    }

    public function testMissingFxRateProducesDataQualityError(): void
    {
        // Try converting BRL or an unrated currency/date
        $loader = new DataLoader();
        $eur = $loader->convertToEur(1000, 'USD', '2099-01-01');
        assert($eur === null, "Missing FX rate must return null");

        $unknownCurrencyEur = $loader->convertToEur(1000, 'XYZ', '2026-07-01');
        assert($unknownCurrencyEur === null, "Unknown currency exponent must return null");
        echo "  [PASS] Stage 1: Missing FX rate / unknown currency handled as data_quality_error\n";
    }

    public function testOrphanRetryMarked(): void
    {
        $csvContent = "event_id,attempt_id,charge_id,merchant_id,customer_id,card_token,card_bin,card_scheme,card_type,card_country,amount_minor,currency,created_at,attempt_number,status,response_code,merchant_advice_code,gateway_decline_category,received_at\n"
            . "evt_orphan,att_2,ch_orphan,m_101,cus_1,tok_12345678,411111,visa,credit,US,1000,EUR,2026-07-01T00:00:00+00:00,1,declined,51,,soft,2026-07-01T00:00:05+00:00\n";

        $tmp = tempnam(sys_get_temp_dir(), 'test_dq_orphan_');
        file_put_contents($tmp, $csvContent);

        $dq = new DataQualityReport();
        $result = $this->loader->loadAttempts($tmp, $dq);
        unlink($tmp);

        assert($dq->orphanRetriesCount === 1, "Expected 1 orphan retry charge recorded");
        assert($result['charges']['ch_orphan'][0]->qualityStatus === 'orphan_retry', "Attempt must be marked orphan_retry");
        echo "  [PASS] Stage 1: Orphan retry (attempt 1 without attempt 0) marked correctly\n";
    }

    public function testValidEurMinorUnitConversion(): void
    {
        $loader = new DataLoader();
        $eur = $loader->convertToEur(1234, 'EUR', '2026-07-01');
        assert(abs($eur - 12.34) < 0.0001, "1234 minor units in EUR must equal €12.34");
        echo "  [PASS] Stage 1: Valid EUR minor units conversion (€12.34)\n";
    }
}
