# CampTix Indonesian Payments: Mayar implementation plan

Prepared: 2 November 2026.

Related issue: https://github.com/WordPress/wordcamp.org/issues/2281

Branch: `feat/camptix-indonesian-payments`.

Production baseline: `b24ef750c8e41e2d2540b05638d4c0518c9d4bde`, fetched from `WordPress/wordcamp.org` and confirmed against its remote `production` ref when preparing this plan.

The initial plugin implementation now follows this plan in `public_html/wp-content/plugins/camptix-indonesian-payments/`. Automated tests use documented API fixtures with intercepted HTTP. Real merchant sandbox verification in milestone 3 remains outstanding because no Mayar credentials were provided. Shared-account network dispatch and automated refunds remain outside this release.

## 1. Naming and scope

- Plugin name: **CampTix Indonesian Payments**.
- Plugin header `Author`: **Nazmul Hosen**.
- Plugin header `Author URI`: https://profiles.wordpress.org/nazmul111/
- Directory/text domain: `camptix-indonesian-payments`.
- First gateway ID/display name: `mayar` / **Mayar**.
- Currency: IDR only.
- Checkout: Mayar-hosted payment page, with channel selection managed in the merchant's Mayar dashboard.
- Runtime: PHP 8.3+ and WordPress HTTP/REST/settings/cron APIs; no new payment SDK or frontend framework needed.

Implement a separate optional CampTix add-on. Use the regional gateways as references for CampTix hooks, but keep the new gateway independent of the Bangladesh plugin. Leave provider-specific API behavior in a Mayar client, so another Indonesian gateway could be added later without designing a generic gateway framework now.

Automatic refunds are outside the initial release unless a supported Mayar refund API and its semantics are verified. Keep CampTix's `refund-single`, `refund-all`, and `refund-partial` capabilities false initially and document manual reconciliation.

## 2. Findings that affect implementation

### Existing CampTix behavior

- Register an extension through `camptix_load_addons` / `camptix_register_addon()` and extend `CampTix_Payment_Method`.
- Use `get_order()` and the stored `tix_order` snapshot for pricing, rather than rebuilding the total from current ticket prices or browser input.
- IDR already exists in `class-camptix-currencies.php`, with two displayed decimals. Mayar's payment request API expects an integer amount. Validate whole-rupiah totals, including discounted totals, without silently rounding or multiplying by 100.
- Make IDR selectable while the add-on is active, before Mayar is enabled. The Bangladesh plugin addresses the equivalent initial-currency setup problem.
- CampTix stores session expiry in `tix_session_expires_at` and adds `DRAFT_LIFETIME_GRACE` before releasing draft seats. Integrate with `camptix_pre_attendee_timeout`.
- `payment_result()` can recover timed-out orders and does not prevent every failed/pending result from downgrading a paid order. The new integration must guard transitions and late payments before calling it.
- Production controls optional plugin activation in `wcorg-misc.php` and expected network activation in `wcorg-network-plugin-control.php`. Include the new plugin in those lists as optional and normally not network activated.

### Current Mayar documentation

Use Headless API v2 with Bearer authentication:

| Purpose | API |
| --- | --- |
| Production base | `https://api.mayar.id/hl/v2` |
| Sandbox base | `https://api.mayar.io/hl/v2` |
| Create payment request | `POST /payments/create` |
| Read payment request | `GET /payments/{id}` |
| Read transaction | `GET /transactions/{id}` |
| Close payment request | `POST /payments/{id}/close` |

Creating a payment request returns a payment ID, transaction ID, and checkout link. Keep the payment ID and transaction ID separate. Send an explicit `expiredAt`, the server-calculated amount, and opaque order correlation in `extraData`.

The docs say `redirectUrl` may be rejected on payment requests when it cannot be persisted. They also document one merchant webhook URL and `payment.received`, but do not establish a request-signature verification contract. API transaction examples do not include an explicit currency field. Treat these as contract questions to resolve in sandbox, not assumptions to encode as facts.

The documented quota is 50 requests/minute per API key; verification and reconciliation must share that budget. Payment requests do not apply the usual settings for passing admin/channel fees to customers, so reconcile the original order amount separately from merchant settlement fees.

## 3. First milestone: verify the provider contract

Using a sandbox merchant and a publicly reachable HTTPS test endpoint:

1. Create and pay one single payment request; capture redacted create, detail, transaction, and webhook fixtures.
2. Confirm the request-to-transaction relationship, merchant identity, `extraData` round trip, amount units, paid/expired statuses, and expiry enforcement.
3. Confirm the exact `payment.received` payload and any supported signature or shared-secret mechanism. The documentation's event-field naming and status types are not sufficiently precise to implement blindly.
4. Check whether a requested return URL is persisted. If unsupported, use hosted checkout plus asynchronous confirmation and document the return experience. Do not repeatedly recreate payments to work around the error. Consider the invoice API only if sandbox evidence shows it is necessary; it has additional required customer fields.
5. Confirm how IDR is established when API responses omit currency. Enforce IDR locally; validate any returned currency, and require a verified IDR merchant/account contract rather than inventing a response field.
6. Confirm allowed checkout domains, duplicate-create behavior, lookup/recovery after a create timeout, closing unpaid links, and available refund behavior.

This milestone needs sandbox credentials during implementation. The plan and mocked tests do not need live credentials.

## 4. Proposed files and repository integration

All new plugin paths below are under `public_html/wp-content/plugins/camptix-indonesian-payments/`:

| File | Responsibility |
| --- | --- |
| `camptix-indonesian-payments.php` | Plugin header, dependency checks, add-on and IDR registration |
| `includes/class-payment-method-mayar.php` | CampTix settings, checkout, provider status mapping, timeout hooks |
| `includes/class-mayar-client.php` | Fixed API hosts, Bearer requests, response validation, normalized errors |
| `includes/class-payment-store.php` | Durable attempt snapshots, provider lookup, concurrency guards |
| `includes/class-webhook-controller.php` | Scoped REST callback, request validation, verification dispatch |
| `includes/class-reconciliation.php` | Bounded background verification, expiry handling and manual rechecks |
| `readme.md` | Installation, settings, webhook setup, reconciliation, refund and rollback instructions |
| `tests/bootstrap.php` and `tests/test-*.php` | WordPress integration tests with mocked provider responses |

Add a bootstrap entry in `phpunit-bootstrap.php` before CampTix's final bootstrap and a **CampTix Indonesian Payments** suite in `phpunit.xml.dist`. The existing CI `other` shard discovers new suites automatically. Update the optional/network plugin lists. Add JavaScript only if the actual checkout UX requires it; a hosted checkout integration should initially be mostly PHP.

## 5. Settings and checkout

Expose **Mayar** in **Tickets → Setup → Payment** with an enable switch, sandbox mode, separate environment credentials, webhook URL/setup guidance, and payment expiry. Default to sandbox and disabled.

Use existing CampTix settings permissions and nonce checks. Keep credentials server-side, render secret inputs without returning saved secrets, preserve saved values on blank submissions, and redact them from logs. Disabling new checkouts must still allow settlement of existing attempts while the plugin remains active.

For checkout:

1. Validate gateway availability, IDR, credentials, the stored CampTix order, and a positive whole-rupiah amount. Keep free orders in CampTix's existing free-ticket flow. Collect only customer fields required by the verified Mayar contract.
2. Acquire an atomic per-order initiation guard and reuse a valid existing payment attempt for repeated submissions.
3. Persist a local attempt before contacting Mayar: opaque order reference, attendee IDs, expected integer amount/IDR, environment, merchant/configuration reference, creation time, expiry, and state. Use non-autoloaded site options for indexed attempt records and store references on attendees; no separate custom table is initially required.
4. Create the payment with a minimal description and opaque `extraData`; avoid exposing CampTix access/payment tokens in provider metadata.
5. Save the payment ID, transaction ID, validated checkout URL, and expiry, then set `tix_session_expires_at` on the related attendees.
6. Redirect only to HTTPS checkout hosts confirmed in the contract test. Reject malformed or unexpected URLs.

For transport timeouts after creation, keep an unresolved attempt and reconcile before creating another charge. Do not assume HTTP retries are idempotent: Mayar's documented duplicate-request response is not a documented idempotency-key guarantee. Apply bounded backoff for 429/5xx responses and honor `Retry-After`.

## 6. Verification, state transitions, and recovery

Register a scoped REST webhook endpoint that works without a WordPress login and validates method, body size, JSON shape, and the configured webhook secret/signature where supported. If sandbox confirms no signatures, use a high-entropy callback secret as an additional filter, compare it in constant time, and always verify payment using the authenticated API. Never use a WordPress user nonce as webhook authentication.

Webhook data is a notification, not proof. Resolve only known local payment/transaction references, fetch the stored transaction and payment request using the recorded environment, and compare ownership, association, amount, and order metadata. Do not accept a different order of equal price or a transaction from another merchant/site/environment. Treat any IDs in metadata as hints until they match stored records.

Browser return, webhook, cron, and an authorized administrator's recheck must call the same verifier. Serialize finalization per order and deduplicate transaction/event processing so simultaneous requests do not issue duplicate confirmations or overwrite settled records. Store only a minimal redacted transaction summary.

Use the following behavior:

| Verified situation | CampTix behavior |
| --- | --- |
| Paid, valid order, seat still held | `PAYMENT_STATUS_COMPLETED` via `payment_result()`; use noninteractive mode for webhooks/cron |
| Unpaid, still within checkout lifetime | Keep draft reservation until expiry; use pending only if verified provider semantics justify it |
| Expired and authoritatively unpaid | `PAYMENT_STATUS_TIMEOUT` for an unsettled order |
| Provider unavailable or unknown status | Retry/reconcile; do not report a declined charge or overwrite a paid attendee |
| Repeated success, stale failure/cancel, or refunded attendee | Preserve settled state and transaction references |
| Paid after seats were released | Record a paid-but-needs-review attempt and notify organizers; do not blindly republish timed-out attendees and oversell |

The late-payment rule is deliberately enforced in the new gateway because current core can republish a timed-out order. Recovery after release needs an organizer to confirm capacity or arrange a refund.

Before timeout, recheck outstanding payments once per order, not once per attendee. Add bounded scheduled reconciliation for missed webhooks, cancellation/expiry races, and temporary API failures. Preserve uncertainty for follow-up, but do not indefinitely extend reservations just because the provider is unavailable. Close unpaid links when appropriate, and retain attempt records needed to reconcile later notifications. Unschedule unnecessary jobs after settlement and clean up safely on deactivation without deleting financial records.

## 7. Multisite webhook routing

The documented merchant webhook URL is account-wide. Saving a separate URL for every WordCamp using the same account would overwrite the previous receiver.

For the first release, support a site-scoped receiver with a dedicated merchant webhook configuration and require manual URL registration; the plugin must not silently overwrite a merchant's webhook on activation or settings save. Document the requirement and confirm the intended account arrangement before rollout.

If shared-account use is required, implement and test a central network receiver before enabling that use case. It must maintain a server-side transaction-to-site mapping, select credentials from trusted records, validate the merchant and active site, and always restore blog context. Do not route or choose credentials solely from untrusted webhook `blog_id` metadata. This extension changes activation/routing scope and should be a separate implementation milestone rather than an undocumented assumption.

## 8. Verification and delivery sequence

Implementation commits:

1. Plugin bootstrap, IDR/settings support, optional activation lists, and test registration.
2. Mayar API client, attempt store, initiation guards, checkout, and redacted fixtures.
3. Webhook/return verifier, finalization guards, reconciliation, timeout handling, and organizer recheck.
4. End-to-end sandbox verification, documentation, and refund/late-payment procedures.

Tests must cover multi-ticket and coupon totals; fractional/zero amounts; initial IDR setup; missing credentials; environment isolation; malformed API responses; wrong amount, merchant, order, transaction, or site; webhook secret/signature errors; unknown/replayed notifications; concurrent return/webhook processing; duplicate create and uncertain create outcomes; 429/backoff; pending/expiry/timeout races; paid-after-release; refunds preserved on later notifications; and secret/PII redaction. Verify existing Stripe, PayPal, Bangladesh, and Indian flows still pass their relevant suites.

Start with PHP syntax checks, the new suite and affected CampTix suites, and changed-file PHPCS. Then mirror CI on PHP 8.3, 8.4, and 8.5:

```bash
# Setup: install Subversion, Composer dependencies, and the CI MariaDB service first.
composer install
bash .docker/bin/install-wp-tests.sh wcorg_test root root "127.0.0.1:${DB_PORT}" latest true

# Install GatherPress 0.36.1 as currently pinned in .github/workflows/unit-tests.yml.
npm ci
npm run build --workspace=public_html/wp-content/mu-plugins/wporg-groups-frontend

./public_html/wp-content/mu-plugins/vendor/bin/phpunit -c phpunit.xml.dist --testsuite 'CampTix Indonesian Payments,CampTix,CampTix BD Payments,CampTix Indian Payments'
BASE_REF=production php .github/bin/phpcs-branch.php

# Final repository checks.
composer run lint
./public_html/wp-content/mu-plugins/vendor/bin/phpunit -c phpunit.xml.dist
npm run build --workspaces --if-present
npm run lint:js --workspaces --if-present
npm run lint:css --workspaces --if-present
npm run test --workspace=wordcamp-blocks
npm run test --workspace=wporg-groups-frontend
```

The changed-lines script resolves `BASE_REF` under `origin`; use the canonical production ref (or a temporary local tracking alias to the fetched upstream baseline when working from a fork). Also mirror the workflow's explicit stylelint step for non-workspace CSS when doing full CI parity. Recheck the pinned GatherPress version if production moves. Use the separate Docker PHPUnit environment only with its documented Docker credentials, not the CI credentials above.

Manual sandbox checks: choose Mayar, complete payment, abandon checkout, return before/after the webhook, retry notifications, simulate an unreachable callback, verify one set of ticket emails and correct ticket counts, and test on mobile and desktop. Use a public HTTPS test URL for callbacks; a local `.test` hostname alone is unreachable from Mayar.

Production readiness requires the contract fixtures/tests to pass, the chosen webhook/account arrangement to work, and payment operations to confirm merchant ownership, settlement, and manual refund handling. Roll out to one event with credentials configured securely. Stop new checkouts first when rolling back, continue verifying existing attempts, then deactivate after reconciliation.

## Implementation verification

- Mayar suite: 25 tests / 112 assertions pass on PHP 8.3.30, 8.4.18, and 8.5.2.
- CampTix plus Indonesian, Bangladesh, and Indian payment suites: 347 tests / 894 assertions pass on PHP 8.4.
- PHP syntax, plugin PHPCS, changed-lines PHPCS against the fetched production baseline, and whitespace checks pass.
- The Groups frontend build required by PHPUnit passes. WordPress 7.1.4, GatherPress 0.36.1, and an isolated MariaDB test database were used. Some existing development dependencies were reused from the original checkout after Composer's third-party downloads timed out.
- The full repository run exercised 3,063 tests and exposed a REST test-fixture registration notice (corrected and verified by rerunning the Mayar suite on all three PHP versions) and a Groups language-sort assertion. The language-sort assertion also fails with the untouched production bootstrap and Mayar not loaded; it is outside this plugin's scope. Full repository validation is therefore not green.
- The installable ZIP excludes tests and build output and passes ZIP integrity checks.
- Real Mayar sandbox transactions remain unverified; no API keys or live payments were used. Follow the plugin readme's sandbox checklist before production activation.

## Sources

- [Mayar API v2 authentication and environments](https://docs.mayar.id/api-reference-v2/introduction.md)
- [Create payment request](https://docs.mayar.id/api-reference-v2/reqpayment/create.md)
- [Payment request details](https://docs.mayar.id/api-reference-v2/reqpayment/detail.md)
- [Transaction details](https://docs.mayar.id/api-reference-v2/transaction/detail.md)
- [Close payment request](https://docs.mayar.id/api-reference-v2/reqpayment/changestatus.md)
- [Webhook events](https://docs.mayar.id/integration/webhook.md)
- [Register merchant webhook URL](https://docs.mayar.id/api-reference-v2/webhook/registerurlhook.md)
- [API rate limits](https://docs.mayar.id/api-reference-v2/rate-limit.md)
- [Invoice API alternative](https://docs.mayar.id/api-reference-v2/invoice/create.md)

Provider behavior above was checked against official documentation; no sandbox API calls or real payment transactions were made while preparing this plan.
