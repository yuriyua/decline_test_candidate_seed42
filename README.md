# Payment Retry Engine

Policy-driven analyzer and dry-run retry engine for subscription-payment declines. The project turns payment-attempt events into auditable charge lifecycles, reports recoverable revenue in EUR, and decides whether a declined charge should be retried, reviewed, or stopped.

> **Status:** MVP / dry-run only. It never submits a payment to a gateway.

## Why it exists

A declined payment is not automatically lost revenue, and retrying every decline is neither profitable nor safe. Each retry costs **€0.08** and some issuer responses, merchant-advice codes, or card-scheme conditions must never be retried.

The implementation answers three questions:

1. What was actually lost, recovered by the current retry behaviour, or excluded due to poor data quality?
2. Which declines are eligible for a compliant and economically worthwhile retry?
3. When should the next retry occur, or why must it not occur?

## Scope

- Ingests `src/attempts.csv` and `src/fx_rates.csv`.
- Groups original attempts and retries by `charge_id`.
- Converts amounts to EUR using the FX rate for the UTC date of `created_at`.
- Produces baseline recovery and retry-cost metrics.
- Applies versioned retry policy, safety checks, scheme windows, kill switches, and an expected-net-value filter.
- Writes a masked audit trail to `src/audit_trail.csv`.

The input files are intentionally kept in `src/` for this exercise. Do not commit production payment data or unmasked card identifiers to a public repository.

## Requirements

- PHP 8.2+
- Composer (optional; the included test runner does not require installing vendor dependencies)
- Docker and Docker Compose only if using the supplied local web/database stack

## Quick start

Run the automated suite:

```bash
php tests/run.php
```

Run the end-to-end analysis and dry-run simulation:

```bash
php src/index.php
```

Equivalent Composer commands:

```bash
composer test
composer analyze
```

The analyzer prints Markdown-friendly financial tables and decision summaries to stdout, then creates `src/audit_trail.csv`. This file is generated output and can be recreated at any time.

## Input contract

### `attempts.csv`

| Field | Use |
| --- | --- |
| `event_id` | Idempotency/deduplication key for webhook events. |
| `attempt_id` | Identifier of a specific payment attempt. |
| `charge_id` | Subscription charge identifier; original and retries share it. |
| `merchant_id`, `customer_id` | Merchant/customer grouping. |
| `card_token`, `card_bin`, `card_scheme`, `card_type`, `card_country` | Card context for policy and analysis; sensitive values are masked in audit logs. |
| `amount_minor`, `currency` | Amount before FX conversion. |
| `created_at`, `received_at` | UTC event time and webhook-delivery time. |
| `attempt_number` | `0` is the original attempt; `1+` are retries. |
| `status` | `approved` or `declined`. |
| `response_code`, `merchant_advice_code`, `gateway_decline_category` | Inputs to decline classification. |

### `fx_rates.csv`

| Field | Use |
| --- | --- |
| `date` | UTC calendar date used to select the rate. |
| `currency` | ISO currency code. |
| `rate_to_eur` | Multiplier after converting minor units to major units. |

Conversion is:

```text
EUR = amount_minor / currency_exponent(currency) × rate_to_eur(created_at UTC date)
```

An invalid amount, unsupported currency, malformed timestamp, or missing FX rate is surfaced in the data-quality report and excluded from EUR totals rather than silently estimated.

## Processing flow

```text
CSV input
  → validation, deduplication and FX conversion
  → charge lifecycle reconstruction
  → baseline recovery report
  → policy classification
  → scheme/window/kill-switch checks
  → recovery-probability and expected-net-value filter
  → retry | do_not_retry | manual_review
  → masked audit trail
```

### 1. Data quality

The loader detects duplicate events, conflicting duplicate payloads, invalid values, late delivery, orphan retries, and charges with more than one original attempt. Conflicting duplicates are quarantined; quality exclusions are reported in EUR where possible.

### 2. Charge lifecycle

Each `charge_id` is classified as one of:

- `approved_initially`
- `recovered_by_current_retry`
- `lost`
- `incomplete_observation`
- `invalid_data`

Metrics distinguish gross value at risk, historical recovered revenue, retry fees, and net recovery. The last seven days of the reporting range are treated as incomplete observation to avoid falsely labelling charges as permanently lost before their retry window is observable.

### 3. Policy classification

`src/Policy/Policy.php` is the central, versioned configuration. It holds retry fees, response-code timing, max attempts, retry windows, hard-stop response/advice codes, economic thresholds, and global or merchant-level kill switches.

`DeclineClassifier` gives merchant advice priority where relevant and classifies a decline as:

- hard stop (`do_not_retry`)
- eligible soft decline (`retry`)
- exhausted (`do_not_retry`)
- ambiguous/unknown (`manual_review`)

The provided defaults are exercise policy values. They must be reviewed and replaced with the acquirer-approved, scheme-specific policy before any production execution.

### 4. Economic filter

`RecoveryModel` learns empirical next-attempt success rates by:

```text
response_code × next_attempt_number × card_scheme
```

When the exact segment has too few observations, it falls back to a response-code segment; if that is also insufficient, the decision is routed to manual review. A retry is viable only when:

```text
expected_net_eur = amount_eur × p_success − 0.08 > 0
```

The holdout evaluation trains through 15 August 2026 and evaluates the 16 August–23 September cohort, leaving the final seven days out of the evaluation.

### 5. Decision engine

The core interface is:

```php
RetryEngine::decideNextRetry(
    Attempt $declinedAttempt,
    array $chargeHistory,
    Policy $policy,
    DateTimeImmutable $now
): Decision
```

`Decision` contains:

- `action`: `retry`, `do_not_retry`, or `manual_review`
- `retry_at`: UTC timestamp or `null`
- `reason_code` and human-readable `explanation`
- `policy_version`
- optional expected net value and success probability
- `charge_id` and the scheduled next attempt number

Before scheduling, the engine validates the amount, deduplicates history by `event_id`, stops an already approved charge, honours kill switches, classifies the decline, validates max attempts and retry windows, and applies the economic filter. Every outcome is logged through `AuditLogger` with masked card data.

## Repository layout

```text
src/
├── Contract/Attempt.php              # Typed payment-attempt contract
├── DataLoader.php                    # CSV ingestion, validation, FX conversion
├── Quality/DataQualityReport.php     # Quality counters and Markdown report
├── Lifecycle/                        # Charge-state reconstruction
├── Policy/                           # Versioned policy and decline classifier
├── Model/RecoveryModel.php           # Empirical probability and value filter
├── Engine/                           # Decision, retry engine, audit logging
├── Report/AnalyticsReport.php        # Baseline and holdout metrics
├── index.php                         # CLI analysis + dry-run entry point
├── attempts.csv
└── fx_rates.csv
tests/Unit/                           # Stage 1–5 executable tests
docker/                               # PHP-FPM and Nginx configuration
```

## Test coverage

The custom test runner covers the delivery stages below.

| Stage | Coverage |
| --- | --- |
| 1 — Data quality | Duplicates, conflicting payloads, invalid amounts/statuses, absent FX, late events, orphan retries. |
| 2 — Lifecycle | Initial approvals, retry recoveries, losses, incomplete observation, fee accounting. |
| 3 — Policy | Hard vs soft declines, merchant advice, timing, scheme limits and unknown codes. |
| 4 — Recovery model | Segment probability, fallback behaviour, insufficient samples and negative expected value. |
| 5 — Engine | Approved charges, idempotent history, kill switches, scheduling windows, deterministic decisions and audit masking. |

Run all tests before merging:

```bash
php tests/run.php
```

## QA checklist

1. Seed every edge case from the test matrix: duplicate/conflicting events, hard and soft declines, merchant advice, missing FX, exhausted retries, late webhooks, and already-approved charges.
2. Manually recompute at least ten charge lifecycles: minor-unit conversion, FX date, retry fees, lifecycle state, and final EUR total.
3. Confirm that an approved charge, hard decline, expired retry window, and active kill switch can never result in `retry`.
4. Invoke the engine twice with identical input and confirm an identical decision and no duplicate schedule.
5. Confirm every decision has a policy version and reason code, all timestamps are UTC, and generated audit output contains no unmasked card token or PAN.
6. Run `php src/index.php` and confirm dry-run output never calls a payment gateway.

## Production-readiness notes

This repository demonstrates the decisioning path, not a production payment executor. Before enabling real retry submission, add:

- Acquirer/compliance approval for all live scheme and merchant-advice rules.
- Durable idempotency storage and an atomic scheduler/queue.
- Authenticated API boundary, secret management, observability, alerting, and incident runbook.
- Merchant consent, customer-notification and authentication/3DS flows where required.
- PCI-DSS review of token handling, logging, retention, and data access.
- Controlled merchant-by-merchant rollout with measurable success, cost, and policy-violation alerts.

## Safety

Do not present `eligible opportunity` as guaranteed recovery. Recovery estimates are empirical, cohort-dependent expectations. The global and merchant-level kill switches in `Policy` are the immediate safeguard for disabling retry decisions.
