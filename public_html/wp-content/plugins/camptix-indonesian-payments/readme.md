# CampTix Indonesian Payments

Accept IDR ticket payments through Mayar's hosted checkout. Attendees choose from
the channels enabled in the merchant's Mayar dashboard. CampTix remains the source
of ticket prices, coupons, reservations, attendee records, and confirmation emails.

Author: [Nazmul Hosen](https://profiles.wordpress.org/nazmul111/).
License: GPL-2.0-or-later. Requires PHP 8.3+ and CampTix.

## Setup

1. Install/activate CampTix, then activate **CampTix Indonesian Payments** on the
   event site. On WordCamp.org this is an optional, per-site plugin.
2. Set the ticket currency to **IDR** in **Tickets → Setup**.
3. Under **Tickets → Setup → Payment**, enter the sandbox Read & Write API key and
   merchant UUID, keep sandbox mode enabled, and enable **Mayar**.
4. Copy the sandbox webhook URL from the settings screen into **Mayar → Integration
   → Webhook**, save, and test it. The URL includes a private, generated secret.
   The event's WordPress REST API must be publicly reachable over HTTPS. Local
   `.test` hostnames need a correctly configured public HTTPS development tunnel.
5. Complete the sandbox checklist below before configuring production credentials.
   Confirm the merchant account settles in IDR and that its enabled payment
   channels meet the event's needs. Enter the production merchant UUID and API key,
   register the production webhook URL, then disable sandbox mode.

The merchant UUID must match both the authenticated transaction's `user.id` and
the payment request's `userId`. Obtain it from the Mayar account/API details; it
is not the payment UUID or transaction UUID. Credentials are stored server-side
in CampTix's options, not sent to the browser. As with other WordPress settings,
database access permits reading those options; they are not encrypted at rest by
this plugin. Blank API-key inputs preserve the saved key. Do not rotate or remove
credentials while payments using them are outstanding without reconciling first.

Mayar documents one webhook URL per merchant account. This release requires a
dedicated merchant webhook configuration for this site. It does not automatically
register/overwrite a merchant's webhook and does not implement a shared-account
network dispatcher. Do not reuse one account's webhook across concurrent sites.

## Checkout and payment verification

- The total must be a positive integer number of rupiah after CampTix coupons.
  Fractional totals are rejected rather than rounded. Free tickets use CampTix's
  free checkout. Mayar requests send rupiah directly, without multiplying by 100.
- The default payment lifetime is 30 minutes, configurable from 5 to 1440 minutes.
  The provider expiry is recorded on every attendee. CampTix's normal expiry grace
  applies to held seats.
- One durable attempt is created per CampTix payment token. Refreshing checkout
  reuses an open attempt. A timeout or malformed provider response never causes
  an automatic second create request for that order.
- The default flow relies on webhook/cron confirmation and ticket email. **Send a
  return URL** is off by default because Mayar can reject `redirectUrl` on payment
  requests. Enable it only after testing that feature with the merchant account.
  A return page verifies the API and reports status; visiting it is not payment proof.
- Only HTTPS checkout links on `myr.id`, its subdomains, `mayar.id`, or `mayar.io`
  are accepted. Custom checkout domains are not supported in this release.
- Webhooks are filtered with a per-environment secret and then checked through
  authenticated API lookups. The current public webhook docs do not define a
  signature contract; this plugin does not claim to verify provider signatures.
  Returned amount, ownership, provider associations, and any returned currency
  must match the saved attempt. IDR is enforced locally; the account must be
  confirmed as an IDR merchant where Mayar omits an explicit currency field.
- Database locks serialize initiation and settlement, including duplicate webhooks,
  browser returns, and cron jobs. Only CampTix performs ticket confirmation and email.
- When a verified payment arrives after its seats were released, the plugin flags
  it for organizer review rather than restoring tickets and risking overselling.

Mayar's API v2 uses `https://api.mayar.io/hl/v2` for sandbox and
`https://api.mayar.id/hl/v2` for production. Outstanding attempts keep their original
environment even after the settings switch modes. An account-wide request budget
and `Retry-After` handling avoid uncontrolled API retries. Other applications using
the same key also consume Mayar's quota.

## Reconciliation and organizer review

WordPress cron checks unsettled payments with bounded backoff. Configure a reliable
server-side WordPress cron runner for events where traffic is insufficient to run
WP-Cron. The timeout sweep also rechecks once per order. Unknown outcomes do not
extend reservations indefinitely.

An admin warning links to an attendee needing review. Open that attendee's **Mayar
payment** panel to see provider IDs, a review reason, and **Recheck payment**. This
action requires CampTix attendee-management permissions and a nonce. For a create
request whose response was lost, an organizer can supply the transaction UUID from
Mayar; recovery additionally requires the API's `extraData.camptix_reference` to
match the local attempt. A webhook can also recover that attempt if it includes
the reference and transaction UUID. No arbitrary webhook blog/merchant/amount
assertion can confirm a ticket.

If the attempt is `review` because a payment arrived after release, rechecking does
not issue tickets automatically. Compare the provider transaction, confirm capacity,
and resolve through the existing attendee administration or arrange a manual refund.
For `creation_uncertain`, inspect Mayar before asking the buyer to pay again.
Unresolved attempts remain available for manual rechecks after automatic retries
stop. Provider responses, credentials, webhook secrets, customer details, and
CampTix access tokens are not included in the plugin's transaction summaries.

Automatic refunds are not advertised or implemented. Perform refunds through the
provider's supported process, then reconcile the attendee's status in CampTix.
Mayar payment requests do not apply the usual customer-paid admin/channel fee
settings; settlement fees must not be mistaken for a short ticket payment.

## Sandbox acceptance checklist

The automated fixtures follow Mayar's public v2 docs. They do not replace a real
merchant sandbox test. Before production use, check:

- Single/multiple tickets and coupons; integer totals and fractional rejection.
- QRIS and the account's enabled channels; expiry and an abandoned checkout.
- The actual webhook payload, amount units, merchant UUID, and correlation metadata.
- One ticket confirmation/email when notifications repeat or race a return.
- Lost webhooks, API downtime, rate limits, and cron/manual recovery.
- Late payments after seats release, manual refunds, and environment changes.
- Whether `redirectUrl` is supported and which checkout hostname is returned.
- Mobile and desktop checkout plus keyboard access to the payment selection.

For automated tests, use the repository's `composer install`, WordPress test database
setup, and `phpunit.xml.dist` suite **CampTix Indonesian Payments**. All provider HTTP
calls in that suite are intercepted; tests cannot create real charges.

## Disabling and data retention

Disable Mayar in CampTix settings to stop new checkouts while leaving webhook/cron
settlement active. Reconcile outstanding attempts before deactivating the plugin.
Deactivation stops its scheduled jobs without deleting financial records. Reactivation
does not recreate cleared jobs; recheck outstanding orders manually. Attempt records
and indexes use non-autoloaded, site-scoped options and remain for reconciliation.
Webhook URL secrets remain stable across settings changes.

## References

- [API v2 and environments](https://docs.mayar.id/api-reference-v2/introduction)
- [Payment request creation](https://docs.mayar.id/api-reference-v2/reqpayment/create)
- [Transaction verification](https://docs.mayar.id/api-reference-v2/transaction/detail)
- [Webhook configuration/events](https://docs.mayar.id/integration/webhook)
- [WordCamp issue #2281](https://github.com/WordPress/wordcamp.org/issues/2281)
