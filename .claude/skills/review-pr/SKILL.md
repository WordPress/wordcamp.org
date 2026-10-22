---
name: review-pr
description: Review a pull request in this repo the way we review here, including security fixes under private disclosure. The method, not the commands - what to distrust in a PR body, how to reproduce and mutate, how to drive the real UI with claude-in-chrome and read the raw response behind it, how to prove a finding before reporting it, how to merge a batch, and how to split public PR text from a private security issue. Use when reviewing a PR, a branch, or a batch of branches, and when reviewing or writing up a security fix.
---

# How we review pull requests here

Commands are in `AGENTS.md`. Domain traps are in
`.claude/skills/groups-gatherpress-compat-test/`. This is the method.

**Keep this skill current.** It is a living record of how we review, not a
fixed checklist. When a review turns up something essential, a trap that
cost you an hour, a class of bug this repo keeps producing, a way a fix
looked right and was not, add it here before the session ends, in the
section it belongs to. "Essential" means the next reviewer would hit the
same wall without it. Fold it into the existing lines rather than appending
a per session bullet list. This file should read as current practice, not a
changelog.

A review is an independent attempt to make the change fail.

## Claims

- The PR body is a hypothesis, not evidence.
- Re-run every suite it quotes. Counts go stale the moment `production` moves.
- An old PR may already be superseded. Before reading the diff of a branch far behind, run `git log origin/production` on the files it touches and read what landed since. Rebasing a superseded branch can quietly revert the newer fix.
- "Covered by tests" means go and find the test.
- A green CI run says the suite passed, not that the suite covers this.
- A green run can be a run that stopped. CampTix redirects end in `die()`, and any `wp_safe_redirect(); exit;` reached through `go_to()` does the same: PHPUnit exits 0 and skips every later test. Compare the test count with the suite's, and make tests turn `wp_redirect` into `WPDieException` (see `test-require-login.php`). Do it in every test that drives the route, not just the one expecting a redirect, or a regression that starts redirecting where it should 404 ends the suite green (#2225).

## Reproducing

- Reproduce the reported symptom before reading the fix.
- Use the path a person takes, not the one the fix makes convenient.
- A fix can be correct, tested, passing, and leave the bug in place.
- Assert on the surface that broke, not on the helper behind it.
- Check the fix at the layer the user sees. Data being right is not the page being right.
- For anything that loops over camps (a CLI command, a cron, a sweep), read which camps it selects before what it does to them. `post_status => any` on `wordcamp` is every camp since 2006, closed ones too. All three local camps with sites are past, so a dry run on them already shows whether history gets written to. Groups are their own network (`events.wordpress.org/group/`, network 4 locally), not the events root's: a sweep of `$wpdb->blogs` for `get_current_network_id()` run with `--url=events.wordpress.org` sees no group site and reports nothing to do (#2231).
- Any CampTix change that moves an attendee's status outside `payment_result()` skips more than the status. The central Ticket Revenue report counts sales and refunds only from the exact log line `Attendee status has been changed to <status>`, and `camptix_payment_result` listeners (refund stats, page-cache clear, invoices) never fire. Grep for both before accepting a direct `wp_update_post()` on a `tix_attendee`.
- Camps come in classic and block themes, and the local stack may only have one. Block-theme camps render `mu-plugins/theme-templates/block-templates/` around the post, so blocks there (session speakers, speaker sessions) sit outside the password form. To check, switch a camp to `twentytwentyfour` and put it back (see Afterwards). Seattle and shinynew point at `twentytwenty`, which isn't installed locally, so they render a blank page until you do. Their Coming Soon is on too, so anonymous gets the placeholder.

## In the browser

- A green suite proves functions, not pages. Drive the real UI with `claude-in-chrome` against the local stack.
- The browser shows what a person sees. A raw request shows what the server sends. An attacker sends raw requests, so read both.
- Cookie flags, response headers and delivered markup are read off the response, never off the source.
- Walk it as every actor the change touches. Log out between them.
- Anonymous is an actor. Gated-content findings live there more often than anywhere else.
- "A save with no author field" isn't the classic form: it always posts a hidden `post_author`. Reproduce by removing `#post_author` before submitting, or over REST with `author` set to yourself (no `edit_others_posts` check when that's you).
- Malformed input goes in the request, not the form. A field the page will not let you type into is not a field an attacker cannot send.
- Check who answers the URL first. Another local stack can hold 443; `curl -ksi` and read the headers. The WordCamp container is also on `:8443`.
- Block assets ship with a fixed `?ver=1.0.0`. After a rebuild, hard-reload (`cmd+shift+r`) before judging any styling, or you are looking at the old CSS.
- A new `wordcamp/*` block only registers in the editor if its controller adds a key to `WordCampBlocks`. That data is built on `init`, before the admin screen exists, so a screen check there drops the key. Insert the block from the inserter before reviewing anything else about it.
- A 403 or 400 from a raw REST call is not yet the permission check. No nonce gives `rest_cookie_invalid_nonce`; get one from `admin-ajax.php?action=rest-nonce`. Arg validation runs before `permission_callback`, so send a full valid payload and look for `rest_forbidden`.
- The same holds for a nonce-gated `admin-post.php` action: a nonce minted in WP-CLI has no session token, so it fails before the capability check. Mint it with the user's cookie in place: `$_COOKIE[LOGGED_IN_COOKIE] = '<minted cookie>'; wp_set_current_user( <id> ); echo wp_create_nonce( '<action>' );`, then curl with that cookie. A 403 from the capability check carries the handler's own message, not "The link you followed has expired".
- `resize_window` can silently not apply. For widths, load the page in a same-origin iframe of that width and measure it there.
- Prefer `find` and click by ref. Coordinates go stale when the viewport moves under you.
- A click on a button below the fold can just scroll it into view. Screenshot before deciding a modal button does nothing, and click again.
- Refs go stale too when React re-mounts a control. Re-`find` right before each `form_input`.
- Login submit can silently no-op here. Press `Return` in the password field, then confirm on the toolbar before trusting anything after it.
- Opening `wp-admin/customize.php` on a camp whose theme isn't installed (Seattle, shinynew) makes core's `validate_current_theme()` switch it to `twentytwentyfour` for real. On `:8443` the Coming Soon section also fails to swap the preview to `?wccsp-preview`; set it with `wp.customize.previewer.previewUrl.set()`, then read the preview iframe's DOM. A Customizer tab with unsaved changes hangs `tabs_close_mcp` on its leave-page prompt and leaves an `auto-draft` `customize_changeset` behind. Prefer checking saved state over WP-CLI and curl.
- Never click a `window.confirm()` button. It blocks the page and automation cannot answer the dialog. Submit the form directly instead. The event modal's Cancel is one once a field has changed; if a tab freezes, close it with `tabs_close_mcp`. Any block-editor tab with a loaded post counts as unsaved, so its leave-page prompt blocks `navigate` and hangs `tabs_close_mcp`; open new pages in a fresh tab instead of navigating away. Judge generated block markup with `wp.blocks.parse()` in that tab (`isValid` on every inner block), not by eye. It is lenient: a raw nested `<ul>` inside a list item passes, so a mutation that stops converting it isn't a failure. Then run the PR's new test against the pre-fix code; an assertion on text alone ("Sub 1") passes on broken markup too.
- Decode a QR code in the page with Chrome's `BarcodeDetector` (draw the SVG to a canvas first). Asserting on `data-url` only proves the input, not the code.
- A print claim ("fits one page") is checked with headless Chrome's `--print-to-pdf`, then count the PDF's pages. It can hang after writing the file; kill it.
- Chrome won't zoom or screenshot-inspect a PDF opened from a `blob:` URL. To judge a generated PDF, rasterize it with `gs -sDEVICE=png16m` and crop with `sips`. For RTL or shaped text, `gs -sDEVICE=txtwrite` prints the glyphs in drawn order, which settles word order where your eyes won't.
- A focus-triggered payload won't fire in an automated tab: it has no window focus, and `.focus()` from script sends no event. Click the element for real before calling it inert.
- "A server error occurred … Your submission failed" is the PWA plugin's service worker covering a failed POST, not the server's page. It re-registers on every load. It also answers a `fetch()` replay sent from a wp-admin tab, with a 200 even when the server refused it, so replay forms with curl. To find the cause, read it server-side: a temporary mu-plugin that logs through `wp_die_handler` and a shutdown function. A `wp_die()` exits 500 and writes nothing to `debug.log`.
- A protected wcpt field rendered `disabled` posts nothing, while the region dropdown posts its stored value. Any check that reads `$_POST` for a protected field (the scheduled-status check does) sees it empty for non-wranglers. Locally, `contributorrole` (6) is no mentor fixture: `.docker/wp-config.php` gives it every wrangler subrole through `$wcorg_subroles`, so its saves pass for the wrong reason. Use `authorrole` (5), added to Central as a contributor, and remove it afterwards. Before #2167 a mentor can't save on Central at all (#2159). Dropping `post_author` from a replayed form does get past core's check, but core then saves the mentor as the author, so it isn't the request a mentor's browser sends.
- Screenshot anything you intend to assert. A finding with a picture survives the round trip.

## Tests

- Break the code. Watch the test fail. Every time.
- A test that cannot fail is not a test.
- A test can pass for a reason you did not intend. Mutate the exact line you think it pins.
- Confirm the mutation landed before trusting a green run. Count the changed lines in `git diff`; a `sed` that misses a quote changes nothing and "passes".
- Beware tests that do the work themselves. Drive the real trigger, then read the result back.
- A filter the code adds only so tests can inject data (`…_groups`, `…_count` returning non-null) skips the real source. Run the real path once in the container. #2175's suite was green while its WordCamp count fatalled: `get_wordcamps()` assigns `->meta` to every result, so `'fields' => 'ids'` dies on an int.
- A test that registers the very callback it asserts was removed proves the `remove_action` argument matches itself, not the vendor. Go read the vendor's `add_action` for the hook, callable and priority.
- A test that calls a filter callback directly cannot catch a wrong hook name. Unhook it and rerun. If the suite stays green, prove the hook fires with a real request.
- Exercise the edges the PR claims in prose. Those are the wrong ones most often.
- New branch, no test, is a finding. Say so even when the branch works.

## Fixtures

- Suspect your fixtures before the code.
- Theme pattern files are cached by theme `Version` for 30 minutes. After switching branches, run `wp_get_theme()->delete_pattern_cache()` or a new pattern silently renders nothing. The same holds in production: a PR that adds a pattern file without bumping `Version` ships it late.
- A direct write earlier in the session can fake a bug no code path produces.
- Check the fixture can tell the cases apart. Identical fixtures make a test vacuous.
- Seed the data the edge case needs. Absent data passes every assertion.
- No local camp has sessions. For Speaker Feedback, seed a published `wcb_session` with `_wcpt_session_type=session` and a past `_wcpt_session_time`; the form is at `…/session/<slug>/feedback/` and saved answers wait in moderation until approved.
- Logo and featured-image sideloads from Central fail locally with cURL error 60 (self-signed cert), so a missing thumbnail on a camp site proves nothing. Re-run the WP-CLI command with `--require` a file that calls `WP_CLI::add_wp_hook( 'https_ssl_verify', '__return_false' )` (plain `add_filter` isn't loaded yet at `--require` time).
- Seattle and Shiny New 2023 point at a missing `twentytwenty` theme, so the page body renders empty. Set `template`/`stylesheet` to `campsite-2017` and put it back after.
- `wp-config.php` globals (`$trusted_deputies`, `$wcorg_subroles`) aren't global under WP-CLI, so `user_can( 6, 'wcb_approve_budget' )` says no there. Judge deputy access in the browser.
- CampTix's meta log is empty locally. To read the log lines (and mail sends) of a web request, a throwaway mu-plugin can append `camptix_log_raw` and `wp_mail` to a file in the container; `chmod 666` it, or php-fpm can't write a file WP-CLI created.
- MES decides "organizer edited this copy" by `post_modified !== post_date`, and core resets a draft's `post_date` on every save. Edits to a draft copy, or made in the save that publishes it, look untouched and get overwritten by Push to Active Camps. Seed an organizer edit on a draft copy before trusting any "skips edited copies" claim.
- The attendee Status dropdown on Edit Attendee renders only for `manage_sites`. A camp organizer refunds through the front-end Refund Request link, so test both paths when a change hooks attendee status.
- Seed through the real API, then read it back. GatherPress drops what the event or site forbids: group sites force anonymous RSVPs off, so a saved "anonymous" RSVP is an ordinary one.
- Publishing a seeded event emails every group member "New event". Harmless locally, but seed as a draft first if the mail log matters to the review.
- A local CampTix checkout needs four things the camps don't have. A published ticket and a `[camptix]` page. The central WordCamp post must not be `wcpt-closed` (Seattle 2023's is). Reopening it doesn't stick for long: the `wcpt_close_wordcamps_after_event` cron closes past camps again on the next WP load that runs cron, so check `wp cron event list` for when it next runs. At least one payment method must be enabled, even for a free ticket. And it has to get past Spam_Prevention: "could not be processed" means the hidden `…-tos-required` honeypot was ticked, or the form was sent less than 2s after it rendered. For a curl checkout, send `camptix-fsp-attendee-info-dob-required=<unix time a few seconds ago>` (that's the timestamp field, despite the name) and leave the honeypot out. Failed tries also add to a per-IP score in `form-spam-prevention-*` transients, so clear those before retrying. Reopening the central post with `wp post update --post_status=wcpt-scheduled` writes no status log and sends no mail; put it back to `wcpt-closed` the same way.
- Past the checkout, a Stripe order needs no test keys. Seed the draft attendees, call `record_checkout_session()`, and stub `api.stripe.com` with a throwaway mu-plugin on `pre_http_request` that answers from an option, so each case is one option write. Then drive `?tix_action=payment_return|payment_cancel&tix_payment_token=…&tix_payment_method=stripe` as a logged-in buyer (camps require a login) and run the real `wp cron event run tix_scheduled_every_ten_minutes`. Seed alphanumeric `tix_access_token`s: CampTix rejects anything `ctype_alnum()` refuses, and an underscore looks like a broken redirect. `camptix-invoices` is per-camp and inactive locally, and invoice PDFs land in `blogs.dir/<id>/files/camptix-invoices/`. Paying a seeded order bumps `camptix_stats` and `invoice_current_number`; put both back. Any attendee status change moves `camptix_stats` too, and seeding then refunding doesn't cancel out (`wp_insert_post` as `publish` counts before the price meta exists, and `remaining` clamps at 0), so save the whole option before seeding and write it back. To refund a seeded order, the camp also needs an enabled payment method, or the form falls back to the ticket list.
- Require Login ties each attendee to one account, so the buyer's session can't open another attendee's `tix_edit_token` link (`require_login_edit_attendee_duplicate_username`). To see what attendee 2 sees, open their link as a second user.
- Seattle 2023's Tickets page (ID 13) is a draft holding organizer copy. Publishing it with `post_content => '[camptix]'` wipes that copy and its date, and no revision keeps the original. Append the shortcode instead, or restore the row from `.docker/data/wordcamp_dev.sql`, the stack's seed dump.
- Some paths can't run locally, so don't read silence as a pass. `WCOR_Mailer::mail()` fatals under WP-CLI because it needs the admin-only `$wordcamp_admin` global, so drive WCOR triggers from wp-admin. Docs, invoice and visa-letter PDFs render through DomPDF and do work locally (the image's `wkhtmltopdf` is dead weight that can't start on arm64). To check one without a download, replay the Docs steps with `fetch()` in the wp-admin tab and read the response's `%PDF-` header, `/Producer` and page count. `wordcamp-qbo` isn't active, so the sponsor-invoice approve button can't run.
- Name fixtures so core's 404 slug guessing can't match them. A post called `flyer-review-…` turned every unrouted `…/flyer/` into a 301 to it.
- On any route that takes a `recurrence_id`, send one that doesn't match the target RSVP's own date. Gates read the request's date, not the object's, and while that context is set `get_comments()` only returns that date's RSVPs, so derived state goes silently wrong too.
- Then send none at all on a series. With no date in context the series reads as one event: lists merge every date's RSVPs and time gates read the series start, so a future date's RSVP passes a gate the mismatched-date case was fixed for.
- With an occurrence in context, `get_permalink()` on its series already returns `…/{date}/` (`Context::post_link()`). Code that appends the date to it, or to a URL built from it, doubles the date and 404s. Build from `Context::series_url()` and check the links an occurrence page prints, not just the series page's.
- Any new surface that renders event content or an auto-excerpt needs a recurring series. The recurring-events plugin prepends its occurrence selector through `the_content`, so an excerpt becomes a list of dates. Sunshine Coast has `weekly-community-standup` with dated `…/{Ymd}T{His}/` URLs.
- The events.wordpress.org lists read the `wporg_events` table, and the local copy holds nothing after January 2024. Seed upcoming rows with high ids and delete them afterwards. Its Google Map code is the gitignored `mu-plugins-private/wporg-mu-plugins/pub-sync/` copy, not a `wporg-mu-plugins` checkout, so a paired mu-plugins PR is tested by patching that copy and restoring it from a backup; `git status` will not show it. Any new key in the facets passed to `get_events()` counts toward `is_cacheable()`'s one-facet limit, so check that a single-filter page still caches.
- `wporg_events` rows reach the theme as fake `WP_Post`s from `inject_events_into_query()`: no date, no content, an invented ID. Any feed or template that renders them through core gets `/?p=<id>` links that 404 and an empty `pubDate` unless it overrides them, the way #1561's feed filters do. Pretty filter URLs join values with `-`, and only a lone `in-person` is special-cased, so a facet that can now hold two values parses `in-person-online` as three. Check the dropdown's ticked state on the redirected URL, not just the results.

## Findings

- Test your doubts before reporting them.
- An unverified concern costs the author more than it costs you.
- Rank by what it does to a user. Say which are blockers.
- Withdraw a nit when it turns out wrong. Say why.
- A cleanup that makes the code worse is not a finding.
- Say plainly what you did not check.

## Security reviews

- Attack the claim, not the diff. The sentence saying what the fix closes is the thing to test.
- Review the whole branch. A fix that survived three rounds had three chances to quietly drop an item.
- "Addressed" is a claim too. Re-check every earlier finding against the current head yourself.
- Ask what the fix leaves behind. "Self-heals on the next sign-in" is worthless where nobody signs in again.
- Size it against the real population before you rate it. Dormant data does not heal.
- Removing a bad control removes its side effects. Name the one that was quietly doing real work.
- Diff the failure mode, not just the success path. A branch can fatal where trunk degraded.
- A guard on `add_option_*`/`update_option_*` polices transitions, never state. Core drops a write of the value already stored, so the hook never fires and a site already sitting in the bad state stays there. Ask whether the fix self-enforces or needs a one-off sweep, then judge the sweep as part of the fix.
- Every superglobal is attacker-typed. `isset` and `! empty` do not make it a string.
- A capability grant keyed on `$_POST` fires on every request that carries the field, not just the form it was written for. PHP fills `$_POST` for a form-encoded REST call too, and REST names fields differently (`author`, not `post_author`), so an "author unchanged" check reads nothing there. Replay the grant against `/wp/v2/<rest_base>/<id>` with the field added; `wordcamp` is `show_in_rest`. A check that re-reads a field core also reads must use core's precedence and cast, or it checks a different value from the one saved. Core takes `post_author_override` only when `! empty()` and casts with `(int)`; `?? ` plus `absint()` passes `post_author_override=` (empty), `0`, or `post_author=-1`, and core then saves another author or author 0.
- A check in PHP and a comparison in MySQL are two different checks. Collations are `_ci`.
- Format is not entropy. Case folding is nothing to a random token and fatal to a fixed-format one.
- Follow the cookie scope. A network domain turns a per-site bug into a network one.
- Coming Soon gates `template_include` and local REST, and nothing else. It is not a privacy signal: `wcorg_enforce_public_blog_option()` pins `blog_public` to `1` on read and write, so WordPress.com treats every gated camp as public. Feeds, sitemaps and the WP.com Search index all answer anonymously. Check the surface you are reviewing against that list.
- Fix the class, not the instance. A patch that opens a new false-positive class is not the fix.
- A regex over translated output only matches English. Run it under another locale's `.po` (French puts a space before the colon). Build the needle from the same `__()` call instead.
- A password gate on a block is not a gate on the data. For anything an event or session is linked to (venue, speakers, topics, language), check every surface that names it: `post_class`, the REST `class_list`, term field and term link, `?<taxonomy>=` filters, the `?post=` terms route, the term archive and its feed, the RSS excerpt (GatherPress prepends the venue), `<head>` feed links, and the reverse link from the other object.
- A swapped template is not a hidden page. `template_include` changes the body, but core's `wp_head` and headers still describe the resolved query: `rel=canonical`, `shortlink`, oEmbed discovery, the REST `alternate` link, and the `Link:` headers all print the permalink. Stopping a redirect hides nothing if the page it lands on prints the URL. Old-slug redirects run outside `redirect_canonical`.
- Residual risk is accepted out loud or it is not accepted.

## Proving it

- Prove the bypass. A plausible bypass is not a finding.
- Run it in the container, at the version that ships.
- Code on Central that `switch_to_blog()`s into a camp site still has Central's registrations. `wc-post-types` returns early on Central, so `wcb_*` taxonomies don't exist there and `wp_insert_term`/`wp_set_object_terms` return `invalid_taxonomy` silently. PHPUnit registers them globally, so the suite can't see it. Run the real command, then read the terms back on the camp site.
- Query the real schema. Collation, charset, and casts belong to the table, not to your memory.
- Read core before reporting core behavior. Half of what you remember about it is one version stale.
- `term_exists( $string, $tax )` tries the slug before the name, and `wp_insert_term()` refuses only a duplicate name (it suffixes a clashing slug). A "name already taken" check built on `term_exists()` also fires on a slug match. Work out the slug with `sanitize_title()` before seeding the case; don't guess it.
- Two filters on one hook at one priority are decided by load order. `wp-settings.php` line numbers settle it; mu-plugins run before core registers its own late hooks.
- The local stack's opcache rechecks files every 2 seconds (`opcache.revalidate_freq=2`). After swapping a file to compare old and new code, wait a few seconds before the request, or you're testing the file you just replaced. A "the old code doesn't reproduce it either" result straight after a swap is this, until shown otherwise.
- Before crediting a fix for a visible change, turn it off and reload. `wordcamporg` is loaded against `get_user_locale()` on `plugins_loaded`, so its strings follow the visitor whatever `determine_locale()` says.
- A text-domain rename conflicts only where the two branches touched the same line. Grep the merge result for the retired domain: a string the other branch added elsewhere merges clean and ships untranslatable.
- Bot reviewers read our comments, not the plugin. Check their claims about GatherPress against the version that ships. Our comments go stale when upstream changes.
- Two of your best findings will die on inspection. Kill them yourself, and write down that you did.
- Report the side effect that helps, not only the one that hurts.

## Disclosure

- Full detail goes in the private issue. Never in the public PR.
- Never name or link the security repo from anything public.
- Public text is an ordinary defect report. No payloads, no attack framing, no reporter.
- Say enough publicly for the author to fix it, not enough for a reader to reproduce it.
- Judge exposure against what is deployed, not against trunk.
- Split what the branch closes from what it leaves open, and write both down.

## Batches

- Good PRs can still be a bad merge.
- Check every pair for conflicts before anyone merges.
- Check how far behind `production` each branch is.
- A fix can be complete only together with another open PR. Merge them locally, re-run the repro on the result, and say they ship as a pair.
- Conflict resolution is review work.
- "Keep both" can be syntactically wrong. Adjacent additions often share a closing brace.
- A conflict can offer two whole lines, each carrying a different shipped feature. Taking either reverts one.
- Lint and run suites on the merge result, not just the branches.
- After the last merge, check the features compose, not just coexist. This includes features already shipped: anything that says "attended" must follow check-ins on a date that has them (`Check_In\event_has_check_ins`), not RSVPs.

## Afterwards

- Reviews mutate local state. Write down what you touch, put it back.
- A theme switch is not undone by switching back. `wp theme activate` moves widgets to inactive, snapshots them into the old theme's `theme_mods_*`, and creates the new theme's mods. Restore `sidebars_widgets` from that snapshot, drop the snapshot and the new mods row. A theme outside WP-CLI's theme roots (Seattle 2023's `twentytwenty`) won't activate, so set `template` and `stylesheet` directly. Setting them directly still leaves a trace: the first render writes `theme_mods_<new theme>` (`custom_css_post_id` = -1), so delete that row too.
- The next review assumes the fixtures are honest.

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

# Log in without typing a password. Mint the cookie, then set it in the browser.
# The name is `wordpress_logged_in_` + COOKIEHASH: empty on the events network,
# hashed on wordcamp.test camps (`wp eval 'echo LOGGED_IN_COOKIE;'`). An old session cookie on a
# narrower path wins over one set on `/`, so expire those first and check who
# the toolbar says you are. Over HTTPS core reads `wordpress_sec_` before the
# logged_in cookie, so a stray one on a broad path wins on the front end too.
# That gets you the front end only: wp-admin bounces
# to wp-login until you also mint "secure_auth" and set it as SECURE_AUTH_COOKIE
# on path=/wp-admin (document.cookie can set a narrower path)
docker compose exec -T wordcamp.test wp eval \
  'echo wp_generate_auth_cookie( <user_id>, time() + 3600, "logged_in" );' --url=<site>

# What the server actually sends. Flags, headers and body, with no browser in the way
curl -ksi -b 'tix_view_token=<value>' https://<site>/<path> | head -40

# Check what the database actually does. Collation decides comparisons, not the code
docker compose exec -T wordcamp.db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -t \
  wordcamp_dev -e "<query>"'
```

- Reproduce on the local multisite. Never against production.
- CI fails any run that writes to PHP's error log, deprecations included, even with every test green. Locally they never show: the container has `log_errors` off and the generated `wp-tests-config.php` doesn't define `WP_DEBUG`, so WordPress drops `E_DEPRECATED`. Reproduce with `php -d log_errors=On -d auto_prepend_file=<file defining WP_DEBUG true> $(command -v phpunit) …` and read the "Unexpected entries" block. Ignore `WP_TESTS_MULTISITE already defined`; that one is local only. A `WordCamp\Logger\log()` call counts as unexpected too when its code has a space in it: the bootstrap only accepts `function:code` with a slug code. A new log line on a shared path (a WCOR trigger, a status transition) fires in other plugins' suites, so run the full suite this way before merging, not only the suite the PR touches (#1748 broke `production` that way).
- If another stack holds 80/443, don't stop it. Put `WORDCAMP_HTTP_PORT=8080` and `WORDCAMP_HTTPS_PORT=8443` in a gitignored `.env`, browse `https://…:8443/`, and delete `.env` afterwards.
- Squash merge only. One commit per PR, `(#NNNN)` in the subject.
- Never force push a pushed branch. Follow-up commits only.
