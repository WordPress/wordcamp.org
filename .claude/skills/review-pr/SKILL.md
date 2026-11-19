---
name: review-pr
description: Review a pull request in this repo the way we review here, including security fixes under private disclosure. The method, not the commands - what to distrust in a PR body, how to reproduce and mutate, how to drive the real UI with claude-in-chrome and read the raw response behind it, how to prove a finding before reporting it, how to merge a batch, and how to split public PR text from a private security issue. Use when reviewing a PR, a branch, or a batch of branches, and when reviewing or writing up a security fix.
---

# How we review pull requests here

Commands are in `AGENTS.md`, domain traps in
`.claude/skills/groups-gatherpress-compat-test/`. This is the method.

**Keep this skill current.** When a review hits a wall the next reviewer would
hit too (an hour-long trap, a bug class this repo keeps producing, a fix that
looked right and wasn't), add it before the session ends: fold it into its
section, in as few words as carry it. Current practice, not a changelog.

A review is an independent attempt to make the change fail.

## Claims

- The PR body is a hypothesis, not evidence. "Covered by tests" means go find the test.
- So is a claim about an external API: #2300 inferred Meetup series from dates because "`series` comes back empty", but the query never asked for it. Check the query, then the live API: `https://api.meetup.com/gql-ext` answers public reads and introspection (one `__type` per query) unauthenticated. `totalCount` isn't the paged list: unfiltered `memberships` counts ACTIVE + LEADER (the group page's number) but lists `INCOMPLETE` too, 5 extra on 5920 (#2375). Compare the list's length.
- Re-run every suite it quotes; counts go stale. "Fails the same on `production`" can be the author's stack: #2336's mounted only `groups/` and `wporg-groups-frontend/` over a checkout 40 files behind, and its 28 "pre-existing" failures vanished on a full worktree.
- An old PR may be superseded: `git log origin/production` its files. Rebasing can quietly revert the newer fix.
- Green CI says the suite passed, not that it covers this or even finished. CampTix redirects and any `wp_safe_redirect(); exit;` reached through `go_to()` `die()`: PHPUnit exits 0, skipping later tests. Compare test counts, and turn `wp_redirect` into `WPDieException` (`test-require-login.php`) in every test driving the route, or a regression redirecting where it should 404 stays green (#2225). Likewise `wp_send_json()`: filter `wp_doing_ajax` and `wp_die_ajax_handler` (`as_ajax_request()` in `test-meetup-import.php`) in the capability test too (#2203). Green E2E may run a stale Gutenberg: CI installs `gutenberg.latest-stable.zip` as Composer version `999`, so a cache hit reuses the old zip; look for "Downloading wordpress-plugin/gutenberg" in the log (#2364).

## Reproducing

- Reproduce the symptom first, by the path a person takes. The RSVP block calls `/members/join` before RSVPing, so "Join & RSVP" never reaches `save_rsvp()` as a non-member: drive "joined by RSVPing" from the button (#2337).
- A correct, tested, green fix can leave the bug in place. Assert on the surface that broke, at the layer the user sees. Right data is not a right page.
- For a loop over camps (CLI, cron, sweep), read which camps it selects first. `post_status => any` on `wordcamp` is every camp since 2006; all three local camps with sites are past, so a dry run shows whether history gets written. Groups are their own network (`events.wordpress.org/group/`, network 4 locally): a `$wpdb->blogs` sweep on `get_current_network_id()` with `--url=events.wordpress.org` finds no group site (#2231).
- Setting CampTix attendee status outside `payment_result()` skips the exact log line `Attendee status has been changed to <status>` (central Ticket Revenue counts only from it) and every `camptix_payment_result` listener (refund stats, page-cache clear, invoices). Grep for both before accepting a direct `wp_update_post()` on a `tix_attendee`.
- The CampTix timeout sweep asks a gateway only once a draft is due (recorded expiry plus grace, else checkout plus 24h): check the session still answers then. PayPal's 3h token is dead by then (#2310); with no recorded expiry the 24h cap gives up on the first pass, so "keep for the next sweep" never keeps (Instamojo, Razorpay, SSLCommerz).
- Block-theme camps wrap the post in `mu-plugins/theme-templates/block-templates/`, so blocks there (session speakers, speaker sessions) sit outside the password form. The local stack may have only classic camps: switch one to `twentytwentyfour`, and undo it (Afterwards).

## In the browser

- A green suite proves functions, not pages. Drive the real UI with `claude-in-chrome` on the local stack, as every actor the change touches, logging out between them. Anonymous is an actor, and where gated-content findings live.
- Read the raw response too; an attacker sends raw requests. Cookie flags, headers and delivered markup come off the response, never the source. Malformed input goes in the request: a field the page won't let you type is still sendable.
- "A save with no author field" isn't the classic form (it always posts a hidden `post_author`): remove `#post_author` first, or use REST with `author` set to yourself (no `edit_others_posts` check).
- Block assets ship a fixed `?ver=1.0.0`: hard-reload (`cmd+shift+r`) after a rebuild.
- A new `wordcamp/*` block appears in the editor only if its controller adds a key to `WordCampBlocks`, built on `init` before the admin screen exists (a screen check drops it). Insert it first.
- A raw REST 403/400 isn't yet the permission check: no nonce gives `rest_cookie_invalid_nonce` (get one from `admin-ajax.php?action=rest-nonce`), and arg validation runs before `permission_callback`. Send a full valid payload and look for `rest_forbidden`. For `admin-post.php`, a WP-CLI nonce has no session token: mint it with the cookie in place (`$_COOKIE[LOGGED_IN_COOKIE] = '<minted cookie>'; wp_set_current_user( <id> ); echo wp_create_nonce( '<action>' );`) and curl with that cookie. A capability 403 carries the handler's message, not "The link you followed has expired".
- `resize_window` can silently not apply: measure widths in a same-origin iframe instead.
- Click by `find` ref, not coordinates, and re-`find` before each `form_input` (React re-mounts stale refs). A click below the fold may only scroll: screenshot, click again. Login submit can no-op: press `Return` in the password field and check the toolbar.
- Never click a `window.confirm()` button (the event modal's Cancel is one once a field changed): submit the form directly, and close a frozen tab with `tabs_close_mcp`. A block-editor tab with a loaded post, or an unsaved Customizer tab, blocks `navigate` and hangs `tabs_close_mcp` on its leave prompt: open new pages in a fresh tab. A page's own `beforeunload` guard (a running import) refuses `navigate` and cancels a script `location.href`; override `Event.prototype.preventDefault` to skip `beforeunload` and make `BeforeUnloadEvent.prototype.returnValue` a no-op setter.
- XSS payloads must not `alert()` (it freezes the extension): use `onerror="document.body.dataset.xss=1;console.log('XSS-FIRED')"` and read `document.body.dataset.xss` and `read_console_messages` (check timestamps; the log survives reloads). Focus-triggered payloads don't fire in an automated tab (no window focus; script `.focus()` sends no event): click the element for real before calling it inert.
- Customizer: opening `customize.php` on a camp whose theme isn't installed (Seattle, shinynew) really switches it to `twentytwentyfour` (`validate_current_theme()`). On `:8443` set the Coming Soon preview (`?wccsp-preview`) with `wp.customize.previewer.previewUrl.set()` and read the iframe. An unsaved tab leaves an `auto-draft` `customize_changeset`, so check saved state with WP-CLI and curl. `invalid_nonce` or "not allowed to customize" on save means page and admin-ajax saw different sessions: mint `logged_in` and `secure_auth` from one `WP_Session_Tokens::create()` token, and if the toolbar names another user, an earlier httponly cookie wins: click its admin-bar Log Out. To save headless, set `$GLOBALS['wp_customize']` to a new `WP_Customize_Manager`, fire `customize_register`, `set_post_value()`, then `save_changeset_post( array( 'status' => 'publish' ) )`; without the global, publishing builds a second, empty manager and saves nothing.
- Check "fits the badge" in the preview iframe per badge and variant (with/without QR; the default is what most camps print), with real-length names: each child's `getBoundingClientRect().bottom` against the badge's, name lines via `Range.getClientRects()` (#2278). Badge CSS goes through `esc_html()`, silently dropping `>` combinators and quotes: confirm rules with `getComputedStyle`.
- Judge generated block markup with `wp.blocks.parse()` (`isValid` on every inner block), not by eye. It's lenient (a raw nested `<ul>` in a list item passes). Run the PR's new test against pre-fix code: a text-only assertion ("Sub 1") passes on broken markup.
- Decode QR codes with Chrome's `BarcodeDetector` (SVG drawn to a canvas); asserting `data-url` only proves the input.
- Check "fits one page" with headless Chrome `--print-to-pdf` (kill it if it hangs after writing). Rasterize `blob:` PDFs with `gs -sDEVICE=png16m` and crop with `sips`; `gs -sDEVICE=txtwrite` prints glyphs in drawn order, settling RTL/shaped word order.
- "A server error occurred … Your submission failed" is the PWA service worker covering a failed POST; it also answers a wp-admin `fetch()` replay with 200 when the server refused, so replay with curl. `wp_die()` exits 500 and writes nothing to `debug.log`: find the cause with a temporary mu-plugin logging via `wp_die_handler` and a shutdown function.
- A protected wcpt field rendered `disabled` posts nothing (the region dropdown posts its stored value), so a `$_POST` check on one (the scheduled-status check) sees it empty for non-wranglers. Local `contributorrole` (6) passes for the wrong reason: `.docker/wp-config.php` gives it every wrangler subrole via `$wcorg_subroles`. Mentor fixture: `authorrole` (5) added to Central as a contributor, removed after. Before #2167 mentors can't save on Central at all (#2159). Dropping `post_author` from a replay passes core's check but saves the mentor as author: not what a browser sends.
- Screenshot anything you intend to assert. A finding with a picture survives the round trip.
- If claude-in-chrome is down: Playwright drives the installed Chrome (`executablePath`) with a minted cookie via `addCookies`; compare `Range.getClientRects()` line breaks against the fix overridden by an injected `<style>`. Or run a tracker's `admin.js` in jsdom on the real edit page: strip `<script>`s, eval WP's jQuery, stub `$.fn.datepicker`/`autocomplete` and `window.wordCampPostType = { Mentors: {} }`, wait for jQuery `ready`, confirm the handler is bound (`$._data( el, 'events' )`) and count jsdom errors, or a silent abort in `initialize()` reads as "no change".
- `word-break: auto-phrase` never breaks inside a phrase; without `overflow-wrap` a long one overflows. Try particle-less titles (`八王子ワードプレス勉強会`) and site names at card width (#2260).
- `position: sticky` sticks to the nearest `overflow: auto` box; lift its height cap and the child scrolls away (#2261's editor toolbar). Find the first ancestor with `scrollHeight > clientHeight`: in the group settings modal `components-modal__children-container` isn't a flex column, so `__single` and the tab panel never scroll.
- The event form has three entry points: the event-manage modal (`[data-wporg-groups-modal]`, prefix `wporg-groups-event-modal`), and Settings → Events and "Edit this event" (`group-settings`, prefix `wporg-event-form`). Click the real buttons; an injected trigger reaches only the modal. The modal resumes a draft in create mode, Settings → Events in edit mode, so create-only controls vanish there (#2275). The `group-settings` save also POSTs speakers to `/wp/v2/gatherpress_events/<id>`, a second `wp_update_post()`: GatherPress save-time rebuilds run twice (#2237).

## Tests

- Break the code. Watch the test fail. Every time. A test that cannot fail is not a test.
- A test can pass for the wrong reason. Mutate the exact line it pins and confirm the mutation landed in `git diff` (a `sed` missing a quote changes nothing; a deletion shows only as `-`/`<`). Undo from a pre-mutation copy, not `git checkout`, which also wipes your uncommitted fix. Groups blocks register from `build/` (`inc/blocks.php`): mutate the built `render.php`, not `src/`.
- Timestamp guards pass vacuously in fast tests: import and fix land in the same second, or a fixture user registered an hour ahead clears any cutoff (#2336, #2338). Move the stored time back, or register the user between stamps.
- Beware tests that do the work themselves. Drive the real trigger, read the result back.
- A test-only injection filter (`…_groups`, `…_count` returning non-null) skips the real source: run the real path once. #2175 was green while its count fatalled: `get_wordcamps()` sets `->meta` on every result, so `'fields' => 'ids'` dies on an int. The real Meetup path on events.wordpress.org (network 2) has no token (only Central's, shared to network 4 by `groups/meetup-shared-connection.php`): `Meetup_Client` sends `Bearer ` silently and `gql-ext` still lists groups with `groupAnalytics: null`, so counts read 0. Run it tokenless and check what's stored.
- A test registering the callback it asserts removed proves only that the `remove_action` matches itself: read the vendor's `add_action` (hook, callable, priority).
- Calling a callback directly can't catch a wrong hook name: unhook it and rerun; if still green, prove the hook fires with a real request. Or a wrong priority: a `pre_get_posts` hook that must beat GatherPress's (10) passes `$query->get()` assertions at 11 while the archive lists every event. Assert on the posts returned (#2325).
- `delete_with_user => false` covers only `wp_delete_user()`; `wpmu_delete_user()` deletes the user's posts on every site they belong to. Test "records outlive their author" via the network path, author a member of the storing site (#2327). In the Groups Network suite an earlier class leaves network 1's `site_admins` as `''`, so `get_super_admins()` callers (`wpmu_delete_user()`) TypeError only in the full run: pin `$GLOBALS['super_admins']`.
- `WordCamp_Admin` hooks never run for Campus Connect applications (the Jetpack form handler `require_once`s `wcpt-loader.php` after `plugins_loaded`, so `core_admin()` builds nothing): drive `create_campus_connect_tracker()` and read the meta on Central (#2249).
- wp-admin-only functions exist under PHPUnit, so a front-end "not a valid callback" fatal (`multisite_over_quota_message` on `upload_ui_over_quota`) can't reproduce there: pin with `has_action()`, prove with a real request. A fatal inside `wp_print_media_templates()` hides in a `<script type="text/html">`: check the response ends in `</html>` (#2362).
- Exercise the edges the PR claims in prose; those are wrong most often.
- A field added to `get_required_fields( 'scheduled' )` is checked on every save of a scheduled event: one without it drops to the fallback status on its next edit (#2246). Ask for a backfill or a transition-only check.
- A new branch with no test is a finding, even when it works.

## Fixtures

- Suspect fixtures before code: an earlier direct write can fake a bug. Make fixtures tell the cases apart, and seed the data the edge needs (absent data passes every assertion).
- Seed through the real API and read it back: GatherPress drops what the site forbids (group sites force anonymous RSVPs off). Another event writer (importer, CLI) claiming parity with the form: diff its calls against `persist_event()` in `rest.php` (#2203 skipped `set_event_language()`, which the edit form then leaves empty). An Event Organizer (`author`) edits only their own events, and the Meetup importer sets `post_author` once, from the first matched host, on first write (`event_author()`); `backfill_people()` never changes it. Deferring host matching leaves imported events on the lead organizer (#2363): drive both imports, check `current_user_can( 'edit_post' )` as the host.
- Name fixtures so core's 404 slug guessing can't match them (a `flyer-review-…` post 301'd every unrouted `…/flyer/`).
- Publishing a seeded event emails every member "New event": seed as a draft if the mail log matters.
- Theme patterns cache by theme `Version` for 30 minutes: after a branch switch run `wp_get_theme()->delete_pattern_cache()`. In production a new pattern without a `Version` bump ships late.
- No local camp has sessions. For Speaker Feedback, seed a published `wcb_session` with `_wcpt_session_type=session` and a past `_wcpt_session_time`; the form is at `…/session/<slug>/feedback/`, answers go to moderation.
- Central sideloads (logos, featured images) fail locally with cURL error 60, so a missing thumbnail proves nothing: re-run the WP-CLI command with `--require` a file calling `WP_CLI::add_wp_hook( 'https_ssl_verify', '__return_false' )` (`add_filter` isn't loaded yet).
- Seattle and Shiny New 2023 use `twentytwenty`, not installed locally (blank body), with Coming Soon on (anonymous sees the placeholder). Set `template`/`stylesheet` to `campsite-2017` and put it back (Afterwards).
- The local Groups root (52) has ~40 options and no `wc_52_user_roles`: empty charset (mojibake em dashes), and trusted deputies fatal on Groups Network Admin (`get_role( 'administrator' )` null in `trusted-deputy-capabilities.php`). Lean on tests for deputy access there, and say so (#2327).
- `wp user create --role=` makes a member of the `--url` site (empty caps); a real wordpress.org account belongs nowhere. Use `wpmu_create_user()`, or delete `wc_<id>_capabilities`/`user_level`, before judging Network Admin access or the admin bar (My Sites needs a membership).
- `wp-config.php` globals (`$trusted_deputies`, `$wcorg_subroles`) aren't global under WP-CLI (`user_can( 6, 'wcb_approve_budget' )` says no): judge deputy access in the browser.
- No theme is network-enabled on local Groups: set `allowedthemes` on a throwaway group site to test a theme switch (#2379).
- Set a group's language by writing `WPLANG` with `$wpdb->update()` (`update_option()` drops an uninstalled locale). Logged-in visitors get their profile locale (`groups/front-end-locale.php`), so `<html lang>` and `:lang()` follow the viewer. For real `wordcamporg` strings, put translate.wordpress.org's `meta/wordcamp/<lang>` `.mo` in `wp-content/languages/wordcamporg/`. To find untranslatable strings, a cookie-gated `gettext` filter wrapping every translation shows what's left in English; skip GatherPress's `_x( 'Event', 'Post Type Singular Name' )` (or every event URL 301s), and don't skip "date formats" by character class (English sentences match) (#2377).
- MES treats a copy as organizer-edited when `post_modified !== post_date`, but core resets a draft's `post_date` on every save, so edits to a draft copy (or in the save publishing it) get overwritten by Push to Active Camps. Seed that case before trusting "skips edited copies".
- A seeded `wordcamp` post needs a `URL` passing `WordCamp_New_Site::url_matches_expected_format()` (`https://campus.wordpress.test/<slug>/`), or tracker saves `wp_die()` behind the PWA page. New IDs exceed `wcpt_require_complete_meta_min_site_id`, so required-field rules apply.
- Silence isn't a pass where a path can't run locally. `WCOR_Mailer::mail()` fatals under WP-CLI (needs the admin-only `$wordcamp_admin`): trigger WCOR from wp-admin. `wordcamp-qbo` is inactive (no sponsor-invoice approve). DomPDF PDFs (Docs, invoices, visa letters) do work (`wkhtmltopdf` can't start on arm64): replay the Docs steps with `fetch()` in wp-admin and read the `%PDF-` header, `/Producer` and page count.
- Since #2247 the PHPUnit bootstrap requires `mu-plugins-private/wporg-mu-plugins/pub-sync/utilities/class-freescout.php`; an older local `wporg-mu-plugins` lacks it and every suite dies. An empty placeholder runs the rest (Open Horizons then errors); delete it after. Conversely CI deletes `composer.lock` and installs the latest `wporg-mu-plugins` build, so upstream can turn every branch's "PHP Tests (other)" red while local stays green: mount the `build` branch's `mu-plugins/utilities/class-freescout.php` over that path, and pass on both clients (#2320, after wporg-mu-plugins#797).
- Core 7.1 unregisters `pingback.ping` outside production: return false from `wp_should_disable_pings_for_environment`. The source host must resolve publicly (`example.com`), answered by a `pre_http_request` stub; unhook `check_comment_flood_db` for reruns and `xmlrpc_pingback_error` for real faults (it maps all but 48 to `0, ''`, as production does). Camps `close_comments_for_old_posts` at 30 days, so older fixtures refuse for that reason. On `:8443` the target URL needs the port (`home` has it), or core faults "Is there no link to us?" first. `mt.getTrackbackPings` lists only `trackback` comments: seed one before trusting an empty array. Also send each method via `system.multicall`, a second dispatch path.

### CampTix

- A local checkout needs:
  - A published ticket and a `[camptix]` page. Seattle 2023's Tickets page (ID 13) is a draft of organizer copy: setting `post_content => '[camptix]'` wipes it with no revision. Append the shortcode, or restore from `.docker/data/wordcamp_dev.sql`.
  - A central WordCamp post not `wcpt-closed` (Seattle 2023's is): `wp post update --post_status=wcpt-scheduled`, and back (no log, no mail). `wcpt_close_wordcamps_after_event` recloses past camps on the next cron load (`wp cron event list`).
  - An enabled payment method, even for free tickets and refunds (else the form falls back to the ticket list).
  - A way past Spam_Prevention: "could not be processed" means the `…-tos-required` honeypot was ticked or the form went in under 2s. For curl, omit the honeypot and send `camptix-fsp-attendee-info-dob-required=<unix time a few seconds ago>` (the timestamp, despite the name). Failures score per IP in `form-spam-prevention-*` transients: clear them.
- Stripe needs no test keys: seed draft attendees, call `record_checkout_session()`, stub `api.stripe.com` with a `pre_http_request` mu-plugin answering from an option (one write per case), drive `?tix_action=payment_return|payment_cancel&tix_payment_token=…&tix_payment_method=stripe` as a logged-in buyer, and run `wp cron event run tix_scheduled_every_ten_minutes`. `tix_access_token`s must pass `ctype_alnum()` (an underscore looks like a broken redirect). PayPal: answer `api-3t…paypal.com/nvp` by `$args['body']['METHOD']` (append `:TOKEN`/`:TRANSACTIONID` per order) and filter `wp_redirect` so checkout lands back on the camp, giving a real draft order to sweep.
- To stub a gateway, filter `camptix_get_payment_method_by_id` above 10 (the real one wins at 10) and proxy the real object, overriding only refund methods (the page calls `supports_currency()` and friends). Confirm via `camptix_log_raw`, or Stripe got the call. A `sleep()` in the stub makes a two-curl race deterministic.
- Paying bumps `camptix_stats` and `invoice_current_number`, and status changes move `camptix_stats` in ways a refund doesn't undo (`wp_insert_post` as `publish` counts before price meta; `remaining` clamps at 0): save both before seeding, restore after. `camptix-invoices` is per-camp, inactive locally; PDFs land in `blogs.dir/<id>/files/camptix-invoices/`.
- The meta log is empty locally: a throwaway mu-plugin can append `camptix_log_raw` and `wp_mail` to a container file (`chmod 666` it, or php-fpm can't write a WP-CLI-created file).
- Edit Attendee Status renders only for `manage_sites`; organizers refund via the front-end Refund Request link. Test both when a change hooks attendee status.
- Require Login binds each attendee to one account (`require_login_edit_attendee_duplicate_username`): open attendee 2's `tix_edit_token` link as a second user.

### Groups events and Meetup

- The Meetup importer runs locally against live data via a throwaway mu-plugin: a fake token from `pre_site_option_meetup_access_token` (`access_token`, `refresh_token`, `expires_in`, `timestamp`); a `pre_http_request` forwarder re-sending `gql-ext` POSTs without auth and blocking other Meetup URLs; the real `Meetup_Client` inside `wcorg_meetup_import_pre_fetch` with `fetch_group_events()`'s exact arguments (diff them) and inside `…_pre_fetch_group` with `fetch_group()`'s query (`.docker/wp-config.php` leaves the OAuth keys empty, so `get_meetup_client()` refuses); other `…_pre_fetch_*` filters answering empty (members, account), but give organizers one edge or the summary hides its whole People section, RSVP and backfill lines included. `array_slice` a big group's events; feed RSVPs and hosts from a site option the forwarder reads. Import into a throwaway `create_group_site()` site. The client fetches `sort: DESC`. WP-CLI on `/group/` runs on the main site, which has no `date_format`.
- Send a `recurrence_id` that doesn't match the target RSVP's date: gates read the request's date, and that context limits `get_comments()` to that date's RSVPs. Then send none on a series: lists merge every date's RSVPs and time gates read the series start, so a future date's RSVP passes. The form's REST saves have no occurrence context either, so a waiting-list check there merges every date (#2273).
- With an occurrence in context, the series' `get_permalink()` already ends `…/{date}/` (`Context::post_link()`); appending the date 404s. Build from `Context::series_url()` and check the links an occurrence page prints.
- A new surface rendering event content or an auto-excerpt needs two fixtures: a recurring series (the occurrence selector is prepended via `the_content`, so excerpts become date lists; Sunshine Coast's `weekly-community-standup`, `…/{Ymd}T{His}/` URLs), and a wp-admin-built event, whose `post_content` carries GatherPress's template blocks (the front-end form keeps them on edit), so stripped text reads "Add to calendar Google Calendar iCal … Login Required … Attending (%d)" (Sunshine Coast event 2293). Use `Defaults\extract_description_blocks()`.
- Occurrence rows outlive their series (trash, draft and private leave them `scheduled`; only `before_delete_post` removes them): direct readers must join the series' `post_status` and test a trashed series (#2277 joined but didn't test).

### events.wordpress.org lists (`wporg_events`)

- Local `wporg_events` is empty after January 2024: seed upcoming rows with high ids, delete after. Its Google Map code is the gitignored `mu-plugins-private/wporg-mu-plugins/pub-sync/` copy: test a paired PR by patching it and restoring from a backup (`git status` won't show it). A new `get_events()` facet key counts toward `is_cacheable()`'s one-facet limit: check single-filter pages still cache.
- To run wordpress.org's `official-wordpress-events` importer locally, `docker cp` it into the container and shadow the table with a TEMPORARY copy from `SHOW CREATE TABLE` (the local one lacks `AUTO_INCREMENT` on `id` and `created_at`). In the container `events.wordpress.org` is production: a `pre_http_request` filter must serve the feed from `events.wordpress.test` (rewriting the host in the body) and refuse everything else. Define `OFFICIAL_WP_EVENTS_GOOGLE_MAPS_API_KEY`; restore `owpe_log` after. For a cheap real-data check first, run the parser and `hide_duplicate_meetups()` on host PHP with ~8 WP stubs (a subclass overriding the geocoders and `log()`) against the live feed.
- That repo's `phpcs-branch.php` diffs against `origin/trunk`'s tip, not the merge base (hundreds of files on a stale branch), and `phpcs-changed --git --git-base <merge-base>` missed a Yoda error CI caught (#1120). Run full-file `phpcs --report=json` and keep messages on lines added in `git diff -U0 origin/trunk...HEAD`.
- A new source under an existing `type` inherits its crons: `meetup` rows are checked hourly by `mark_deleted_meetups()`, which marks IDs Meetup.com doesn't know `deleted` (#1120).
- `UNIQUE (type, url(255))`, production too: a non-Latin title (~30 CJK or ~55 Cyrillic characters) encodes to a 200-character slug, so a series' occurrence URLs collide and upsert into one row. Seed a long-slug series and count rows, with a distinct title per fixture (a `-2` suffix re-encodes the slug shorter).
- Skipped rows (no coordinates) are neither updated nor deleted: test removing a venue, not just deleting. The check is `empty()`, so `0` counts, and the Groups feed rounds to 2 decimals: within ~0.005° of the equator or Greenwich reads as missing (GatherPress puts "Stratford, London" at longitude `0.0016`, #2320).
- `date_utc` and `end_date` hold local time, so `NOW()` cutoffs drift by the offset: #1120's `end_date >= NOW()` deletion pass loses a UTC−5 row 5h early, so a meetup unpublished that afternoon stays listed. Compare `DATE_SUB( end_date, INTERVAL date_utc_offset SECOND )` with `UTC_TIMESTAMP()`, and test an Americas fixture, not just Australian ones.
- Rows reach the theme as fake `WP_Post`s from `inject_events_into_query()` (no date, content or real ID): rendered through core they get 404ing `/?p=<id>` links and empty `pubDate`s unless overridden (#1561's feed filters). Pretty filter URLs join values with `-`, special-casing only a lone `in-person`, so `in-person-online` parses as three: check the dropdown's ticked state on the redirected URL.

## Findings

- Test your doubts before reporting them. An unverified concern costs the author more than it costs you.
- Rank by what it does to a user. Say which are blockers.
- Withdraw a nit when it turns out wrong, and say why. A cleanup that makes the code worse is not a finding.
- Say plainly what you did not check.

## Security reviews

- Attack the claim, not the diff: the sentence saying what the fix closes is what to test.
- Review the whole branch; every round is a chance to drop an item. "Addressed" is a claim: re-check earlier findings against the current head.
- Ask what the fix leaves behind. "Self-heals on the next sign-in" is worthless where nobody signs in; dormant data doesn't heal. Size it against the real population.
- Removing a bad control removes its side effects: name the one doing real work.
- Diff the failure mode too: a branch can fatal where trunk degraded.
- A guard on `add_option_*`/`update_option_*` polices transitions, not state (core drops a same-value write), so a site already bad stays bad. Does the fix self-enforce or need a sweep? Judge the sweep as part of the fix.
- Every superglobal is attacker-typed: `isset` and `! empty` don't make it a string.
- `add_query_arg()` doesn't encode values (`build_query()` passes `urlencode = false`): user text in a GET to a payment API (refund reason, display name) can add or override parameters (PHP keeps the last), and `#` truncates the rest, credentials included (#2312). Read the URL your stub receives; use `rawurlencode_deep()` or a POST body. A refund keyed on a browser-supplied id needs the gateway to confirm it belongs to the order (#2311 Razorpay).
- A capability grant keyed on `$_POST` fires on every request carrying the field, form-encoded REST included, where names differ (`author`, not `post_author`): replay against `/wp/v2/<rest_base>/<id>` (`wordcamp` is `show_in_rest`). Re-reading a core field needs core's precedence and cast: core takes `post_author_override` only when `! empty()`, cast `(int)`; `??` plus `absint()` lets `post_author_override=` (empty), `0`, or `post_author=-1` save another author or author 0.
- PHP checks and MySQL comparisons differ: collations are `_ci`, and case folding is nothing to a random token but fatal to a fixed-format one. `GET_LOCK` names are case-sensitive: a lock named from a request token that matched its row `_ci` is skipped by flipping case. Key locks on the stored value (#2217).
- A filter added in a `wp` callback misses requests that never fire `wp`: `wp-comments-post.php`, `xmlrpc.php`, REST (exits at `parse_request`), admin-ajax. A gate there only hides the form (#2292).
- Follow the cookie scope: a network domain makes a per-site bug network-wide.
- Coming Soon gates `template_include` and local REST, nothing else, and isn't private: `wcorg_enforce_public_blog_option()` pins `blog_public` to `1`, so feeds, sitemaps and the WP.com Search index answer anonymously. Check your surface against that list.
- A `posts_search`/`posts_where` fix covers REST (`/wp/v2/<type>?search=`, `/wp/v2/search`), not production's `?s=`: Jetpack classic search answers it from the WP.com index via `posts_pre_query` (`class-classic-search.php`, `should_handle_query()`), fed raw `post_content` by Jetpack Sync. Jetpack isn't connected locally, so `?s=` looks fixed: say it's unverified or filter Jetpack's query too (#2343). Grepping results for a slug, read `main .wp-block-post-title`: block-theme page lists name every page.
- Fix the class, not the instance. A patch opening a new false-positive class is not the fix.
- `lock: { remove }` isn't "can't remove": core 7.x's Hide → "Omit from published content" works on locked blocks, saving `metadata.blockVisibility: false`, and the front end drops them; one click and a template save hid a group's shared header with `canLockBlocks` and the code editor off (#2379). Core 7.1 gates Hide on theme.json `blockVisibility.allowEditing`, but the Groups network's Gutenberg plugin (23.5) checks only `canEdit` and `visibility` support and drops that setting: test editor-setting fixes in the browser, not core's `dist/` (#2379 strips visibility from `lock.remove` blocks in `render_block_data`). The Site Editor builds settings from global styles, so `block_editor_settings_all` never reaches it.
- Slug guards on a REST create must compare `sanitize_title()` of `$request['slug']`, not the raw value: `wp_insert_post()` lowercases later, so `HEADER` passed #2379's `in_array( $slug, array( 'header', … ), true )` and became `groups-site//header`.
- Regexes over translated output match only English: test under another `.po` (French spaces before colons), or build the needle from the same `__()` call.
- A password gate on a block doesn't gate the data. For anything an event or session links to (venue, speakers, topics, language), check every surface: `post_class`, REST `class_list`, term field and link, `?<taxonomy>=` filters, the `?post=` terms route, term archive and feed, RSS excerpt (GatherPress prepends the venue), `<head>` feed links, and the reverse link.
- A swapped template doesn't hide a page: `wp_head` and headers still describe the query (`rel=canonical`, `shortlink`, oEmbed discovery, REST `alternate`, `Link:` headers). Stopping a redirect hides nothing if the landing page prints the URL. Old-slug redirects run outside `redirect_canonical`.
- Path allowlists don't decide what WordPress serves: `WP::parse_request()` lets `$_GET`/`$_POST` query vars beat the path, so a sunrise exemption for `/group/wp-json/<route>` also serves `?rest_route=<any route>`, and a plain-permalink root ignores the path and renders its front end (`?feed=rss2`, `?s=`). `/?rest_route=<route>` itself is tight: REST exits before the template, and a differing POST `rest_route` dies with "variable mismatch" (#2277).
- Residual risk is accepted out loud or not at all.

## Proving it

- Prove the bypass in the container, at the version that ships; a plausible one is not a finding.
- After `switch_to_blog()`, registrations are still the origin site's, and PHPUnit (registering globally) can't see it. Central → camp: no `wcb_*` taxonomies (`wc-post-types` returns early on Central), so `wp_insert_term`/`wp_set_object_terms` silently return `invalid_taxonomy`; read terms back on the camp. Events network: `wcpt-*` statuses are unregistered and `WP_Query` drops an unregistered `post_status`, counting every `wordcamp` post (#2175); print `$query->request`. Groups root: `get_post_type_object( 'gatherpress_event' )->rewrite['slug']` stays `event` while each site's base is its GatherPress `events_url` (localized `/evento/`, or custom): compare `get_permalink()` on a site with a custom `events_url`; recurring-events' hardcoded `^event/` 404s its occurrence canonicals (#2277). Group site → store site (blog 47): `get_attached_file()` returns a nonexistent `mu/wp-content/blogs.dir/…`, so `filesize()`/`is_readable()` checks fail open, invisibly to a test that writes there: render the real group page (#2324's sponsor logo).
- Query the real schema: collation, charset and casts belong to the table, not memory. Read core before reporting core behavior; half of what you remember is a version stale. So is local core: `public_html/mu` was 7.1-beta2 while production ran 7.1.5-alpha (`?ver=` on `wp-includes` assets, or the feed's `<generator>`), with different image `loading`/`fetchpriority` (front-page card thumbnails eager locally, lazy live). Check loading claims against production's anonymous HTML.
- `term_exists( $string, $tax )` tries slug before name, while `wp_insert_term()` refuses only duplicate names (it suffixes slugs), so a "name taken" check on `term_exists()` fires on slug matches too. Compute slugs with `sanitize_title()`; don't guess.
- Two filters on one hook and priority run in load order: `wp-settings.php` line numbers settle it (mu-plugins load before core's late hooks).
- Local opcache revalidates every 2s (`opcache.revalidate_freq=2`): wait after swapping a file. "The old code doesn't reproduce it either" right after a swap is this until shown otherwise.
- Before crediting a fix for a visible change, turn it off and reload. `wordcamporg` loads against `get_user_locale()` on `plugins_loaded`, so its strings follow the visitor whatever `determine_locale()` says.
- Text-domain renames conflict only on lines both branches touched: grep the merge for the retired domain, or a string added elsewhere ships untranslatable.
- Bot reviewers read our comments, not the plugin: check their GatherPress claims against the shipping version.
- Two of your best findings will die on inspection. Kill them yourself, and write down that you did.
- Report the side effect that helps, not only the one that hurts.

## Disclosure

- Full detail goes in the private issue, never the public PR. Never name or link the security repo from anything public.
- Public text is an ordinary defect report: no payloads, attack framing or reporter. Enough for the author to fix it, not for a reader to reproduce it.
- Judge exposure against what is deployed, not trunk.
- Split what the branch closes from what it leaves open, and write both down.

## Batches

- Good PRs can still be a bad merge: check every pair for conflicts and how far behind `production` each is.
- A fix may be complete only with another open PR: merge them locally, re-run the repro, say they ship as a pair.
- Conflict resolution is review work. "Keep both" can break syntax (adjacent additions share a closing brace), and two conflicting whole lines can each carry a shipped feature, so either choice reverts one.
- Lint and run suites on the merge result, not just the branches.
- Once A is squashed, having merged A's branch into B doesn't make B clean: the squash isn't B's ancestor, so B's extensions of A conflict again, and git can silently auto-merge a stale copy of A's block beside B's (#2338 got a dead second `if ( $reformatted )` return). Simulate with `git merge --squash`: A, then `production` into B, then B; diff against B's file.
- An importer finding earlier work by recorded IDs must keep recording them on the skip-published path: once #2303 published on import, #2300's series stopped taking new Meetup IDs and a rerun duplicated it (it now keys on Meetup's `series.id`). Rerun with the first IDs gone, and over what the *deployed* importer wrote: swap production's file in, import, swap back.
- One PR's re-recorded stamp can be another's cutoff: #2336's `fix_markdown()` rewrites `IMPORTED_MODIFIED_META`, which #2338's backfill falls back on for deployed-importer events, so if the repair ran without "Match people", sign-ups between import and repair are never backfilled, in either merge order. Grep each PR's writes for keys the other reads.
- After the last merge, check the features compose, shipped ones included: anything saying "attended" must follow check-ins where a date has them (`Check_In\event_has_check_ins`), not RSVPs.

## Afterwards

- Reviews mutate local state: record what you touch and put it back. Clean up seeds by recorded ID, not title or date (a date sweep of `customize_changeset` took someone else's; CampTix blanks a `tix_attendee`'s `post_title` on insert). Deleting seeded attendees moves `camptix_stats`: snapshot it before, restore after. `wp site delete` on a throwaway group site leaves its `wc_<id>_gatherpress_occurrence*` tables: drop them.
- Switching back doesn't undo a theme switch: `wp theme activate` moves widgets to inactive, snapshots them into the old theme's `theme_mods_*` and creates the new theme's mods. Restore `sidebars_widgets` from the snapshot, then drop the snapshot and the new mods. A theme outside WP-CLI's roots (Seattle 2023's `twentytwenty`) needs `template`/`stylesheet` set directly; the first render then writes `theme_mods_<new theme>` (`custom_css_post_id` = -1): delete it too.

## Mechanics

```bash
# PHP suite, by name from phpunit.xml.dist
docker exec wordcamporg-phpunit_wp-1 bash -lc 'cd /app && phpunit --testsuite "<suite>"'

# Changed-lines PHPCS as CI runs it. Bare branch name: the script prepends
# `remotes/origin/`, so BASE_REF=origin/production scans nothing and exits 0
BASE_REF=production php .github/bin/phpcs-branch.php

# Front-end workspace. build/ is gitignored: rebuild after every checkout
cd public_html/wp-content/mu-plugins/<workspace> && npm run build && npm test

# PHP in the container. Root-file writes go stale on virtiofs, stdin doesn't
docker compose exec -T wordcamp.test wp eval-file - --url=<site> < script.php

# A language behaviour at the version that ships, not the one you remember
docker compose exec -T phpunit_wp php -r '<snippet>'

# Log in without a password: mint the cookie, set it in the browser. Name is
# `wordpress_logged_in_` + COOKIEHASH (empty on the events network, hashed on
# wordcamp.test camps: `wp eval 'echo LOGGED_IN_COOKIE;'`). An old cookie on a
# narrower path, or a stray `wordpress_sec_` on a broad one (core reads it
# first over HTTPS), wins: expire those and check the toolbar. Front end only:
# for wp-admin also mint "secure_auth" and set it as SECURE_AUTH_COOKIE on
# path=/wp-admin (document.cookie can set a narrower path)
docker compose exec -T wordcamp.test wp eval \
  'echo wp_generate_auth_cookie( <user_id>, time() + 3600, "logged_in" );' --url=<site>

# What the server sends: flags, headers and body, no browser in the way
curl -ksi -b 'tix_view_token=<value>' https://<site>/<path> | head -40

# What the database does. Collation decides comparisons, not the code
docker compose exec -T wordcamp.db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -t \
  wordcamp_dev -e "<query>"'
```

- Reproduce on the local multisite. Never against production.
- Another local stack may hold 80/443 (`curl -ksi`, read the headers). Don't stop it: set `WORDCAMP_HTTP_PORT=8080` and `WORDCAMP_HTTPS_PORT=8443` in a gitignored `.env`, browse `https://…:8443/`, delete `.env` after. `mu-plugins/0-local-https-port.php` (tracked) adds the port to `siteurl`/`home`, so group sites' `admin_url()`/`rest_url()` reach this stack. nginx gives a request 60s: a slower one 504s while PHP continues, and the next within ~10s gets 502 "no live upstreams". That's the stack, not the PR.
- The local PHPUnit image runs its own PHPUnit (`/tmp/vendor`), so project Composer packages (`mu-plugins/vendor`, the QuickBooks SDK) load only via the bootstrap's autoloader require (#2340; before it, full runs passed only because a CampTix Invoices test required it on load). Check a red local suite against `production`, alone and full, before blaming the branch.
- CI fails runs that write to PHP's error log, deprecations included, even all green; locally they're hidden (`log_errors` off, no `WP_DEBUG` in the generated `wp-tests-config.php`, so `E_DEPRECATED` drops). Reproduce with `php -d log_errors=On -d auto_prepend_file=<file defining WP_DEBUG true> $(command -v phpunit) …` and read "Unexpected entries" (ignore the local-only `WP_TESTS_MULTISITE already defined`). It catches `WordCamp\Logger\log()` codes with a space (only `function:code` with a slug code passes) and `wp_list_pluck()` over rows missing the key (`array_column()` skips them, #2300). New log lines on shared paths (WCOR triggers, status transitions) fire in other suites: run the full suite this way before merging (#1748 broke `production`).
- Review in a `git worktree` (the main checkout is usually mid-work). `cp -cR` the gitignored deps in (APFS clones: seconds, no extra disk): `public_html/mu`, `wp-config.php`, `wp-cli.yml`, `mu-plugins/vendor`, `mu-plugins-private`, the plugins and themes `git status --ignored` lists, `build/` dirs, and each workspace's own `node_modules` before its first `npm run build` (the build creates `node_modules/.cache`, an `[ -e ] || cp` then skips, and Jest resolves the root's packages: spurious `window.location` mock failures). Bind-mounting instead needs every mountpoint pre-created (empty `public_html/wp-config.php` and `wp-cli.yml`, nested dirs), or Docker refuses "mountpoint … is outside of rootfs"; a running container's mountpoint can't become a symlink, so run phpcs from the main checkout's `vendor/bin` with the worktree's `phpcs.xml.dist`.
- Serve the worktree from a second `wordcamp-test-local` container on `wordcamporg_default` (`-p 9443:443`, sharing the dev DB); curl with `--resolve events.wordpress.test:9443:127.0.0.1`. Cookies ignore ports, so a `:9443` cookie logs in the person's `:443` browser too: destroy those sessions by token (`WP_Session_Tokens::destroy()`) and expire the cookies after.
- PHPUnit on a worktree needs a second `wordcamporg-phpunit_wp` container with its own test DB: mount a `wp-tests-config.php` copy with a new `DB_NAME` and a writable `.docker/test_suite` copy (upload-writing suites fail read-only). A fresh `mariadb:12.0` needs `--skip-ssl`, or the image's client rejects its self-signed cert and the entrypoint waits forever. Never run two PHPUnit processes on one test DB: the second install drops the first's tables, and the first still exits 0, its log full of "Table … doesn't exist".
- Squash merge only: one commit per PR, `(#NNNN)` in the subject. The squash message is the branch's commit messages (`COMMIT_MESSAGES`), so "Fixes #N" in any commit body closes the issue even if the PR body says "See #N" (#2379 closed #2368 with cleanup pending). Grep `git log origin/production..<head> --format=%B` for closing keywords, or pass `--body` to `gh pr merge`.
- Never force push a pushed branch. Follow-up commits only.
