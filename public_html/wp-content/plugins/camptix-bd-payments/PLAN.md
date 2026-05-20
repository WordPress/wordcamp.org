# CampTix BD Payments - Implementation Plan

> **Reference:** See `CAMPTIX_BD_PAYMENT_REPORT.md` at the project root for the full analysis of the current ticketing/inventory model and the oversell risk.

---

## Phase 1: SSLCommerz Improvements

### Current API Details (v4)

| | Sandbox | Production |
|---|---------|------------|
| **Base URL** | `https://sandbox.sslcommerz.com` | `https://securepay.sslcommerz.com` |
| **Session Init** | `POST /gwprocess/v3/api.php` | `POST /gwprocess/v3/api.php` |
| **Order Validation** | `GET /validator/api/validationserverAPI.php` | `GET /validator/api/validationserverAPI.php` |
| **Transaction Check** | `GET /validator/api/merchantTransIDvalidationAPI.php` | `GET /validator/api/merchantTransIDvalidationAPI.php` |

**Required TLS:** 1.2+

### Session Init Parameters (POST to `/gwprocess/v3/api.php`)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `store_id` | string(50) | Yes | Merchant store ID |
| `store_passwd` | string(50) | Yes | Merchant store password |
| `total_amount` | decimal | Yes | Total order amount |
| `currency` | string(3) | Yes | `BDT` |
| `tran_id` | string(255) | Yes | Unique transaction ID |
| `success_url` | string(255) | Yes | Success redirect URL |
| `fail_url` | string(255) | Yes | Failure redirect URL |
| `cancel_url` | string(255) | Yes | Cancel redirect URL |
| `ipn_url` | string(255) | No | IPN notification URL |
| `cus_name` | string(50) | Yes | Customer name |
| `cus_email` | string(50) | Yes | Customer email |
| `cus_add1` | string(50) | No | Customer address |
| `cus_city` | string(50) | No | Customer city |
| `cus_country` | string(50) | No | Customer country |
| `cus_phone` | string(15) | Yes | Customer phone |
| `product_category` | string(50) | Yes | Product category |
| `shipping_method` | string(50) | No | `YES` or `NO` |
| `num_of_item` | integer | No | Number of items |
| `product_name` | string(255) | No | Product names |
| `product_profile` | string(255) | No | Product profile type |

### Response Fields

| Field | Description |
|-------|-------------|
| `status` | `SUCCESS` or `FAILED` |
| `sessionkey` | Unique session key |
| `GatewayPageURL` | **Redirect customer here** |
| `gw` | Gateway name |
| `storeBanner` | Store banner URL |
| `storeLogo` | Store logo URL |

### IPN Verification

SSLCommerz signs IPN requests using an MD5 hash:
1. Extract `verify_sign` and `verify_key` from POST data
2. Build hash string from fields listed in `verify_key`, sorted alphabetically
3. Append `store_passwd=md5($store_password)`
4. Compute `md5($hash_string)` and compare with `verify_sign` using `hash_equals()`

### Validation API Response

| Field | Description |
|-------|-------------|
| `status` | `VALID`, `VALIDATED`, `FAILED`, `CANCELLED` |
| `tran_id` | Transaction ID |
| `amount` | Paid amount |
| `currency` | Currency |
| `card_type` | Card type used |
| `risk_level` | `0` = safe, `1` = risky |
| `bank_tran_id` | Bank transaction ID |

### 1.1 Security Hardening

**File:** `includes/gateway/class-gateway-sslcommerz.php`

| # | Change | Lines | Priority |
|---|--------|-------|----------|
| 1 | Fix loose comparison: `$this->id != $_REQUEST['tix_payment_method']` → `!==` | 284 | High |
| 2 | Sanitize `$_REQUEST['tix_action']` with `sanitize_text_field()` before switch | 320 | High |
| 3 | Sanitize `$_REQUEST['tix_payment_token']` with `sanitize_text_field()` | 299, 343, 390, 413 | High |
| 4 | Sanitize `$_REQUEST['tran_id']` and `$_REQUEST['val_id']` | 344-345 | High |
| 5 | Replace `die()` with `wp_die()` | 307 | Medium |
| 6 | Rename `ipn_hash_varify()` → `ipn_hash_verify()` | 584 + all callers | Low |

### 1.2 Input Sanitization

**File:** `includes/class-phone-field.php`

| # | Change | Lines | Priority |
|---|--------|-------|----------|
| 7 | Sanitize `$_POST['tix_ticket_info']['phone']` in `edit_attendee_info_form_error()` | 155 | High |
| 8 | Add `sanitize_text_field()` to `$_POST['tix_attendee_save']` check | 154 | Medium |

### 1.3 Phone Validation Enhancement

**File:** `includes/class-phone-field.php`

| # | Change | Priority |
|---|--------|----------|
| 9 | Add BD phone format validation (+880 or 01XXXXXXXXX) | Medium |
| 10 | Add minimum length check (11 digits for local, 14 for international) | Medium |
| 11 | Show validation error via `$camptix->error_flag()` | Medium |

### 1.4 Gateway Enhancement

**File:** `includes/gateway/class-gateway-sslcommerz.php`

| # | Change | Priority |
|---|--------|----------|
| 12 | Add `product_category` parameter to session API call (required by SSLCommerz v4) | Medium |
| 13 | Add customer address fields (`cus_add1`, `cus_city`, `cus_country`) to API call | Low |
| 14 | Add risk level check (flag transactions with `risk_level = 1` for manual review) | Low |
| 15 | Support `store_passwd` rotation (handle both old and new passwords during transition) | Low |

---

## Phase 2: Surjo Pay Gateway

### API Details (v2.1)

| | Sandbox | Production |
|---|---------|------------|
| **Base URL** | `https://sandbox.shurjopayment.com` | Merchant-specific (provided after onboarding) |
| **Auth** | `POST /api/get_token` | `POST /api/get_token` |
| **Checkout** | `POST /api/secret-pay` | `POST /api/secret-pay` |
| **Verify** | `POST /api/verification` | `POST /api/verification` |

**Sandbox Credentials:**
- Username: `sp_sandbox`
- Password: `pyyk97hu&6u6`
- Prefix: `NOK`

**Authentication:** Bearer token (obtained from `/api/get_token`)

### API Flow

```
1. Authenticate → POST /api/get_token
   Request:  { username, password }
   Response: { token, store_id }

2. Checkout → POST /api/secret-pay
   Headers:  Authorization: Bearer {token}
   Request:  {
               token, store_id, prefix, currency, return_url, cancel_url,
               amount, discount_amount, disc_percent, order_id,
               customer_name, customer_phone, customer_email,
               customer_address, customer_city, customer_state,
               customer_postcode, customer_country,
               shipping_address, shipping_city, shipping_country,
               received_person_name, shipping_phone_number,
               client_ip, value1, value2, value3, value4
             }
   Response: { checkout_url }  → redirect customer here

3. Verify → POST /api/verification
   Headers:  Authorization: Bearer {token}
   Request:  { order_id }
   Response: [{ sp_code, order_id, ... }]
   Success:  sp_code == '1000'

4. Return/Cancel → GET {return_url}?order_id={order_id}
   Verify payment using step 3 before confirming
```

### Checkout Parameters

| Parameter | Type | Required | Source |
|-----------|------|----------|--------|
| `token` | string | Yes | From `/api/get_token` |
| `store_id` | string | Yes | From `/api/get_token` |
| `prefix` | string | Yes | Merchant prefix (e.g., `NOK`) |
| `currency` | string | Yes | `BDT` |
| `return_url` | string | Yes | Callback URL (same for success/cancel) |
| `cancel_url` | string | Yes | Callback URL |
| `amount` | decimal | Yes | Order total |
| `discount_amount` | decimal | Yes | `0` for no discount |
| `disc_percent` | integer | Yes | `0` for no discount |
| `order_id` | string | Yes | `{prefix}{uniqid()}` |
| `customer_name` | string | Yes | From attendee form |
| `customer_phone` | string | Yes | 11-digit BD phone |
| `customer_email` | string | Yes | From attendee form |
| `customer_address` | string | Yes | From attendee form |
| `customer_city` | string | Yes | `Dhaka` or from form |
| `customer_state` | string | Yes | `Dhaka` or from form |
| `customer_postcode` | string | Yes | `1209` or from form |
| `customer_country` | string | Yes | `Bangladesh` |
| `shipping_address` | string | No | Optional |
| `shipping_city` | string | No | Optional |
| `shipping_country` | string | No | Optional |
| `received_person_name` | string | No | Optional |
| `shipping_phone_number` | string | No | Optional |
| `client_ip` | string | No | Auto-detected |
| `value1`-`value4` | string | No | Custom data (returned in response) |

### IPN / Return Handling

shurjoPay redirects customer back to `return_url` with `order_id` parameter:
```
{return_url}?order_id={shurjoPay_order_id}
```

**Verification flow:**
1. Extract `order_id` from `$_REQUEST['order_id']`
2. Call `POST /api/verification` with `{ order_id }` and Bearer token
3. Check `response[0]->sp_code == '1000'` for success
4. Call `$camptix->payment_result($payment_token, COMPLETED/FAILED, $data)`

**No separate IPN endpoint** — shurjoPay uses the same return URL for both browser redirect and server-to-server notification. Verification is always done via the `/api/verification` endpoint.

### 2.1 Create Gateway Class

**New file:** `includes/gateway/class-gateway-surjopay.php`

```php
namespace CamptixBD\Gateway;

use CampTix_Plugin, CampTix_Payment_Method;

class SurjoPay extends CampTix_Payment_Method {
    public $id                   = 'surjopay';
    public $name                 = 'Surjo Pay';
    public $description          = 'Surjo Pay payment gateway for Bangladesh.';
    public $supported_currencies = [ 'BDT' ];

    // API endpoints (built in camptix_init)
    private $url_auth;      // /api/get_token
    private $url_checkout;  // /api/secret-pay
    private $url_verify;    // /api/verification
    private $sp_token;      // Bearer token
    private $sp_store_id;   // Store ID from auth
}
```

Methods to implement:

| Method | Description |
|--------|-------------|
| `camptix_init()` | Load options, build API URLs, register hooks |
| `gateway_enabled()` | Check if gateway is active in CampTix settings |
| `authenticate()` | POST to `/api/get_token`, store token + store_id |
| `payment_checkout($token)` | Authenticate → build payload → POST to `/api/secret-pay` → redirect to `checkout_url` |
| `template_redirect()` | Route return/cancel requests |
| `payment_return()` | Extract `order_id` → verify via `/api/verification` → call `payment_result()` |
| `payment_cancel()` | Call `payment_result(CANCELLED)` |
| `verify_payment($order_id)` | POST to `/api/verification` with Bearer token |
| `pre_attendee_timeout()` | Recover timed-out attendees that actually paid |
| `api($method, $endpoint, $body, $auth)` | HTTP client wrapper using `wp_remote_request()` |
| `prepare_transaction_for_log()` | Strip sensitive fields before logging |
| `payment_settings_fields()` | Register admin settings |
| `validate_options($input)` | Sanitize and save settings |
| `add_attendee_info()` | Add phone to attendee object |

### 2.2 Register Gateway

**File:** `bd-payments-camptix.php`

```php
// In includes():
require_once __DIR__ . '/includes/gateway/class-gateway-surjopay.php';

// In load_addons():
camptix_register_addon( '\CamptixBD\Gateway\SurjoPay' );
```

### 2.3 Payment Flow Implementation

```
payment_checkout($payment_token):
  1. Validate payment_token and currency (BDT)
  2. Get order via $this->get_order($payment_token)
  3. Verify order total > 0
  4. Call $this->authenticate()
     → POST /api/get_token { username, password }
     → Store token + store_id
  5. Build payload:
     → token, store_id, prefix
     → currency: BDT
     → return_url, cancel_url (with tix_payment_token)
     → amount: order total
     → order_id: {prefix}{uniqid()}
     → customer fields from attendee data
  6. Store order_id in post meta (for timeout recovery)
  7. POST /api/secret-pay with Bearer token + payload
  8. If response has checkout_url:
     → wp_redirect(checkout_url), exit
  9. Else: log error, call payment_result(FAILED)

template_redirect():
  1. Check tix_payment_method == 'surjopay'
  2. If tix_action == 'return' or 'cancel':
     → Route to payment_return() or payment_cancel()

payment_return():
  1. Get payment_token from URL
  2. Get order_id from $_REQUEST['order_id']
  3. Sanitize order_id with sanitize_text_field()
  4. Call verify_payment($order_id)
     → POST /api/verification { order_id } with Bearer token
  5. If response[0]->sp_code == '1000':
     → Verify amount matches order total
     → Call payment_result(COMPLETED, $data)
  6. Else:
     → Call payment_result(FAILED, $data)

payment_cancel():
  1. Get payment_token from URL
  2. Call payment_result(CANCELLED)
```

### 2.4 Shared Code Refactoring

**New file:** `includes/gateway/class-gateway-base.php`

```php
namespace CamptixBD\Gateway;

abstract class Base_Gateway extends \CampTix_Payment_Method {
    protected function get_attendee_for_payment( $payment_token ) { /* ... */ }
    protected function build_callback_urls( $payment_token ) { /* ... */ }
    protected function prepare_transaction_for_log( $data ) { /* ... */ }
}
```

Both SSLCommerz and Surjo Pay extend `Base_Gateway`.

---

## Phase 3: Ticket Inventory Hold System

> **Problem:** CampTix only counts `publish` + `pending` attendees as sold. Draft attendees (created at checkout before payment) do NOT reduce the available ticket count. This causes overselling.
>
> See `CAMPTIX_BD_PAYMENT_REPORT.md` sections "Current Inventory Logic" and "Oversell Risk During External Payment" for the full analysis.

### 3.1 How It Works Today

```
Ticket quantity: 100
Published attendees: 95
Draft attendees: 20 (in various stages of payment)
Remaining shown to users: 100 - 95 = 5  ← WRONG
```

**Current inventory query** (`camptix.php:5883-5925`):
- Counts only `publish` + `pending` as purchased
- `draft`, `failed`, `cancel`, `timeout` are ignored

### 3.2 Approach A: Count Drafts in Inventory (Simple)

Modify the remaining ticket calculation to include `draft`:

**File to modify:** `public_html/wp-content/plugins/camptix/camptix.php:5925`

```php
// Current:
$attendee_statuses = array( 'publish', 'pending' );

// New:
$attendee_statuses = array( 'publish', 'pending', 'draft' );
```

### 3.3 Approach B: Add 5-Minute Draft Timeout (Better)

Add faster cleanup for abandoned checkouts:

**New file:** `mu-plugins/ticket-hold-release.php`

```php
// Schedule WP-Cron every 2 minutes
add_action( 'camptix_release_abandoned_drafts', 'release_abandoned_drafts' );

function release_abandoned_drafts() {
    // Find draft attendees older than 5 minutes
    // Change their status to 'timeout'
    // This frees the tickets for other buyers
}
```

### 3.4 Flow With Holds

```
User selects tickets
    → [Remaining = quantity - publish - pending - draft]
    → If remaining <= 0: "Sold out"

Checkout creates draft attendees
    → Drafts immediately counted in remaining
    → Other users see reduced availability

Gateway redirects to payment
    → Drafts remain held

Payment completed (IPN + return)
    → draft → publish (already counted)

Payment failed
    → draft → failed (freed from count) ← ALREADY WORKS

Payment cancelled
    → draft → cancel (freed from count) ← ALREADY WORKS

User abandons (no return, no IPN)
    → After 5 min: draft → timeout (freed from count) ← NEW
```

### 3.5 Implementation Steps

| # | Step | File | Priority |
|---|------|------|----------|
| 1 | Add `draft` to `$attendee_statuses` in `get_remaining_tickets()` | mu-plugin override of `camptix.php:5925` | **Critical** |
| 2 | Create mu-plugin `ticket-hold-release.php` | `mu-plugins/ticket-hold-release.php` | **Critical** |
| 3 | Schedule WP-Cron every 2 minutes to release abandoned drafts (>5 min) | `mu-plugins/ticket-hold-release.php` | **Critical** |
| 4 | Verify `payment_result()` correctly transitions draft → failed/cancel | `camptix.php:6648` | High |
| 5 | Add filter to `camptix_timeout` duration (24h → 5min for new drafts) | mu-plugin | High |
| 6 | Log when drafts are released due to timeout | mu-plugin | Medium |

### 3.6 Testing

- [ ] Start checkout for last ticket → verify remaining shows 0 to other users
- [ ] Complete payment → verify attendee becomes publish, remaining stays 0
- [ ] Fail payment → verify attendee becomes failed, remaining returns to 1
- [ ] Cancel payment → verify attendee becomes cancel, remaining returns to 1
- [ ] Abandon checkout → verify draft released after 5 minutes
- [ ] Two users try last ticket simultaneously → only one succeeds

---

## Phase 4: Testing

### 4.1 SSLCommerz Sandbox Testing

- [ ] Enable SSLCommerz in sandbox mode
- [ ] Create a test ticket with BDT currency
- [ ] Complete payment with test VISA card (`4111111111111111`, exp `12/26`, CVV `111`)
- [ ] Verify IPN hash verification works
- [ ] Verify attendee status changed to `publish`
- [ ] Verify ticket count reduced during payment (draft held)
- [ ] Test payment cancellation → verify ticket restored
- [ ] Test payment failure → verify ticket restored
- [ ] Test timeout recovery via `pre_attendee_timeout()`
- [ ] Verify phone number in admin and CSV export

### 4.2 Surjo Pay Sandbox Testing

- [ ] Enable Surjo Pay in sandbox mode
- [ ] Create a test ticket with BDT currency
- [ ] Authenticate: verify `/api/get_token` returns token + store_id
- [ ] Checkout: verify `/api/secret-pay` returns `checkout_url`
- [ ] Complete payment on shurjoPay hosted page
- [ ] Return: verify `order_id` extracted from return URL
- [ ] Verify: verify `/api/verification` returns `sp_code == '1000'`
- [ ] Verify attendee status changed to `publish`
- [ ] Test cancellation flow → verify ticket restored
- [ ] Test failure flow → verify ticket restored

### 4.3 Inventory Edge Cases

- [ ] Last ticket: two users start checkout simultaneously
- [ ] Draft released after 5 min timeout → ticket available again
- [ ] Payment returns after draft already timed out
- [ ] Double notification (idempotency)
- [ ] Mismatched transaction/payment_token
- [ ] Wrong amount in verification response

---

## File Changes Summary

| File | Action | Phase |
|------|--------|-------|
| `includes/gateway/class-gateway-sslcommerz.php` | Edit (security fixes + API params) | 1 |
| `includes/class-phone-field.php` | Edit (sanitization + validation) | 1 |
| `includes/gateway/class-gateway-base.php` | Create (shared base class) | 2 |
| `includes/gateway/class-gateway-surjopay.php` | Create (new gateway) | 2 |
| `bd-payments-camptix.php` | Edit (register Surjo Pay) | 2 |
| `DEVELOPMENT.md` | Edit (update with API docs) | 2 |
| `readme.md` | Edit (add Surjo Pay) | 2 |
| `mu-plugins/ticket-hold-release.php` | Create (5min draft timeout + release) | 3 |
| `camptix.php` (or mu-plugin override) | Edit (count drafts in remaining) | 3 |

---

## Open Questions

1. **Phone field scope** — Should phone be required only when a BD gateway is enabled, or always?
2. **Refund support** — Should we implement `payment_refund()` for either gateway?
3. **Multi-gateway UX** — Should users see both gateways simultaneously, or only one at a time?
4. **Core modification** — OK to modify `camptix.php` directly, or mu-plugin override?
5. **Surjo Pay production credentials** — Have you registered for a merchant account?
