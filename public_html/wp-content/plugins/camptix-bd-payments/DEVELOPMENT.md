# CampTix BD Payments - Development Documentation

## Overview

CampTix BD Payments is a WordPress plugin that integrates Bangladeshi payment gateways with the [CampTix](https://github.com/WordPress/camptix) ticketing plugin. It currently supports **SSLCommerz** and is being extended to support **Surjo Pay**.

**Version:** 1.3
**Requires:** WordPress Multisite, CampTix plugin, PHP 7.4+
**Text Domain:** `bd-payments-camptix`

---

## Architecture

```
camptix-bd-payments/
├── bd-payments-camptix.php                  # Main plugin bootstrap
├── includes/
│   ├── class-phone-field.php                # Phone number field addon (shared by all gateways)
│   └── gateway/
│       ├── class-gateway-sslcommerz.php     # SSLCommerz payment gateway
│       └── class-gateway-surjopay.php       # Surjo Pay payment gateway (planned)
├── DEVELOPMENT.md                           # This file
└── readme.md                                # User-facing readme
```

### How It Works

1. **Main plugin file** (`bd-payments-camptix.php`) checks if CampTix is loaded, then includes gateway files and registers them as CampTix addons.

2. **Phone Field** (`class-phone-field.php`) is a CampTix addon (not a payment method) that adds a required phone number field to the attendee registration form. It hooks into:
   - `camptix_attendee_form_additional_info` - renders the field
   - `camptix_form_register_complete_attendee_object` - adds phone to attendee object
   - `camptix_checkout_update_post_meta` - saves phone as post meta (`tix_phone`)
   - `camptix_metabox_attendee_info_additional_rows` - shows phone in admin
   - `camptix_form_edit_attendee_*` - edit form support
   - `camptix_attendee_report_*` - CSV export support

3. **Payment Gateways** extend `CampTix_Payment_Method` (from CampTix core) and implement:
   - `payment_checkout($payment_token)` - initiate payment with the gateway
   - `payment_settings_fields()` - admin settings UI
   - `validate_options($input)` - sanitize settings on save
   - `camptix_init()` - initialize hooks when gateway is enabled

### CampTix Payment Flow

```
User selects tickets → CampTix creates attendee posts (status: draft)
    → payment_checkout($token) called with a unique payment token
    → Gateway redirects user to payment provider
    → User completes payment
    → Gateway sends IPN (server-to-server POST) to notify_url
    → Gateway redirects user back to return_url (browser GET)
    → Gateway calls $camptix->payment_result($token, STATUS, $data)
    → CampTix updates attendee post status (publish = paid, trash = failed)
```

### Key CampTix Methods Available to Gateways

Via `CampTix_Payment_Method` base class:
- `$this->get_order($payment_token)` - get order details (items, total, currency)
- `$this->payment_result($token, $status, $data)` - report payment outcome
- `$this->log($message, $post_id, $data, $module)` - write to CampTix log
- `$this->get_payment_options()` - get saved gateway settings
- `$this->field_text()`, `field_yesno()`, `field_checkbox()` - settings field renderers

### CampTix Payment Status Constants

```php
CampTix_Plugin::PAYMENT_STATUS_COMPLETED
CampTix_Plugin::PAYMENT_STATUS_FAILED
CampTix_Plugin::PAYMENT_STATUS_CANCELLED
CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED
```

---

## SSLCommerz Gateway (Current)

### API Version
v4 (Session-based)

### Endpoints

| Environment | Base URL |
|-------------|----------|
| Sandbox | `https://sandbox.sslcommerz.com` |
| Production | `https://securepay.sslcommerz.com` |

**Required TLS:** 1.2+

### API Calls Used

1. **Session Init** - `POST /gwprocess/v3/api.php`
   - Creates a payment session
   - Returns `GatewayPageURL` to redirect the customer
   - Required params: `store_id`, `store_passwd`, `total_amount`, `currency`, `tran_id`, `success_url`, `fail_url`, `cancel_url`, `cus_name`, `cus_email`, `cus_phone`, `product_category`

2. **Order Validation** - `GET /validator/api/validationserverAPI.php`
   - Validates an IPN notification
   - Returns status: `VALID`, `VALIDATED`, `FAILED`, `CANCELLED`
   - Response includes: `tran_id`, `amount`, `currency`, `card_type`, `risk_level`, `bank_tran_id`

3. **Transaction Check** - `GET /validator/api/merchantTransIDvalidationAPI.php`
   - Checks transaction status by session key
   - Used for timeout recovery

### Session Init Response

| Field | Description |
|-------|-------------|
| `status` | `SUCCESS` or `FAILED` |
| `sessionkey` | Unique session key |
| `GatewayPageURL` | **Redirect customer here** |
| `gw` | Gateway name |

### IPN Verification

SSLCommerz signs IPN requests using an MD5 hash of sorted key=value pairs including the store password. The verification flow:

1. Extract `verify_sign` and `verify_key` from POST data
2. Build hash string from the fields listed in `verify_key`, sorted alphabetically
3. Append `store_passwd=md5($store_password)` to the hash string
4. Compute `md5($hash_string)` and compare with `verify_sign` using `hash_equals()`

### Security Measures Already Implemented

- IPN hash verification (`ipn_hash_varify()`)
- Transaction-to-token binding (prevents replay attacks across orders)
- Amount validation (order total must match gateway response)
- Timing-safe comparison (`hash_equals()`)
- Sensitive data stripped from logs (`prepare_transaction_for_log()`)
- POST-to-GET redirect to handle cross-domain cookie issues

### Risk Level Handling

SSLCommerz returns `risk_level` in validation responses:
- `0` = safe transaction
- `1` = risky transaction (hold and verify manually)

### Known Issues

See `CODEBASE_AUDIT_REPORT.md` for the full list. Key items:
- Loose comparison (`!=`) on `tix_payment_method` check
- `$_REQUEST` values used without sanitization in some places
- Method name typo: `ipn_hash_varify` → `ipn_hash_verify`
- `die()` instead of `wp_die()`

---

## Surjo Pay Gateway (Planned)

### API Version
v2.1 (RESTful)

### Endpoints

| | Sandbox | Production |
|---|---------|------------|
| **Base URL** | `https://sandbox.shurjopayment.com` | Merchant-specific |
| **Auth** | `POST /api/get_token` | `POST /api/get_token` |
| **Checkout** | `POST /api/secret-pay` | `POST /api/secret-pay` |
| **Verify** | `POST /api/verification` | `POST /api/verification` |

### Sandbox Credentials

| Field | Value |
|-------|-------|
| Username | `sp_sandbox` |
| Password | `pyyk97hu&6u6` |
| Prefix | `NOK` |

### API Flow

1. **Authenticate** — `POST /api/get_token` with `{ username, password }` → returns `{ token, store_id }`
2. **Checkout** — `POST /api/secret-pay` with Bearer token + transaction payload → returns `{ checkout_url }`
3. **Redirect** — Send customer to `checkout_url`
4. **Return** — shurjoPay redirects back to `return_url?order_id={id}`
5. **Verify** — `POST /api/verification` with Bearer token + `{ order_id }` → returns `[{ sp_code: '1000' }]` on success

### Checkout Parameters

Required: `token`, `store_id`, `prefix`, `currency` (BDT), `return_url`, `cancel_url`, `amount`, `discount_amount`, `disc_percent`, `order_id`, `customer_name`, `customer_phone`, `customer_email`, `customer_address`, `customer_city`, `customer_state`, `customer_postcode`, `customer_country`

Optional: `shipping_address`, `shipping_city`, `shipping_country`, `received_person_name`, `shipping_phone_number`, `client_ip`, `value1`-`value4` (custom data)

### IPN / Return Handling

shurjoPay uses the same URL (`return_url`) for both browser redirect and IPN. The `order_id` parameter is passed as a GET parameter. Verification is always done via the `/api/verification` endpoint — there is no separate IPN signature/hash mechanism.

**Success indicator:** `response[0]->sp_code == '1000'`

### PHP SDK

Available via Composer: `shurjomukhi/shurjopay-plugin-php`

Key classes: `Shurjopay`, `ShurjopayConfig`, `ShurjopayEnvReader`, `PaymentRequest`

GitHub: https://github.com/shurjopay-plugins/sp-plugin-php

### Planned Architecture

```
includes/gateway/class-gateway-surjopay.php
```

The Surjo Pay gateway will:
- Extend `CampTix_Payment_Method` (or shared `Base_Gateway`)
- Implement `authenticate()` — POST to `/api/get_token`
- Implement `payment_checkout()` — build payload → POST to `/api/secret-pay` → redirect
- Implement `payment_return()` — extract `order_id` → verify via `/api/verification` → call `payment_result()`
- Implement `payment_cancel()` — call `payment_result(CANCELLED)`
- Support sandbox/production toggle
- Add timeout recovery via `pre_attendee_timeout()`

---

## Development Setup

### Prerequisites

1. Docker (via `docker-compose.yaml` at project root)
2. CampTix plugin activated
3. This plugin activated
4. SSLCommerz sandbox account (register at https://developer.sslcommerz.com/registration/)

### Running Locally

```bash
# From project root
docker compose up -d

# Access WordPress at http://localhost:8888
# CampTix settings: wp-admin → Tickets → Setup → Payment
```

### Testing Payments

**SSLCommerz Sandbox Test Cards:**

| Card | Number | Exp | CVV |
|------|--------|-----|-----|
| VISA | 4111111111111111 | 12/26 | 111 |
| Mastercard | 5111111111111111 | 12/26 | 111 |
| AMEX | 371111111111111 | 12/26 | 111 |

**Mobile OTP:** `111111` or `123456`

**Surjo Pay Sandbox:**

| Field | Value |
|-------|-------|
| Username | `sp_sandbox` |
| Password | `pyyk97hu&6u6` |
| Prefix | `NOK` |
| API Base | `https://sandbox.shurjopayment.com` |

---

## Adding a New Payment Gateway

To add a new gateway (e.g., Surjo Pay):

### Step 1: Create the gateway class

```php
<?php
namespace CamptixBD\Gateway;

use CampTix_Plugin, CampTix_Payment_Method;

class SurjoPay extends CampTix_Payment_Method {
    public $id                   = 'surjopay';
    public $name                 = 'Surjo Pay';
    public $description          = 'Surjo Pay payment gateway for Bangladesh.';
    public $supported_currencies = [ 'BDT' ];

    public function camptix_init() { /* ... */ }
    public function payment_checkout( $payment_token ) { /* ... */ }
    public function payment_settings_fields() { /* ... */ }
    public function validate_options( $input ) { /* ... */ }
}
```

### Step 2: Register in main plugin file

```php
// In bd-payments-camptix.php, add to includes():
require_once __DIR__ . '/includes/gateway/class-gateway-surjopay.php';

// In load_addons():
camptix_register_addon( '\CamptixBD\Gateway\SurjoPay' );
```

### Step 3: Implement the payment flow

1. `payment_checkout()` - Call Surjo Pay's session/init API, redirect to gateway
2. `template_redirect()` - Handle IPN and return callbacks
3. `payment_notify()` - Verify IPN signature, validate transaction, call `payment_result()`
4. `payment_cancel()` / `payment_failed()` - Handle failure cases

### Step 4: Add settings fields

```php
public function payment_settings_fields() {
    $this->add_settings_field_helper( 'merchant_id', __( 'Store ID', 'bd-payments-camptix' ), [ $this, 'field_text' ] );
    $this->add_settings_field_helper( 'store_password', __( 'Store Password', 'bd-payments-camptix' ), [ $this, 'field_text' ] );
    $this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'bd-payments-camptix' ), [ $this, 'field_yesno' ] );
}
```

### Step 5: Test

1. Enable gateway in CampTix settings
2. Set sandbox mode
3. Create a test ticket and go through checkout
4. Verify IPN handling, success redirect, and attendee status update

---

## Coding Standards

- Follow WordPress Coding Standards (WPCS)
- Use namespaces (`CamptixBD\*`)
- Sanitize all input: `sanitize_text_field()`, `absint()`, `esc_attr()`
- Escape all output: `esc_html()`, `esc_attr()`, `esc_url()`
- Use `hash_equals()` for timing-safe string comparison
- Use `$wpdb->prepare()` for all database queries
- Log sensitive operations but strip credentials from log data
