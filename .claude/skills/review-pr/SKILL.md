---
name: review-pr
description: Review a pull request in this repo the way we review here, including security fixes under private disclosure. The method, not the commands - what to distrust in a PR body, how to reproduce and mutate, how to drive the real UI with claude-in-chrome and read the raw response behind it, how to prove a finding before reporting it, how to merge a batch, and how to split public PR text from a private security issue. Use when reviewing a PR, a branch, or a batch of branches, and when reviewing or writing up a security fix.
---

# How we review pull requests here

Commands are in `AGENTS.md`. Domain traps are in
`.claude/skills/groups-gatherpress-compat-test/`. This is the method.

**Keep this skill current.** When a review turns up something essential (a
trap that cost an hour, a class of bug this repo keeps producing, a fix that
looked right and was not), add it before the session ends, in its section.
"Essential" means the next reviewer would hit the same wall without it. Fold
it into existing lines; this is current practice, not a changelog.

A review is an independent attempt to make the change fail.

## Claims

- The PR body is a hypothesis, not evidence. "Covered by tests" means go find the test.
- Re-run every suite it quotes. Counts go stale the moment `production` moves.
- An old PR may be superseded. For a branch far behind, `git log origin/production` on its files and read what landed since. Rebasing it can quietly revert the newer fix.
- A green CI run says the suite passed, not that it covers this. It can also be a run that stopped: CampTix redirects end in `die()`, as does any `wp_safe_redirect(); exit;` reached through `go_to()`, so PHPUnit exits 0 and skips every later test. Compare the test count with the suite's, and turn `wp_redirect` into `WPDieException` (see `test-require-login.php`) in every test that drives the route, not just the one expecting a redirect, or a regression that redirects where it should 404 ends green (#2225).

## Reproducing

- Reproduce the reported symptom before reading the fix, by the path a person takes, not the one the fix makes convenient.
- A fix can be correct, tested, passing, and leave the bug in place. Assert on the surface that broke, at the layer the user sees, not on the helper behind it. Right data is not a right page.
- For anything looping over camps (CLI, cron, sweep), read which camps it selects before what it does. `post_status => any` on `wordcamp` is every camp since 2006, closed ones too. All three local camps with sites are past, so a dry run already shows whether history gets written. Groups are their own network (`events.wordpress.org/group/`, network 4 locally): a `$wpdb->blogs` sweep for `get_current_network_id()` with `--url=events.wordpress.org` sees no group site and reports nothing to do (#2231).
- A CampTix change moving attendee status outside `payment_result()` skips more than the status. The central Ticket Revenue report counts sales/refunds only from the exact log line `Attendee status has been changed to <status>`, and `camptix_payment_result` listeners (refund stats, page-cache clear, invoices) never fire. Grep for both before accepting a direct `wp_update_post()` on a `tix_attendee`.
- Camps come in classic and block themes; the local stack may have only one. Block-theme camps render `mu-plugins/theme-templates/block-templates/` around the post, so blocks there (session speakers, speaker sessions) sit outside the password form. Switch a camp to `twentytwentyfour` to check, and undo it (see Afterwards).

## In the browser

- A green suite proves functions, not pages. Drive the real UI with `claude-in-chrome` against the local stack.
- The browser shows what a person sees; a raw request shows what the server sends, and an attacker sends raw requests. Read both. Cookie flags, headers and delivered markup come off the response, never the source.
- Walk it as every actor the change touches, logging out between them. Anonymous is an actor, and where gated-content findings usually live.
- Malformed input goes in the request, not the form. A field the page won't let you type in is still one an attacker can send.
- "A save with no author field" isn't the classic form, which always posts a hidden `post_author`. Remove `#post_author` before submitting, or use REST with `author` set to yourself (no `edit_others_posts` check then).
- Check who answers the URL: another local stack can hold 443. `curl -ksi` and read the headers. The WordCamp container is also on `:8443`.
- Block assets ship with a fixed `?ver=1.0.0`; hard-reload (`cmd+shift+r`) after a rebuild before judging styling.
- A new `wordcamp/*` block only registers in the editor if its controller adds a key to `WordCampBlocks`, built on `init` before the admin screen exists, so a screen check there drops the key. Insert it from the inserter before reviewing anything else.
- A 403/400 from a raw REST call isn't yet the permission check. No nonce gives `rest_cookie_invalid_nonce` (get one from `admin-ajax.php?action=rest-nonce`); arg validation runs before `permission_callback`, so send a full valid payload and look for `rest_forbidden`.
- Same for a nonce-gated `admin-post.php` action: a WP-CLI nonce has no session token and fails before the capability check. Mint it with the cookie in place: `$_COOKIE[LOGGED_IN_COOKIE] = '<minted cookie>'; wp_set_current_user( <id> ); echo wp_create_nonce( '<action>' );`, then curl with that cookie. A capability 403 carries the handler's own message, not "The link you followed has expired".
- `resize_window` can silently not apply. For widths, load the page in a same-origin iframe of that width and measure there.
- Prefer `find` and click by ref; coordinates go stale when the viewport moves. Refs go stale too when React re-mounts a control, so re-`find` right before each `form_input`. A click below the fold may only scroll it into view: screenshot before deciding a button does nothing, and click again.
- Login submit can silently no-op. Press `Return` in the password field and confirm on the toolbar.
- Opening `wp-admin/customize.php` on a camp whose theme isn't installed (Seattle, shinynew) makes core's `validate_current_theme()` really switch it to `twentytwentyfour`. On `:8443` Coming Soon also fails to swap the preview to `?wccsp-preview`; set it with `wp.customize.previewer.previewUrl.set()` and read the preview iframe's DOM. A Customizer tab with unsaved changes hangs `tabs_close_mcp` on its leave-page prompt and leaves an `auto-draft` `customize_changeset`. Prefer checking saved state with WP-CLI and curl.
- Never click a `window.confirm()` button: automation can't answer it. Submit the form directly. The event modal's Cancel is one once a field changed; close a frozen tab with `tabs_close_mcp`. Any block-editor tab with a loaded post counts as unsaved, so its prompt blocks `navigate` and hangs `tabs_close_mcp`; open new pages in a fresh tab.
- Judge generated block markup with `wp.blocks.parse()` in an editor tab (`isValid` on every inner block), not by eye. It's lenient (a raw nested `<ul>` in a list item passes), so a mutation that stops converting it isn't a failure. Then run the PR's new test against the pre-fix code; a text-only assertion ("Sub 1") passes on broken markup too.
- Decode a QR code in the page with Chrome's `BarcodeDetector` (draw the SVG to a canvas first). Asserting on `data-url` only proves the input.
- Check a print claim ("fits one page") with headless Chrome `--print-to-pdf` and count the pages. It can hang after writing the file; kill it.
- Chrome won't zoom or inspect a PDF from a `blob:` URL. Rasterize with `gs -sDEVICE=png16m` and crop with `sips`. For RTL or shaped text, `gs -sDEVICE=txtwrite` prints glyphs in drawn order, which settles word order.
- A focus-triggered payload won't fire in an automated tab (no window focus; script `.focus()` sends no event). Click the element for real before calling it inert.
- "A server error occurred … Your submission failed" is the PWA plugin's service worker covering a failed POST, re-registered on every load. It also answers a `fetch()` replay from a wp-admin tab with a 200 even when the server refused it, so replay forms with curl. Find the cause server-side with a temporary mu-plugin logging through `wp_die_handler` and a shutdown function: a `wp_die()` exits 500 and writes nothing to `debug.log`.
- A protected wcpt field rendered `disabled` posts nothing, while the region dropdown posts its stored value, so any check reading `$_POST` for a protected field (the scheduled-status check does) sees it empty for non-wranglers. Locally `contributorrole` (6) is no mentor fixture: `.docker/wp-config.php` gives it every wrangler subrole via `$wcorg_subroles`, so its saves pass for the wrong reason. Use `authorrole` (5) added to Central as a contributor, and remove it after. Before #2167 a mentor can't save on Central at all (#2159). Dropping `post_author` from a replayed form gets past core's check, but core then saves the mentor as author, so it isn't what a mentor's browser sends.
- Screenshot anything you intend to assert. A finding with a picture survives the round trip.

## Tests

- Break the code. Watch the test fail. Every time. A test that cannot fail is not a test.
- A test can pass for a reason you didn't intend. Mutate the exact line you think it pins, and confirm the mutation landed (count changed lines in `git diff`; a `sed` that misses a quote changes nothing and "passes").
- Beware tests that do the work themselves. Drive the real trigger, then read the result back.
- A filter added only so tests can inject data (`…_groups`, `…_count` returning non-null) skips the real source. Run the real path once in the container. #2175 was green while its WordCamp count fatalled: `get_wordcamps()` assigns `->meta` to every result, so `'fields' => 'ids'` dies on an int.
- A test that registers the very callback it asserts was removed proves the `remove_action` matches itself, not the vendor. Read the vendor's `add_action` for hook, callable and priority.
- A test calling a filter callback directly can't catch a wrong hook name. Unhook it and rerun; if still green, prove the hook fires with a real request.
- Exercise the edges the PR claims in prose; those are wrong most often.
- A new branch with no test is a finding, even when it works.

## Fixtures

- Suspect your fixtures before the code. A direct write earlier in the session can fake a bug no code path produces.
- Check the fixture can tell the cases apart (identical fixtures make a test vacuous), and seed the data the edge case needs (absent data passes every assertion).
- Theme pattern files are cached by theme `Version` for 30 minutes. After switching branches run `wp_get_theme()->delete_pattern_cache()` or a new pattern renders nothing. In production too: a new pattern file without a `Version` bump ships late.
- No local camp has sessions. For Speaker Feedback, seed a published `wcb_session` with `_wcpt_session_type=session` and a past `_wcpt_session_time`; the form is at `…/session/<slug>/feedback/` and answers wait in moderation.
- Logo/featured-image sideloads from Central fail locally with cURL error 60 (self-signed cert), so a missing camp thumbnail proves nothing. Re-run the WP-CLI command with `--require` a file calling `WP_CLI::add_wp_hook( 'https_ssl_verify', '__return_false' )` (plain `add_filter` isn't loaded at `--require` time).
- Seattle and Shiny New 2023 point at `twentytwenty`, not installed locally, so the body renders blank; their Coming Soon is on too, so anonymous gets the placeholder. Set `template`/`stylesheet` to `campsite-2017` (or another installed theme) and put it back (see Afterwards).
- `wp-config.php` globals (`$trusted_deputies`, `$wcorg_subroles`) aren't global under WP-CLI, so `user_can( 6, 'wcb_approve_budget' )` says no there. Judge deputy access in the browser.
- CampTix's meta log is empty locally. To capture a web request's log lines and mail, a throwaway mu-plugin can append `camptix_log_raw` and `wp_mail` to a file in the container; `chmod 666` it, or php-fpm can't write a file WP-CLI created.
- MES decides "organizer edited this copy" by `post_modified !== post_date`, and core resets a draft's `post_date` on every save. Edits to a draft copy, or in the save that publishes it, look untouched and get overwritten by Push to Active Camps. Seed an organizer edit on a draft copy before trusting "skips edited copies".
- The Edit Attendee Status dropdown renders only for `manage_sites`; organizers refund via the front-end Refund Request link. Test both paths when a change hooks attendee status.
- Seed through the real API, then read it back. GatherPress drops what the event or site forbids: group sites force anonymous RSVPs off, so a saved "anonymous" RSVP is ordinary.
- Publishing a seeded event emails every group member "New event". Seed as a draft first if the mail log matters.
- A local CampTix checkout needs what camps lack:
  - A published ticket and a `[camptix]` page.
  - A central WordCamp post that isn't `wcpt-closed` (Seattle 2023's is). Reopen with `wp post update --post_status=wcpt-scheduled` (no status log, no mail) and restore `wcpt-closed` the same way. It won't stick long: `wcpt_close_wordcamps_after_event` closes past camps on the next cron-running load; check `wp cron event list`.
  - At least one enabled payment method, even for a free ticket.
  - Getting past Spam_Prevention. "Could not be processed" means the hidden `…-tos-required` honeypot was ticked or the form was sent under 2s after rendering. For curl, send `camptix-fsp-attendee-info-dob-required=<unix time a few seconds ago>` (the timestamp field, despite the name) and omit the honeypot. Failures add to a per-IP score in `form-spam-prevention-*` transients; clear them before retrying.
- A Stripe order needs no test keys. Seed draft attendees, call `record_checkout_session()`, and stub `api.stripe.com` with a throwaway `pre_http_request` mu-plugin answering from an option (one option write per case). Drive `?tix_action=payment_return|payment_cancel&tix_payment_token=…&tix_payment_method=stripe` as a logged-in buyer (camps require login) and run the real `wp cron event run tix_scheduled_every_ten_minutes`. Seed alphanumeric `tix_access_token`s: CampTix rejects anything `ctype_alnum()` refuses, and an underscore looks like a broken redirect. `camptix-invoices` is per-camp and inactive locally; invoice PDFs land in `blogs.dir/<id>/files/camptix-invoices/`. Paying bumps `camptix_stats` and `invoice_current_number`, and any status change moves `camptix_stats` in ways seed-then-refund doesn't cancel (`wp_insert_post` as `publish` counts before price meta exists, `remaining` clamps at 0), so save both options before seeding and write them back. Refunding a seeded order also needs an enabled payment method, or the form falls back to the ticket list.
- Require Login ties each attendee to one account, so the buyer can't open another attendee's `tix_edit_token` link (`require_login_edit_attendee_duplicate_username`). Open attendee 2's link as a second user.
- Seattle 2023's Tickets page (ID 13) is a draft of organizer copy. Publishing it with `post_content => '[camptix]'` wipes that copy and its date with no revision. Append the shortcode, or restore from `.docker/data/wordcamp_dev.sql`, the seed dump.
- Some paths can't run locally; silence isn't a pass. `WCOR_Mailer::mail()` fatals under WP-CLI (needs the admin-only `$wordcamp_admin` global), so drive WCOR triggers from wp-admin. Docs, invoice and visa-letter PDFs render through DomPDF and work locally (the image's `wkhtmltopdf` can't start on arm64). To check one without a download, replay the Docs steps with `fetch()` in the wp-admin tab and read the `%PDF-` header, `/Producer` and page count. `wordcamp-qbo` is inactive, so the sponsor-invoice approve button can't run.
- Name fixtures so core's 404 slug guessing can't match them. A post `flyer-review-…` turned every unrouted `…/flyer/` into a 301 to it.
- On any route taking a `recurrence_id`, send one that doesn't match the target RSVP's date. Gates read the request's date, not the object's, and with that context set `get_comments()` returns only that date's RSVPs, so derived state goes silently wrong. Then send none on a series: the series reads as one event, lists merge every date's RSVPs, and time gates read the series start, so a future date's RSVP passes a gate the mismatched-date case was fixed for.
- With an occurrence in context, `get_permalink()` on its series already returns `…/{date}/` (`Context::post_link()`). Appending the date again doubles it and 404s. Build from `Context::series_url()` and check the links an occurrence page prints, not just the series page's.
- Any new surface rendering event content or an auto-excerpt needs a recurring series: recurring-events prepends its occurrence selector through `the_content`, so an excerpt becomes a list of dates. Sunshine Coast's `weekly-community-standup` has dated `…/{Ymd}T{His}/` URLs.
- The events.wordpress.org lists read `wporg_events`, and the local copy is empty after January 2024. Seed upcoming rows with high ids and delete them after. Its Google Map code is the gitignored `mu-plugins-private/wporg-mu-plugins/pub-sync/` copy, not a `wporg-mu-plugins` checkout: test a paired mu-plugins PR by patching that copy and restoring from a backup (`git status` won't show it). Any new facet key passed to `get_events()` counts toward `is_cacheable()`'s one-facet limit; check a single-filter page still caches.
- `wporg_events` rows reach the theme as fake `WP_Post`s from `inject_events_into_query()` (no date, no content, invented ID). A feed or template rendering them through core gets 404ing `/?p=<id>` links and an empty `pubDate` unless it overrides them, as #1561's feed filters do. Pretty filter URLs join values with `-` and only a lone `in-person` is special-cased, so a two-value facet parses `in-person-online` as three. Check the dropdown's ticked state on the redirected URL, not just the results.

## Findings

- Test your doubts before reporting them. An unverified concern costs the author more than it costs you.
- Rank by what it does to a user. Say which are blockers.
- Withdraw a nit when it turns out wrong, and say why. A cleanup that makes the code worse is not a finding.
- Say plainly what you did not check.

## Security reviews

- Attack the claim, not the diff. The sentence saying what the fix closes is the thing to test.
- Review the whole branch; a fix that survived three rounds had three chances to drop an item. "Addressed" is a claim too: re-check every earlier finding against the current head.
- Ask what the fix leaves behind. "Self-heals on the next sign-in" is worthless where nobody signs in again. Size it against the real population; dormant data doesn't heal.
- Removing a bad control removes its side effects. Name the one quietly doing real work.
- Diff the failure mode, not just the success path. A branch can fatal where trunk degraded.
- A guard on `add_option_*`/`update_option_*` polices transitions, never state. Core drops a write of the already-stored value, so a site already in the bad state stays there. Ask whether the fix self-enforces or needs a one-off sweep, and judge the sweep as part of the fix.
- Every superglobal is attacker-typed. `isset` and `! empty` don't make it a string.
- A capability grant keyed on `$_POST` fires on every request carrying the field. PHP fills `$_POST` for form-encoded REST calls too, and REST names fields differently (`author`, not `post_author`), so an "author unchanged" check reads nothing there. Replay against `/wp/v2/<rest_base>/<id>` with the field added; `wordcamp` is `show_in_rest`. A check re-reading a field core reads must use core's precedence and cast: core takes `post_author_override` only when `! empty()`, cast `(int)`; `??` plus `absint()` passes `post_author_override=` (empty), `0`, or `post_author=-1`, and core saves another author or author 0.
- A check in PHP and a comparison in MySQL are different checks. Collations are `_ci`. Format is not entropy: case folding is nothing to a random token and fatal to a fixed-format one.
- Follow the cookie scope. A network domain turns a per-site bug into a network one.
- Coming Soon gates `template_include` and local REST, nothing else. It's no privacy signal: `wcorg_enforce_public_blog_option()` pins `blog_public` to `1` on read and write, so WordPress.com treats every gated camp as public, and feeds, sitemaps and the WP.com Search index answer anonymously. Check your surface against that list.
- Fix the class, not the instance. A patch opening a new false-positive class is not the fix.
- A regex over translated output only matches English. Run it under another locale's `.po` (French spaces before the colon), or build the needle from the same `__()` call.
- A password gate on a block isn't a gate on the data. For anything an event or session links to (venue, speakers, topics, language), check every surface naming it: `post_class`, REST `class_list`, term field and link, `?<taxonomy>=` filters, the `?post=` terms route, the term archive and feed, the RSS excerpt (GatherPress prepends the venue), `<head>` feed links, and the reverse link from the other object.
- A swapped template isn't a hidden page. `template_include` changes the body, but `wp_head` and headers still describe the resolved query: `rel=canonical`, `shortlink`, oEmbed discovery, the REST `alternate` link and `Link:` headers all print the permalink. Stopping a redirect hides nothing if the landing page prints the URL. Old-slug redirects run outside `redirect_canonical`.
- Residual risk is accepted out loud or not at all.

## Proving it

- Prove the bypass; a plausible one is not a finding. Run it in the container, at the version that ships.
- Code on Central that `switch_to_blog()`s into a camp keeps Central's registrations. `wc-post-types` returns early on Central, so `wcb_*` taxonomies don't exist and `wp_insert_term`/`wp_set_object_terms` return `invalid_taxonomy` silently. PHPUnit registers them globally and can't see it. Run the real command and read the terms back on the camp.
- Query the real schema; collation, charset and casts belong to the table, not your memory. Read core before reporting core behavior; half of what you remember is a version stale.
- `term_exists( $string, $tax )` tries slug before name, and `wp_insert_term()` refuses only a duplicate name (it suffixes a clashing slug), so a "name taken" check on `term_exists()` also fires on a slug match. Compute the slug with `sanitize_title()` before seeding; don't guess.
- Two filters on one hook at one priority are decided by load order. `wp-settings.php` line numbers settle it; mu-plugins run before core registers its late hooks.
- Local opcache rechecks files every 2s (`opcache.revalidate_freq=2`). After swapping a file, wait a few seconds or you're testing the old one. "The old code doesn't reproduce it either" right after a swap is this until shown otherwise.
- Before crediting a fix for a visible change, turn it off and reload. `wordcamporg` loads against `get_user_locale()` on `plugins_loaded`, so its strings follow the visitor whatever `determine_locale()` says.
- A text-domain rename conflicts only where both branches touched the same line. Grep the merge result for the retired domain: a string the other branch added elsewhere merges clean and ships untranslatable.
- Bot reviewers read our comments, not the plugin. Check their GatherPress claims against the shipping version; our comments go stale when upstream changes.
- Two of your best findings will die on inspection. Kill them yourself, and write down that you did.
- Report the side effect that helps, not only the one that hurts.

## Disclosure

- Full detail goes in the private issue, never the public PR. Never name or link the security repo from anything public.
- Public text is an ordinary defect report: no payloads, attack framing or reporter. Enough for the author to fix it, not for a reader to reproduce it.
- Judge exposure against what is deployed, not trunk.
- Split what the branch closes from what it leaves open, and write both down.

## Batches

- Good PRs can still be a bad merge. Check every pair for conflicts, and how far behind `production` each is, before anyone merges.
- A fix can be complete only together with another open PR. Merge them locally, re-run the repro on the result, and say they ship as a pair.
- Conflict resolution is review work. "Keep both" can be syntactically wrong (adjacent additions often share a closing brace), and a conflict can offer two whole lines each carrying a different shipped feature, so taking either reverts one.
- Lint and run suites on the merge result, not just the branches.
- After the last merge, check the features compose, not just coexist, including already-shipped ones: anything saying "attended" must follow check-ins on a date that has them (`Check_In\event_has_check_ins`), not RSVPs.

## Afterwards

- Reviews mutate local state. Write down what you touch and put it back. The next review assumes the fixtures are honest.
- A theme switch isn't undone by switching back. `wp theme activate` moves widgets to inactive, snapshots them into the old theme's `theme_mods_*`, and creates the new theme's mods: restore `sidebars_widgets` from the snapshot and drop the snapshot and the new mods row. A theme outside WP-CLI's theme roots (Seattle 2023's `twentytwenty`) won't activate, so set `template`/`stylesheet` directly; the first render then writes `theme_mods_<new theme>` (`custom_css_post_id` = -1), so delete that row too.

## Mechanics

```bash
# PHP suite, by name from phpunit.xml.dist
docker exec wordcamporg-phpunit_wp-1 bash -lc 'cd /app && phpunit --testsuite "<suite>"'

# Changed-lines PHPCS, the way CI runs it
BASE_REF=production php .github/bin/phpcs-branch.php

# Front-end workspace. build/ is gitignored, so rebuild after every checkout
cd public_html/wp-content/mu-plugins/<workspace> && npm run build && npm test

# PHP in the container. Root-file writes go stale on virtiofs, stdin does not
docker compose exec -T wordcamp.test wp eval-file - --url=<site> < script.php

# Check a language behaviour at the version that ships, not the one you remember
docker compose exec -T phpunit_wp php -r '<snippet>'

# Log in without a password: mint the cookie, set it in the browser.
# Name is `wordpress_logged_in_` + COOKIEHASH: empty on the events network,
# hashed on wordcamp.test camps (`wp eval 'echo LOGGED_IN_COOKIE;'`).
# An old cookie on a narrower path beats one on `/`, so expire those and check
# the toolbar. Over HTTPS core reads `wordpress_sec_` first, so a stray one on a
# broad path wins on the front end too. This covers the front end only: for
# wp-admin also mint "secure_auth" and set it as SECURE_AUTH_COOKIE on
# path=/wp-admin (document.cookie can set a narrower path)
docker compose exec -T wordcamp.test wp eval \
  'echo wp_generate_auth_cookie( <user_id>, time() + 3600, "logged_in" );' --url=<site>

# What the server actually sends. Flags, headers and body, no browser in the way
curl -ksi -b 'tix_view_token=<value>' https://<site>/<path> | head -40

# What the database actually does. Collation decides comparisons, not the code
docker compose exec -T wordcamp.db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -t \
  wordcamp_dev -e "<query>"'
```

- Reproduce on the local multisite. Never against production.
- CI fails any run that writes to PHP's error log, deprecations included, even with every test green. Locally they never show (`log_errors` off, and the generated `wp-tests-config.php` doesn't define `WP_DEBUG`, so `E_DEPRECATED` is dropped). Reproduce with `php -d log_errors=On -d auto_prepend_file=<file defining WP_DEBUG true> $(command -v phpunit) …` and read the "Unexpected entries" block; ignore the local-only `WP_TESTS_MULTISITE already defined`. A `WordCamp\Logger\log()` call counts too when its code has a space: the bootstrap accepts only `function:code` with a slug code. A new log line on a shared path (a WCOR trigger, a status transition) fires in other plugins' suites, so run the full suite this way before merging (#1748 broke `production` that way).
- If another stack holds 80/443, don't stop it. Put `WORDCAMP_HTTP_PORT=8080` and `WORDCAMP_HTTPS_PORT=8443` in a gitignored `.env`, browse `https://…:8443/`, and delete `.env` afterwards.
- Squash merge only: one commit per PR, `(#NNNN)` in the subject.
- Never force push a pushed branch. Follow-up commits only.
