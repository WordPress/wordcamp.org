=== Shashura Mobile Money for CampTix ===
Contributors: cipherwave, ssebuwufumoses, mosescursor
Tags: camptix, mobile money, mtn, airtel, uganda
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept MTN and Airtel Mobile Money for CampTix ticket sales in Uganda through the Shashura gateway. Not affiliated with or endorsed by CampTix.

== Description ==

Shashura Mobile Money for CampTix adds MTN and Airtel Mobile Money as a payment option for CampTix, the WordPress event ticketing plugin used by WordCamp and community events. It is built for organisers selling tickets in Uganda who need a payment method their attendees already use day to day. This plugin is developed and maintained by Techdom Digital Solutions and is not affiliated with, or endorsed by, the CampTix project.

A Shashura merchant account is required to use this plugin. Sign up or log in at [https://shashura.com](https://shashura.com).

The plugin connects CampTix to the Shashura Mobile Money API and handles the full collection lifecycle: number verification, the PIN push to the buyer's phone, settlement confirmation, and reconciliation. It runs against either Shashura's Sandbox or Production environment, and endpoint overrides are controlled by trusted deployment code, never per-site settings. See [ENDPOINT-POLICY.md](https://github.com/Techdom-Digital-Solutions-U-limited/shashura-mobile-money-for-CampTix/blob/main/ENDPOINT-POLICY.md) for migration and development testing.

= How a payment works =

1. The buyer selects Mobile Money at checkout and enters their MTN or Airtel number.
2. The number is verified against the network before anything is charged. The registered name is hidden by default; site administrators may choose partial or full display.
3. A PIN prompt is pushed to the buyer's phone for the exact order total.
4. The plugin confirms the payment with Shashura directly rather than trusting the browser or an incoming webhook on their own. Both only trigger a check; only a successful status enquiry for the stored payment attempt, with the expected amount and (where already known) matching Shashura transaction ID, can mark it paid. The gateway may replace external_ref after settlement, so it is not used as a post-settlement equality check.
5. Once confirmed, the buyer is taken to their CampTix ticket page and receives CampTix's standard confirmation email.

= Reliability and safety =

* Every payment prompt on an order is tracked and reconciled, including ones approved after a buyer tried more than once.
* A duplicate payment on the same order is detected and flagged to the site administrator for a manual refund, rather than accepted or dropped silently.
* Incoming webhooks are verified against an HMAC-SHA256 signature. Unsigned, forged, oversized or mismatched-environment events are rejected.
* A per-order lock stops the webhook, the buyer's browser, an order cancellation and CampTix's own timeout sweep from recording conflicting outcomes for the same order at the same time.
* Requires CampTix with non-interactive payment results (the four-argument API in current CampTix 2.x). CampTix 1.x is unsupported: it can terminate webhook requests before a response is sent. On an incompatible version, this payment method is not registered and administrators see an upgrade notice. Upgrade CampTix before accepting new Mobile Money payments.

= Requirements =

* CampTix, with the ticket currency set to UGX.
* A Shashura merchant account with an app configured for Mobile Money collections, and its Client ID, Client Secret and Webhook Secret.
* A publicly reachable site, with the server's outbound IP whitelisted on the Shashura app, so callbacks and settlement can reach the plugin.

== Installation ==

1. Install and activate CampTix. Under Tickets → Setup → General, set the ticket currency to UGX.
2. Under Plugins → Add New, search for "Shashura Mobile Money for CampTix", then install and activate it. You can also upload the plugin zip under Plugins → Add New → Upload Plugin.
3. Go to Tickets → Setup → Payment and open the Mobile Money (MTN / Airtel) section:
   * Set Enabled to Yes.
   * Enter the Client ID and Client Secret from your Shashura app (see "Getting Your Shashura API Credentials" below).
   * Choose Sandbox while testing. Switch to Production only when ready to take real payments.
   * Enter the Webhook Secret shown on your Shashura app. Mobile Money is not offered at checkout until this is set.
   * API endpoints are controlled by trusted deployment code; see [ENDPOINT-POLICY.md](https://github.com/Techdom-Digital-Solutions-U-limited/shashura-mobile-money-for-CampTix/blob/main/ENDPOINT-POLICY.md).
4. Copy the webhook URL shown under Webhook Secret and set it as the Callback URL on the matching app in your Shashura dashboard.
5. Ask Shashura to whitelist your server's outbound IP address for that app. Requests from an IP that is not whitelisted are rejected.
6. Save your settings. The Connection line confirms whether the credentials authenticate successfully.

== Getting Your Shashura API Credentials ==

This plugin needs three values from your Shashura merchant account: a Client ID, a Client Secret, and a Webhook Secret. All three are issued from the Apps page of your Shashura merchant dashboard.

1. Log in to your Shashura merchant dashboard at [https://shashura.com](https://shashura.com) and open the Apps page. If you don't have an account yet, sign up there first.
2. Click Create App and give it a name, such as your event site's name. Shashura generates a Sandbox credential pair and a Production credential pair for the app at the same time, along with a Webhook Signing Secret, so there is no separate step to request production access later.
3. The Apps page lists each Client ID against its environment:
   * Client ID: SANDBOX begins with `TID_`. Use this, with its matching Client Secret, while Environment is set to Sandbox in this plugin.
   * Client ID: PRODUCTION begins with `PID_`. Use this, with its matching Client Secret, once Environment is switched to Production.
   * Client Secret is hidden by default. Use the reveal icon to display it on screen, or the regenerate icon to issue a new one. Regenerating immediately invalidates the previous secret, so update this plugin's settings straight after.
4. Copy the Client ID and Client Secret for the environment you are configuring into this plugin's Payment settings.
5. Reveal the Webhook Signing Secret (begins with `whsec_`) on the same Apps page and copy it into this plugin's Webhook Secret field.
6. Copy the webhook URL this plugin displays under its Webhook Secret field and paste it into the Callback URL field for the same app on the Apps page.
7. Ask Shashura to whitelist your server's outbound IP address for this app, so callbacks and settlement checks are accepted.

Because each app holds both a Sandbox and a Production credential pair, going live does not require creating a new app; only switching this plugin's Environment setting to Production and swapping in the Production Client ID and Secret from the same Apps page.

== Frequently Asked Questions ==

= Which currencies are supported? =

Uganda Shillings (UGX) only, matching Shashura's Mobile Money coverage.

= Does this plugin process refunds? =

Yes. Use CampTix's own "Refund" button on the attendee screen — full, partial, and refund-all are all supported. Only the amount the organizer actually received is reversed; Shashura's processing fee is not refunded. The attendee gets the refunded amount back on the same phone number that paid.

= What happens if a buyer closes the browser tab after approving the PIN prompt? =

The payment still settles. Shashura's webhook, and CampTix's own timeout check on CampTix 2.x, both ask Shashura directly whether the order was paid, independently of whether the buyer's browser is still open.

= Can I switch between Sandbox and Production while orders are still open? =

Avoid it. A prompt sent under one environment cannot be checked while the plugin is configured for the other. It stays in a waiting state, never shown as failed, until the environment is switched back.

= Where do I see what happened on a specific order? =

Open the attendee's edit screen under Tickets → Attendees. The CampTix log on that screen records every check, error, amount mismatch or duplicate-payment warning for that order.

= Can this connect to a Shashura deployment other than the public default? =

Yes. A trusted deployment operator can set SHASMOMO_BASE_URL or use shasmomo_base_url. Per-site URL settings are ignored. See [ENDPOINT-POLICY.md](https://github.com/Techdom-Digital-Solutions-U-limited/shashura-mobile-money-for-CampTix/blob/main/ENDPOINT-POLICY.md).

== External services ==

This plugin connects to the Shashura Mobile Money API to collect ticket payments. This is required functionality: without it, the plugin cannot verify mobile numbers, push PIN prompts, or confirm payment, since Mobile Money collection can only happen through a licensed Mobile Money aggregator.

What is sent, and when:

* Number verification: when a buyer enters a mobile number at checkout, that number is sent to Shashura to look up the registered account name on the MTN/Airtel network, shown to the buyer for confirmation before anything is charged.
* Payment request: once the buyer confirms, the mobile number, the order total, and an order reference are sent to Shashura to push a PIN prompt to the buyer's phone.
* Status confirmation: after a prompt is sent, this plugin asks Shashura directly whether the referenced payment succeeded (and Shashura also notifies this plugin via a signed webhook). No card numbers, mobile money PINs, or other site data are sent.
* These calls happen only when a buyer actually attempts a Mobile Money payment at checkout, never on a page load with no checkout activity.

This service is provided by Shashura (operated by Techdom Digital Solutions): [Terms of Service](https://shashura.com/terms), [Privacy Policy](https://shashura.com/privacy).

A Shashura merchant account, with an app's Client ID and Client Secret, is required to use this plugin. See "Getting Your Shashura API Credentials" above.

== Screenshots ==

1. Payment configuration under Tickets → Setup → Payment: credentials, environment selection, and live connection status.
2. The buyer-facing checkout flow, from entering a mobile number through to the CampTix ticket page.
3. An optional "Powered by Shashura" footer, shown here on the PIN-approval step. Off by default; the site administrator can turn it on from the payment settings.

== Changelog ==

= 1.0.5 =
* A ticket reservation is now kept while its payment prompt is still unresolved, instead of being released by CampTix's own timeout sweep. A collection that confirms late can still complete the order instead of landing on a seat already given up.
* The plugin now checks that CampTix supports non-interactive payment results (the four-argument API in current CampTix 2.x) before registering as a payment method. On an incompatible version it shows an admin notice instead of registering, rather than risking a webhook request being terminated mid-response.

= 1.0.4 =
* Hardened the API endpoint: removed the per-site "API Base URL" setting entirely (including silently clearing any previously saved value). The endpoint is now fixed to Shashura's public API unless explicitly overridden by trusted deployment code (a `SHASMOMO_BASE_URL` constant in `wp-config.php`), never by a site's saved settings — closing a path where a compromised or tampered settings save could have redirected API credentials to another server. All requests now go through WordPress's safe HTTP transport, which blocks requests to private/internal network addresses.
* The buyer now sees a specific reason when a payment is declined (not enough balance, wrong PIN, cancelled, timed out, etc.) instead of a generic "declined or expired" message.
* A payment collected for the wrong amount is no longer treated as a failed payment. It's held for reconciliation instead: the buyer is told not to pay again, the order is never auto-cancelled, and the site administrator is emailed once with the details needed to resolve it.
* Refunds are now confirmed, not assumed. CampTix is only told a refund succeeded once Shashura confirms the customer's phone actually received the money — a reversed ledger entry with an unconfirmed or failed payout now holds the order for manual reconciliation instead of silently reporting success. Concurrent or repeated refund clicks on the same order are blocked while one is in progress.
* A refund payout that resolves after the fact (the common case for MTN, which confirms asynchronously) now automatically clears that reconciliation hold once Shashura confirms delivery, instead of requiring a manual check.

= 1.0.3 =
* The buyer-facing payment pages now show the actual MTN MoMo and Airtel Money logos instead of a coloured badge with the network name. A site without permission to use the official logos can restore the plain badge, or supply its own image, through the `shasmomo_network_logo` filter.
* Updated plugin credits: now developed and maintained by Techdom Digital Solutions. Added Cipherwave Software Limited and Moses Ssebuwufu as contributors.

= 1.0.2 =
* Added refund support: CampTix's "Refund" button (single, partial, and refund-all) now works through the Shashura API. The attendee's money is sent back to the phone that paid; Shashura's processing fee is not refunded.
* The "Powered by Shashura" credit link on buyer-facing payment pages is now off by default and only shown if the site administrator explicitly turns it on in the payment settings.
* Renamed internal functions, classes, defines and filters from the generic "ctx_shashura"/"CTX_Shashura" prefix to a more distinctive "shasmomo"/"Shasmomo" prefix, to avoid clashing with other plugins.
* Fixed the plugin header's License field to a format reliably recognised by the Plugin Check tool.

= 1.0.1 =
* Renamed from "CampTix Shashura Mobile Money" to "Shashura Mobile Money for CampTix" to avoid implying official affiliation with the CampTix project.
* Documented the plugin's use of the Shashura Mobile Money API under "External services".

= 1.0.0 =
* Initial release. MTN and Airtel Mobile Money collection for CampTix via the Shashura API, with number verification, webhook and status-enquiry reconciliation, duplicate-payment detection, and support for both CampTix 2.x and 1.4.x.

== Upgrade Notice ==

= 1.0.5 =
Keeps ticket inventory reserved while a payment prompt is pending, and requires CampTix with non-interactive payment results (upgrade CampTix if you see the new admin notice). Recommended for all sites.

= 1.0.4 =
Security hardening: the API endpoint can no longer be redirected via site settings (removes the "API Base URL" field). Also adds buyer-facing decline reasons, safer handling of wrong-amount payments and refunds. Recommended for all sites.

= 1.0.3 =
Payment pages now show the real MTN/Airtel logos by default (filter-overridable). Plugin credits also updated: Techdom Digital Solutions, Cipherwave Software Limited.

= 1.0.2 =
"Powered by Shashura" is now opt-in (off by default); internal naming made more distinctive. No action needed unless you want the credit link, which you can now turn on in the payment settings.

= 1.0.1 =
Plugin renamed to Shashura Mobile Money for CampTix; no functional changes.

= 1.0.0 =
Initial release.

== Additional Information ==

= About Shashura =

Shashura is a Mobile Money payment gateway for Uganda, settling payments through MTN and Airtel Mobile Money. A Shashura merchant account is required to use this plugin. Sign up or log in at [https://shashura.com](https://shashura.com).

= Privacy =

A checkout visitor can check a Ugandan Mobile Money number that does not belong to them. The verification service may return the registered subscriber's name. By default this plugin hides that name from the visitor; site administrators can opt to show the partial or full name under Tickets → Setup → Payment. This can disclose personal information to a third party. Explain the chosen setting in the site's privacy policy. Rate limiting is not proof of number ownership. The name may still be received and temporarily stored by the plugin when display is off.

Against each paid order, this plugin stores the payer's registered name, a masked mobile number, the mobile network, and the Shashura transaction reference, for reconciliation and support. Full, unmasked phone numbers and names are included in WordPress's own Tools → Export Personal Data and Erase Personal Data tools, so data-subject requests are handled through core WordPress, not a separate process. This plugin never handles or stores card numbers, mobile money PINs, or Shashura account credentials.

CampTix writes each payment result to its own order log, which WordPress's Erase Personal Data tool does not reach. The payer's name and masked number are therefore not included in the payment data this plugin passes to CampTix. Log entries written by earlier versions may still contain them; if a person asks for erasure, those entries have to be cleaned up in the database. The name and number kept on the payment attempt itself are still covered by the export and erase tools.

= Support =

For help with a Shashura account, API credentials, or a specific payment, contact Shashura support through the merchant dashboard at [https://shashura.com](https://shashura.com). For issues with this plugin itself, use this plugin's support forum on WordPress.org.

= Testing =

The public repository contains standalone regression tests with in-memory WordPress/CampTix doubles and mocked gateway responses. Run `php bin/test.php` or `composer test`; GitHub Actions runs the suite on PHP 7.4, 8.2 and 8.4. Coverage and remaining integration gaps are documented in [tests/README.md](https://github.com/Techdom-Digital-Solutions-U-limited/shashura-mobile-money-for-CampTix/blob/main/tests/README.md). The historical 92-check end-to-end suite and independent security-review report are not published here; the public regressions do not substantiate those claims.

= Credits =

* **Developed and maintained by:** Techdom Digital Solutions ([https://techdom.io](https://techdom.io))
* **Contributors:** Cipherwave Software Limited ([https://cipherwavesolutions.com](https://cipherwavesolutions.com)), Moses Ssebuwufu ([ssebuwufumoses](https://profiles.wordpress.org/ssebuwufumoses/) on WordPress.org, [@ssebuwufumoses](https://github.com/ssebuwufumoses) on GitHub), Moses Cursor Ssebunya ([mosescursor](https://profiles.wordpress.org/mosescursor/) on WordPress.org, [@Successfulsebunya](https://github.com/Successfulsebunya) on GitHub)
* **Trademarks:** The MTN MoMo and Airtel Money logos in `assets/images` are trademarks of MTN and Airtel.

= Developer Filters =

The following filters are available to adjust plugin behaviour from a theme or another plugin:

* `shasmomo_lookups_per_ip` (default 20): number verification checks allowed per visitor IP per hour.
* `shasmomo_lookups_per_number` (default 3): number verification checks allowed per phone number per hour, across all orders.
* `shasmomo_lookups_alert` (default 300): site-wide number verification checks per hour that trigger an admin email.
* `shasmomo_client_ip`: overrides the IP address used for rate limiting.
* `shasmomo_accent_color` (default #1d4ed8): accent colour used on the payment pages.
* `shasmomo_merchant_logo` (default: Site Icon, then Custom Logo): image shown in the payment page header.
* `shasmomo_network_logo` (default: bundled MTN MoMo or Airtel Money logo): image URL for a network logo (`mtn` or `airtel`). Return an empty string to show the coloured badge with the network name instead.
* `shasmomo_template` (default: the plugin's `templates/` folder): path of a payment page template. Themes can also override one by copying it to `your-theme/shashura-mobile-money-for-camptix/`.
* `shasmomo_http_timeout` (default 25): timeout, in seconds, for calls to the Shashura API.
* `shasmomo_base_url` (default https://shashura.com/api/v1): trusted deployment override requiring HTTPS and WordPress safe HTTP validation. Per-site endpoint settings are ignored.
