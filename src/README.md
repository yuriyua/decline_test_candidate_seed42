# Test task: "How much can we really recover?"

60 minutes. AI required (we work in Claude Code). PHP or Go.
No payments background needed: learning the domain fast with AI is part of the test.

## The request

From Sales, Monday 9:00:

> "Our new CRM partner says declines are killing their merchants' revenue. Here are 90 days of
> payment attempts from 3 pilot merchants. How much can we really recover, and can we have a
> retry engine by tonight? I'm pitching them on Thursday."

## Files

- `attempts.csv`: every payment attempt webhook event, July to September 2026, 3 subscription merchants.
- `fx_rates.csv`: daily rates to EUR.

Each attempt (original or retry) costs EUR 0.08 in processing fees. Card scheme rules apply.

## Send back within 60 minutes

1. The task you wrote with AI, as you would put it in ClickUp: the real problem, acceptance criteria, edge cases.
2. The numbers: real lost revenue in EUR for the 90 days, how much of it is recoverable, and the expected gain over the merchants' current retries.
3. A retry engine in PHP or Go: given a declined attempt and its history, return the next retry time, or "do not retry" with a reason. With tests.
4. A note to Sales, up to 10 lines: what we can promise on Thursday, and what we must not.
5. Your agent session log or a short screen recording.
6. Three lines on what you checked by hand, and why.

A partial answer with a clear plan beats a late perfect one.

## attempts.csv columns

| Column | Meaning |
| --- | --- |
| event_id | Webhook event ID |
| attempt_id | Payment attempt ID |
| charge_id | The subscription charge; an original attempt and its retries share it |
| merchant_id, customer_id | Merchant and customer |
| card_token, card_bin, card_scheme, card_type, card_country | Card details |
| amount_minor, currency | Amount in minor units of the currency |
| created_at | Attempt time, UTC |
| attempt_number | 0 = original attempt, 1+ = retry |
| status | approved / declined |
| response_code | Issuer response code |
| merchant_advice_code | Mastercard merchant advice code, when present |
| gateway_decline_category | Our gateway's soft / hard label |
| received_at | When the webhook reached us, UTC |
