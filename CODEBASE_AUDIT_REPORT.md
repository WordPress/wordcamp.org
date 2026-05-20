# WordCamp.org Codebase Audit Report

**Date:** 2026-05-20
**Branch:** `camptix-bd-payments-fixing`
**Auditor:** OpenClaude (mimo-v2.5-pro)

---

## Executive Summary

This is a large WordPress multisite installation powering WordCamp.org -- the official platform for organizing WordPress community events worldwide. The codebase contains **40+ custom plugins**, **12+ custom themes**, and **24+ mu-plugins**, along with extensive infrastructure configuration.

The codebase is generally well-maintained by experienced WordPress developers, but has accumulated significant technical debt over 10+ years. The most critical findings are **missing CSRF nonce verification** in several high-traffic forms (CampTix checkout, refunds, attendee editing), **weak cryptographic token generation** in the ticketing system, and **outdated PHP/dependency targets**.

### Severity Summary

| Severity | Count |
|----------|-------|
| **Critical** | 8 |
| **High** | 16 |
| **Medium** | 22 |
| **Low** | 25 |

---

## Critical Findings

### 1. Missing Nonce on CampTix Checkout Form
**File:** `public_html/wp-content/plugins/camptix/camptix.php` (~lines 6114-6330)
**Severity:** Critical

The entire checkout flow -- which creates attendee posts and triggers payment processing -- lacks CSRF nonce verification. An attacker could craft a cross-site request to purchase tickets on behalf of an authenticated user.

### 2. Missing Nonce on Refund Request Form
**File:** `public_html/wp-content/plugins/camptix/camptix.php` (~lines 5484-5593)
**Severity:** Critical

Refund requests can be submitted without CSRF protection. Combined with the access token in the URL, this allows cross-site refund attacks.

### 3. Weak Cryptographic Token Generation
**File:** `public_html/wp-content/plugins/camptix/camptix.php` (~lines 6236-6249)
**Severity:** Critical

Access tokens, payment tokens, and edit tokens are generated using `md5()` with `rand()` and `time()`. These are cryptographically weak:
- `rand()` is not cryptographically secure
- `time()` is predictable
- `md5` is not a suitable key derivation function

**Recommendation:** Use `wp_generate_password()`, `random_bytes()`, or `bin2hex(random_bytes(32))`.

### 4. Weak Idempotency Tokens (PayPal & Stripe)
**Files:** `camptix/addons/payment-paypal.php`, `camptix/addons/payment-stripe.php`
**Severity:** Critical

Idempotency tokens use `md5('tix-idempotency-token' . $payment_token . time() . rand(1, 9999))` -- same weak pattern as above.

### 5. Missing Nonce on Attendee Edit Form
**File:** `public_html/wp-content/plugins/camptix/camptix.php` (~line 5193)
**Severity:** Critical

Attendee information can be modified without nonce verification, gated only by an edit_token (which itself is weakly generated).

### 6. Unauthenticated AJAX Endpoint for Attendance Tracking
**File:** `public_html/wp-content/plugins/camptix-attendance/addons/attendance.php` (~lines 49-118)
**Severity:** Critical

The attendance AJAX endpoint is registered for both `wp_ajax_` and `wp_ajax_nopriv_`, meaning unauthenticated users can modify attendance data if they know the secret. There is no nonce verification and no WordPress capability check. The secret is compared with loose comparison (`==`).

### 7. Path Traversal in Invoice Generator
**File:** `public_html/wp-content/plugins/camptix-invoices/camptix-invoices.php` (~line 521)
**Severity:** Critical

`ctx_get_invoice()` constructs a file path from post meta without proper path traversal protection: `$path = $invoices_dirname . '/' . $invoice_document;`. If `invoice_document` contains `../`, this could allow reading arbitrary files.

### 8. SQL Injection in Network Tools
**File:** `public_html/wp-content/plugins/camptix-network-tools/network-dashboard.php` (~lines 121-125)
**Severity:** Critical

SQL queries use direct string interpolation of `$events_ids` without `$wpdb->prepare()`: `DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( $events_ids )`. While the values come from a prior DB query, this is a dangerous pattern.

---

## High Severity Findings

### 6. Header Injection Risk in SSL Redirect
**File:** `public_html/wp-content/mu-plugins/ssl.php` (line 36)
**Severity:** High

Uses `$_SERVER['HTTP_HOST']` and `$_SERVER['REQUEST_URI']` directly in a `header()` call without sanitization. An attacker could inject headers via a crafted `HTTP_HOST` value.

### 7. CSRF in wordcamp-central-2012 Subscribe Form
**File:** `public_html/wp-content/themes/wordcamp-central-2012/functions.php` (lines 120-178)
**Severity:** High

The subscribe form processes `$_REQUEST` without nonce verification. This is a classic CSRF vulnerability.

### 8. SQL Dump Committed in Theme
**File:** `public_html/wp-content/themes/wporg-events-2023/env/wporg_events_dev.sql`
**Severity:** High

A 40,021-line SQL dump file containing real database data is committed to the repository. This could expose sensitive data (user emails, hashed passwords, API keys) if the repository is public.

### 9. Unsanitized `$_POST` in Metabox Save (wordcamp-base themes)
**Files:** `themes/wordcamp-base/lib/utils/class-wcb-post-metabox.php`, `themes/wordcamp-base-v2/lib/utils/class-wcb-post-metabox.php` (lines 70-71)
**Severity:** High

Raw `$_POST` values are directly assigned to metadata updates without calling `sanitize_text_field()`. While nonce verification exists, the data itself passes unsanitized.

### 10. Unsanitized Form Data in Events Application
**File:** `public_html/wp-content/mu-plugins/events/jetpack-form-to-wcpt-application.php` (lines 92-100)
**Severity:** High

Jetpack Form submissions are mapped directly to post meta without sanitization. Values like `$name`, `$email`, `$wporg` are taken from form fields and stored as post meta.

### 11. `FILTER_UNSAFE_RAW` Usage
**File:** `public_html/wp-content/mu-plugins/camptix-tweaks/addons/ticket-types/ticket-types.php` (line 168)
**Severity:** High

Uses `filter_input(INPUT_POST, 'tix_type', FILTER_UNSAFE_RAW)` which provides no sanitization. The value is saved directly to post meta.

### 12. Missing Nonce on Private Content Form
**File:** `public_html/wp-content/plugins/camptix/addons/shortcodes.php` (~lines 435-486)
**Severity:** High

The `[camptix_private]` form processes email submissions without nonce verification.

### 13. Public REST Endpoint with API Key Only
**File:** `public_html/wp-content/mu-plugins/wordcamp/lets-encrypt-helper.php` (line 26)
**Severity:** High

The Let's Encrypt helper REST endpoint uses `'permission_callback' => '__return_true'` (publicly accessible), relying solely on an API key parameter for authentication.

### 14. Missing Nonce on Meetup OAuth Handler
**File:** `public_html/wp-content/mu-plugins/wcorg-meetup-oauth.php` (lines 3-5)
**Severity:** High

Checks `$_GET['code']` and `$_GET['state']` without nonce verification or capability checks.

### 15. Trusted Deputies Get Full Administrator Capabilities
**File:** `public_html/wp-content/mu-plugins/trusted-deputy-capabilities.php` (line 39)
**Severity:** High

`trusted_deputy_has_cap()` merges the entire `administrator` role capabilities plus network management caps. Any user in the `$trusted_deputies` global array gets full administrator capabilities on all sites.

### 16. Weak View Token Generation
**File:** `public_html/wp-content/plugins/camptix/addons/shortcodes.php` (~line 676)
**Severity:** High

`$view_token = md5('tix-view-token-' . strtolower($email . $ip))` is deterministic and guessable if the attacker knows the attendee's email and IP. No secret salt is included.

### 17. World-Readable Temp Directory
**File:** `public_html/wp-content/mu-plugins/0-error-handling.php` (line 28)
**Severity:** High

Error rate-limiting files are stored in `/tmp/error_limiting` with no restrictive permissions set on `mkdir()`.

### 18. CSRF in Email Post Changes Subscription
**File:** `public_html/wp-content/plugins/email-post-changes-specific-post/widget-subscribe.php` (line 39)
**Severity:** High

Missing nonce verification on the front-end subscription form. An attacker could forge a request to subscribe arbitrary email addresses to posts.

### 19. Stored XSS in wc-fonts
**File:** `public_html/wp-content/plugins/wc-fonts/wc-fonts.php` (line 56)
**Severity:** High

Google Web Fonts CSS option is output inside a `<style>` tag without CSS sanitization: `printf( '<style>%s</style>', $this->options['google-web-fonts'] )`. If an admin saves malicious CSS/JavaScript, it will be executed.

### 20. Unsanitized $_REQUEST in camptix-attendance AJAX
**File:** `public_html/wp-content/plugins/camptix-attendance/addons/attendance.php` (~lines 85-175)
**Severity:** High

Multiple `$_REQUEST` values (`camptix_action`, `camptix_id`, `camptix_set_attendance`) used without sanitization or nonce verification in the AJAX handler.

### 21. Unsanitized $_REQUEST in camptix-network-tools
**File:** `public_html/wp-content/plugins/camptix-network-tools/includes/class-camptix-network-log-list-table.php` (lines 23, 47)
**Severity:** High

`$_REQUEST['s']` and `$_REQUEST['tix_log_section']` used without sanitization in list table classes.

---

## Medium Severity Findings

### 18. PHP Platform Target End of Life
**File:** `composer.json` (line 11)
**Severity:** Medium

Platform PHP is set to `8.1`, which reached end of life in December 2025. Should be upgraded to 8.2 or 8.3 minimum.

### 19. PHPUnit Incompatibility
**File:** `composer.json`, `phpunit.xml.dist`
**Severity:** Medium

PHPUnit `^9` is required, but `phpcs.xml.dist` targets PHP `8.4-`. PHPUnit 9 does not fully support PHP 8.4. PHPUnit 10+ is needed.

### 20. Non-Reproducible Builds
**File:** `composer.json` (lines 45-170)
**Severity:** Medium

All third-party plugins use `version: "999"` with `latest-stable.zip` downloads. Every `composer install` fetches the latest version, making builds non-reproducible.

### 21. Direct DB Queries Without `prepare()`
**Files:** `mu-plugins/4-helpers-wcpt.php` (line 333), `camptix/camptix.php` (line ~2061), `mu-plugins/camptix-tweaks/addons/extra-fields/privacy.php` (line 113)
**Severity:** Medium

Several direct SQL queries bypass `$wpdb->prepare()`. While most don't accept user input, this is a best-practice violation that could become dangerous if the code is modified.

### 22. Raw `$_POST` Logged
**Files:** `camptix/camptix.php` (~lines 3920, 5247, 6233, 6310)
**Severity:** Medium

The plugin logs raw `$_POST` data in several places, potentially including payment tokens, PII, and passwords.

### 23. `$_SERVER` Superglobals Used Without Sanitization
**Files:** Multiple across mu-plugins and themes
**Severity:** Medium

`$_SERVER['HTTP_HOST']`, `$_SERVER['REQUEST_URI']`, `$_SERVER['HTTP_USER_AGENT']`, `$_SERVER['REMOTE_ADDR']` are accessed directly throughout the codebase without sanitization.

### 24. Deprecated `wp_title()` Function
**Files:** `themes/wordcamp-base/lib/utils/header.php`, `themes/wordcamp-base-v2/header.php`, `themes/plan/header.php`
**Severity:** Medium

`wp_title()` has been deprecated since WordPress 4.4 in favor of `add_theme_support('title-tag')`.

### 25. `extract()` Usage
**Files:** 6 files across wordcamp-base and wordcamp-base-v2
**Severity:** Medium

`extract()` on arrays that derive from user-influenced data can overwrite local variables and create unpredictable behavior.

### 26. Hardcoded Blog IDs
**Files:** Multiple mu-plugins and camptix-tweaks
**Severity:** Medium

Hardcoded blog IDs (364, 112, 217, 406, 558, 1056, 1415, 7694169) are scattered across the codebase. These are fragile and will break if the database is recreated with different IDs.

### 27. Operator Precedence Bug
**File:** `mu-plugins/structured-data.php` (lines 77, 88)
**Severity:** Medium

`(int) $wordcamp->meta['...'][0] ?? 0` -- the `(int)` cast has higher precedence than `??`, so the null coalescing will never trigger. Works by accident but is technically incorrect.

### 28. Translation Errors
**Files:** `mu-plugins/camptix-tweaks/views/html-mail-footer.php`, `mu-plugins/camptix-tweaks/views/notice-sandbox-mode.php`
**Severity:** Medium

`_e()` is used with HTML strings. The `_e()` function escapes output, so HTML tags will be displayed as text, not rendered. Should use `wp_kses_post()`.

### 29. Outdated DevDependencies
**File:** `package.json`
**Severity:** Medium

- `@wordpress/eslint-plugin: 24.1.0` (current: 30.x+)
- `@wordpress/jest-preset-default: 12.39.0` (current: 20.x+)
- `@wordpress/scripts: 27.3.0` (current: 30.x+)

### 30. Missing `esc_attr()` Echo
**File:** `camptix/addons/track-attendance.php` (line ~37)
**Severity:** Medium

`esc_attr()` returns the escaped value but does not echo it. The `id` attribute will be empty.

### 31. Deprecated Currency Codes
**File:** `camptix/inc/class-camptix-currencies.php`
**Severity:** Medium

`HRK` (Croatian Kuna, replaced by EUR in 2023) and `MRO` (Mauritanian Ouguiya, replaced by MRU in 2018) are still listed.

### 32. No Health Checks in Docker
**File:** `docker-compose.yaml`
**Severity:** Medium

Neither service defines a healthcheck, restart policy, or resource limits.

### 33. Default Salts in wp-config
**File:** `.docker/wp-config.php` (lines 121-129)
**Severity:** Medium

All authentication keys and salts use `'put your unique phrase here'`. If accidentally deployed to production, all auth tokens would be predictable.

### 34. Rendering Shortcodes in Block Patterns
**File:** `themes/wporg-parent-2021/functions.php` (line 29)
**Severity:** Medium

`add_filter('render_block_core/pattern', 'do_shortcode')` enables shortcode execution inside block patterns. If a pattern is modified by a lower-privilege user, this could lead to shortcode-based attacks.

### 35. Deprecated `wp_get_sites()` Usage
**File:** `mu-plugins/wp-cli-commands/users.php` (line 78)
**Severity:** Medium

Uses `wp_get_sites()` which was deprecated since WordPress 4.6. Should use `get_sites()`.

### 36. Extremely Outdated Minimum PHP Version
**Files:** `plugins/tagregator/classes/tggr-shortcode-tagregator.php`, `plugins/campt-indian-payment-gateway/campt-indian-payment-gateway.php`
**Severity:** Medium

Both plugins declare a minimum PHP version of 5.3, which is extremely outdated and unsupported.

### 37. `$_GET` Without Sanitization in Budget Tool
**File:** `plugins/wordcamp-payments/includes/budget-tool.php` (line 376)
**Severity:** Medium

`$_GET['wcb-view']` used without sanitization in a switch statement.

### 38. Loose Comparison in Attendance Secret
**File:** `plugins/camptix-attendance/addons/attendance.php` (line 52, 82)
**Severity:** Medium

The attendance secret is compared with loose comparison (`==` and `!=`) instead of strict comparison. This could allow type juggling attacks.

### 39. `$_POST` Modification in wordcamp-forms-to-drafts
**File:** `plugins/wordcamp-forms-to-drafts/wordcamp-forms-to-drafts.php` (lines 196-200)
**Severity:** Medium

`$_POST` is directly modified without nonce verification in `populate_form_based_on_user()`, relying on Jetpack's nonce handling.

### 40. Outdated PHP Version Check in campt-indian-payment-gateway
**File:** `plugins/campt-indian-payment-gateway/campt-indian-payment-gateway.php` (line 215)
**Severity:** Medium

`version_compare(phpversion(), '5.3', '>=')` -- the minimum PHP version check is for PHP 5.3, which is extremely outdated.

---

## Low Severity Findings

### 36. `@` Error Suppression
**File:** `camptix/addons/shortcodes.php` (~line 479)
Uses `@$_SERVER['REMOTE_ADDR']` instead of `$_SERVER['REMOTE_ADDR'] ?? ''`.

### 37. Hardcoded HTTP URL
**File:** `camptix/addons/field-twitter.php` (~line 60)
Uses `http://twitter.com/` instead of `https://`.

### 38. Predictable Log File Paths
**Files:** `camptix/addons/logging-file.php`, `camptix/addons/logging-file-json.php`
Logs written to `/tmp/camptix.log` and `/tmp/camptix.json.log` which are predictable on shared hosting.

### 39. `die()` Used Instead of `wp_die()`
**File:** `camptix/addons/payment-paypal.php` (~line 451)

### 40. Duplicate Help Tab ID
**File:** `camptix/help.php` (~line 157)
Two help tabs share `id => 'tix-export'`.

### 41. PHP4-Style Constructors
**Files:** `themes/wordcamp-base/lib/` metabox classes

### 42. No Theme Test Coverage
None of the custom themes have test suites.

### 43. IE 6/7/8 Compatibility Code
**Files:** `themes/wordcamp-central-2012`, `themes/plan`
IE conditional comments and HTML5 shims for ancient browsers are still present.

### 44. Incorrect `Requires PHP: 5.7`
**Files:** `themes/campus-connect/style.css`, `themes/student-clubs/style.css`
PHP 5.7 does not exist; this is likely a typo for 7.x.

### 45. Outdated TODO Comments
**File:** `mu-plugins/wcorg-misc.php` (line 87)
Comment says "todo can remove this after upgrade to 4.9" -- WordPress is well past 4.9.

### 46. Typo in Log Key
**File:** `mu-plugins/3-helpers-misc.php` (line 324)
`'request_failed_permenantly'` should be `'request_failed_permanently'`.

### 47. Loose Comparisons Throughout
Multiple files use `!=` instead of `!==` and `==` instead of `===` where strict comparisons would be safer.

### 48. `setlocale()` Thread Safety
**File:** `mu-plugins/3-helpers-misc.php` (line 234)
`setlocale(LC_CTYPE, ...)` changes global state and is not thread-safe.

### 49. Protocol-Relative URLs
**File:** `themes/wordcamp-central-2012/header.php` (line 33)
Uses `//use.typekit.com/...` -- protocol-relative URLs are deprecated practice.

### 50. Unescaped `bloginfo()` Call
**File:** `themes/plan/header.php` (line 39)
`bloginfo('description')` echoed without `esc_html()`.

### 51. Hardcoded WordPress.org URLs
Multiple files hardcode URLs like `https://central.wordcamp.org`, `https://events.wordpress.org`, etc.

### 52. Nonce Verification Excluded from PHPCS
**File:** `phpcs.xml.dist` (lines 208-209)
Both `WordPress.Security.NonceVerification.Missing` and `Recommended` are disabled, making CSRF checks entirely manual.

### 53. `$_POST` Superglobal Modified
**File:** `camptix/inc/class-camptix-admin-tools.php` (~line 334)
`$_POST = wp_unslash($_POST)` modifies the superglobal directly.

### 54. Object Cache Manipulation
**File:** `camptix/addons/field-tshirt.php` (~lines 104-127)
Directly manipulates `$wpdb->queries` and `$wp_object_cache` internals.

### 55. `debug_community_events_response()` Dead Code
**File:** `mu-plugins/wcorg-misc.php` (lines 629-684)
Function is defined but the `add_action` call is commented out.

### 56. Hardcoded Cron Timezone
**File:** `mu-plugins/cron.php` (line 33)
Hardcodes `'Next weekday 10am America/Los_Angeles'`.

### 57. Unescaped Robots.txt Output
**File:** `mu-plugins/robots.php` (line 70)
`Disallow` paths use raw `$path` values from `parse_url()` without escaping.

### 58. Outdated Access Guard Pattern
**Files:** `plugins/tagregator/bootstrap.php` (line 12)
Uses `$_SERVER['SCRIPT_FILENAME'] == __FILE__` as direct access guard instead of `defined('ABSPATH') || exit;`.

### 59. SSL Verification Disabled in Local
**File:** `plugins/wordcamp-qbo-client/wordcamp-qbo-client.php` (line 259)
`sslverify = false` in local environments. Intentional for development but should be clearly documented.

### 60. Unescaped Error Messages
**File:** `plugins/email-post-changes-specific-post/widget-subscribe.php` (lines 43-45)
Error messages output as `'<p>' . $error . '</p>'` without escaping.

### 61. `$_GET` Without Sanitization
**File:** `plugins/email-post-changes/class.email-post-changes.php` (line 382)
`$_GET['settings-updated']` accessed without sanitization in boolean context.

### 62. Raw SQL in camptix-admin-flags
**File:** `plugins/camptix-admin-flags/addons/admin-flags.php` (line 356)
Direct SQL query without `$wpdb->prepare()` for counting admin flags.

### 63. `sanitize_key()` Used as HTML Escaping
**File:** `plugins/wc-fonts/wc-fonts.php` (lines 43-44, 68-69)
`sanitize_key()` is used as the escaping function for Typekit and Font Awesome output, but it's not an HTML escaping function.

---

## Architecture & Code Quality Observations

### Positive Aspects
- **Well-organized codebase**: Clear separation between mu-plugins, plugins, and themes
- **Good use of namespaces**: Most modern code uses PHP namespaces properly
- **Proper escaping in newer code**: campsite-2017 and newer themes follow WordPress escaping best practices
- **Error handling**: The Slack-based error reporting system is well-designed with rate limiting
- **Capability system**: Subroles and trusted deputies are thoughtfully implemented
- **PHPCS/WPCS integration**: Coding standards are configured (though with many exclusions)
- **Stripe webhook verification**: Properly implements signature verification using `hash_hmac('sha256', ...)`

### Technical Debt
- **Legacy themes**: wordcamp-base, wordcamp-base-v2, wordcamp-central-2012, and plan are built on outdated patterns (PHP4 constructors, TwentyTen base, IE compatibility code)
- **Hardcoded values**: Blog IDs, URLs, and timezone values are scattered throughout
- **Global state reliance**: Several files rely on global variables (`$trusted_deputies`, `$wcorg_subroles`, `$camptix`)
- **No CI/CD visible**: No GitHub Actions workflows or CI configuration found
- **Incomplete test coverage**: Only CampTix has meaningful tests; themes and most plugins lack tests

---

## Recommendations

### Immediate (Security)
1. **Add nonce verification** to CampTix checkout, refund, attendee edit, and private content forms
2. **Replace weak token generation** with `random_bytes()` or `wp_generate_password()`
3. **Sanitize `$_SERVER` superglobals** before use in headers and redirects
4. **Remove the SQL dump** from `wporg-events-2023/env/`
5. **Add nonce verification** to the wordcamp-central-2012 subscribe form

### Short-term (Dependencies)
6. **Upgrade PHP platform target** from 8.1 to 8.2+ (8.1 is EOL)
7. **Upgrade PHPUnit** to 10+ for PHP 8.4 compatibility
8. **Pin third-party plugin versions** instead of using `latest-stable.zip`
9. **Update devDependencies** (`@wordpress/eslint-plugin`, `@wordpress/jest-preset-default`)

### Medium-term (Code Quality)
10. **Replace `extract()` calls** with explicit variable assignments
11. **Replace deprecated functions** (`wp_title()`, `wp_get_sites()`)
12. **Fix translation errors** in email footer and sandbox notice views
13. **Add `$wpdb->prepare()`** to direct SQL queries
14. **Remove dead code** (commented-out functions, outdated TODOs)

### Long-term (Architecture)
15. **Migrate legacy themes** to modern patterns or deprecate them
16. **Implement proper secrets management** instead of wp-config.php constants
17. **Add test coverage** for mu-plugins and custom themes
18. **Implement CI/CD pipeline** with automated PHPCS, PHPUnit, and security scanning

---

## Files Examined

- **mu-plugins:** 24 root files, 5 subdirectories (camptix-tweaks, utilities, events, wordcamp, wp-cli-commands)
- **Plugins:** 35 custom plugins examined in detail:
  - camptix (29 PHP files, ~273KB main file)
  - wordcamp-payments, wordcamp-payments-network
  - wordcamp-reports, wordcamp-api, wordcamp-dashboard-widgets
  - wordcamp-docs, wordcamp-forms-to-drafts, wordcamp-mentors
  - wordcamp-organizer-reminders, wordcamp-participation-notifier
  - wordcamp-qbo, wordcamp-qbo-client, wordcamp-remote-css
  - wordcamp-site-cloner, wordcamp-speaker-feedback, wordcamp-wiki
  - wordcamp-coming-soon-page, wc-fonts, wc-post-types, wcpt
  - camptix-admin-flags, camptix-attendance, camptix-badge-generator
  - camptix-invoices, camptix-mailchimp, camptix-network-tools
  - campt-indian-payment-gateway, multi-event-sponsors, tagregator
  - wporg-profiles-wp-activity-notifier, jquery-ui-css, custom-content-width
  - email-post-changes, email-post-changes-specific-post, wp-cldr
- **Themes:** 10 custom themes (wordcamp-base, wordcamp-base-v2, wordcamp-central-2012, campsite-2017, campus-connect, student-clubs, plan, wporg-events-2023, wporg-flagship-landing, wporg-parent-2021)
- **Config:** composer.json, docker-compose.yaml, phpcs.xml.dist, phpmd.xml.dist, phpunit.xml.dist, package.json, .eslintrc.js, .prettierrc.js, .stylelintrc, wp-config.php

---

*Report generated by automated code audit. Findings should be verified manually before acting on them.*
