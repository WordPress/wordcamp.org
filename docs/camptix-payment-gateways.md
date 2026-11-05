# CampTix payment gateways

What a payment gateway is expected to support on WordCamp sites. Decided in
[#1550](https://github.com/WordPress/wordcamp.org/issues/1550).

## Requirements

A gateway extends `CampTix_Payment_Method` and should:

1. **Report results as structured data.** Call `payment_result( $token, $status, $data )`
   with `$data` an array: `transaction_id`, `transaction_details`, and for refunds
   `refund_transaction_id` and `refund_transaction_details`. A bare transaction id still
   works, but raises `_doing_it_wrong()`.
2. **Support refunds.** Set the `refund-single` feature and implement `payment_refund()`.
   Set `refund-all` and implement `send_refund_request()` too, or the Refund All tool skips
   the gateway's orders. Partial refunds (`refund-partial`) are optional. Refund the
   payment the gateway has on record for the order, never an id that came back through the
   buyer's browser, and encode anything the buyer typed before it goes into a request.
3. **Support payment lookup.** Set the `payment-lookup` feature and implement
   `reconcile_payment( $token )`. Before a draft order times out, the sweep asks the
   gateway what became of its payment. Record the gateway's session or order id at
   checkout with `CampTix_Plugin::update_payment_token_meta()`, so it is on every attendee
   of the order. If the gateway session has a known lifetime, record it with
   `set_order_session_expiry()`, early enough that the gateway still answers for the session
   when the sweep asks (PayPal records its expiry 20 minutes before the token's). Otherwise
   drafts hold their seats for 24 hours, and a lookup that can't answer then is retried for
   six more. See the docblock on `reconcile_payment()` for the result statuses.
4. **Have a sandbox mode**, and a test site that its maintainers can use. A gateway
   without a sandbox needs a maintainer who can test it with small live payments.

A gateway missing refunds (`refund-single`) or payment lookup (`payment-lookup`) gets a
warning on Tickets > Setup > Payment, beside its switch and, while it's enabled, at the top
of the screen. It is not blocked. Camps rely on gateways that don't declare these yet (see
Status), and every event year is a new site, so a block would cut them off from the
gateway they used the year before. Their maintainers are asked to add both instead. The
list of features is filterable with `camptix_payment_method_required_features`.

The `camptix_pre_attendee_timeout` action still fires before each draft times out, for
gateways that haven't moved to `reconcile_payment()` yet.

## Where gateways live

- **In this repository** by default, under `public_html/wp-content/plugins/`, with
  tests that run in CI. Changes go through pull requests here. That includes
  `campt-indian-payment-gateway` and `camptix-bd-payments`; this repository is their
  source of truth.
- **A country-specific gateway** can live in its own repository under the WordPress
  GitHub organization, named `WordCamp-Camptix-Payment-Gateway-<Name>`, with its own
  translation project. That keeps strings that only one locale needs out of the
  `wordcamporg` text domain.
- Not on plugins.svn. CampTix isn't supported as a standalone plugin any more.

## Status

| Gateway | Refunds | Payment lookup | Notes |
| --- | --- | --- | --- |
| Stripe | Yes | Yes | |
| PayPal | Yes | Yes | |
| Instamojo | Yes | Yes | Instamojo's sandbox host no longer resolves, so refunds are covered by unit tests only. |
| Razorpay | Yes | Yes | Not offered: unregistered in `campt-indian-payment-gateway` since May 2026 as known broken. |
| SSLCommerz | Yes | Yes | Purchase and refund tried against the sandbox. |
| shurjoPay | No | Yes | shurjoPay's API has no refund call. |
| Knit Pay | Yes | No | Outside this repository; site admins may activate it. Reports payment status to CampTix itself. In use by camps in India and the Philippines. |
| Khalti | Yes | Through `camptix_pre_attendee_timeout` | Outside this repository ([nepali-payments-for-camptix](https://github.com/openarun/nepali-payments-for-camptix)). In use by camps in Nepal. |
| Fonepay QR | No | Through `camptix_pre_attendee_timeout` | Same repository as Khalti. In use by camps in Nepal. |
| MercadoPago, PagSeguro, PayFast, PayNow, Paystack, TrustCard, TrustPay | No | No | Outside this repository. Last used between 2013 and 2019. |

Every gateway with anything but Yes in both columns gets the warning.
