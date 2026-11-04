# CampTix payment gateways

What a payment gateway needs before WordCamp sites can use it. Decided in
[#1550](https://github.com/WordPress/wordcamp.org/issues/1550).

## Requirements

A gateway extends `CampTix_Payment_Method` and must:

1. **Report results as structured data.** Call `payment_result( $token, $status, $data )`
   with `$data` an array: `transaction_id`, `transaction_details`, and for refunds
   `refund_transaction_id` and `refund_transaction_details`. A bare transaction id still
   works, but raises `_doing_it_wrong()`.
2. **Support refunds.** Set the `refund-single` and `refund-all` features, and implement
   `payment_refund()` and `send_refund_request()`. Partial refunds (`refund-partial`) are
   optional.
3. **Support payment lookup.** Set the `payment-lookup` feature and implement
   `reconcile_payment( $token )`. Before a draft order times out, the sweep asks the
   gateway what became of its payment. Record the gateway's session or order id at
   checkout with `CampTix_Plugin::update_payment_token_meta()`, so it is on every attendee
   of the order. If the gateway session has a known lifetime, record it with
   `set_order_session_expiry()`; otherwise drafts hold their seats for 24 hours. See the
   docblock on `reconcile_payment()` for the result statuses.
4. **Have a sandbox mode**, and a test site that its maintainers can use. A gateway
   without a sandbox needs a maintainer who can test it with small live payments.

A gateway missing refunds or payment lookup can't be newly enabled on a site. One
already enabled stays enabled, with a warning, until it's turned off. The list of
required features is filterable with `camptix_payment_method_required_features`.

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
| Instamojo | Yes | Yes | |
| Razorpay | Yes | Yes | |
| SSLCommerz | Yes | Yes | |
| shurjoPay | No | Yes | shurjoPay's API has no refund call. |
| MercadoPago, PagSeguro, PayFast, TrustCard, TrustPay | Unknown | No | Outside this repository. |
