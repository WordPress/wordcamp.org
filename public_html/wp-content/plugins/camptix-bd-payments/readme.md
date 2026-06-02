# CampTix BD Payments

**Contributors:** Tareq Hasan, Nazmul Hosen
**Tags:** camptix, payment, bangladesh, sslcommerz, surjopay
**Requires at least:** 5.0
**Tested up to:** 6.8
**Stable Tag:** 1.4
**License:** GPLv2 or later
**License URI:** http://www.gnu.org/licenses/gpl-2.0.html

Bangladeshi payment gateway integration for CampTix

## Description

A payment gateway addon for the [CampTix](http://github.com/developer-developer/camptix) plugin that supports Bangladeshi payment gateways:

- [SSLCommerz](https://sslcommerz.com/) - Session-based payment gateway
- [Surjo Pay](https://shurjopay.com.bd/) - shurjoPay Payment Gateway v2.1

### Features

- **Multiple Gateways** - SSLCommerz and Surjo Pay support
- **Phone Field** - Required phone number on attendee registration with BD format validation
- **Ticket Hold System** - Prevents overselling by counting draft attendees as "held" during checkout
- **Auto-Release** - Abandoned drafts are released after 5 minutes
- **Timeout Recovery** - Paid orders are recovered even if the attendee times out
- **CSV Export** - Phone numbers included in attendee CSV export
- **Sandbox Mode** - Test with sandbox credentials before going live

## Installation

1. Upload the plugin files to the `/wp-content/plugins/camptix-bd-payments` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Tickets > Setup screen to configure the payment gateways

## Configuration

### SSLCommerz

1. Get your store ID and password from [SSLCommerz](https://developer.sslcommerz.com/registration/)
2. Enable SSLCommerz in Tickets > Setup > Payment
3. Enter your store ID and password
4. Toggle sandbox mode for testing

### Surjo Pay

1. Register at [Surjo Pay](https://shurjopay.com.bd/)
2. Get your username, password, and API endpoint after onboarding
3. Enable Surjo Pay in Tickets > Setup > Payment
4. Enter your credentials
5. Toggle sandbox mode for testing

## Changelog

**1.4 - 2 jun 2026**
- Added Surjo Pay (shurjoPay) payment gateway
- Added shared Base_Gateway class for common gateway logic
- Added ticket inventory hold system (drafts count as held tickets)
- Added 5-minute auto-release for abandoned checkouts
- Security: sanitized all $_REQUEST inputs in gateways
- Security: strict comparisons for payment method checks
- Security: renamed ipn_hash_varify to ipn_hash_verify
- Phone field: added BD phone format validation (+880/01XX)
- Phone field: added sanitization in edit form
- Replaced die() with wp_die()

**1.3 - 14th Nov 2025**
- Update camptix compat version.
- Update email subject of refund

**1.2 - 14th Nov 2025**
- Added phone field support to register, view, and edit an attendee.
- Refactored namespaces.
- Refund request feature added.

**1.1 - 7th Sept, 2025**
- Update compatibility
- Added sanitize_phone_number

**1.0 - 7th Sept, 2025**
- Initial release
