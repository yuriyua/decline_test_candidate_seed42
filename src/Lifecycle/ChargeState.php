<?php
declare(strict_types=1);

namespace RetryEngine\Lifecycle;

final class ChargeState
{
    public const APPROVED_INITIALLY = 'approved_initially';
    public const RECOVERED_BY_CURRENT_RETRY = 'recovered_by_current_retry';
    public const LOST = 'lost';
    public const INCOMPLETE_OBSERVATION = 'incomplete_observation';
    public const INVALID_DATA = 'invalid_data';
}
