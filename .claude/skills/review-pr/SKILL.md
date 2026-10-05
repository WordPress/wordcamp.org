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
- "Covered by tests" means go and find the test.
- A green CI run says the suite passed, not that the suite covers this.

## Reproducing

- Reproduce the reported symptom before reading the fix.
- Use the path a person takes, not the one the fix makes convenient.
- A fix can be correct, tested, passing, and leave the bug in place.
- Assert on the surface that broke, not on the helper behind it.
- Check the fix at the layer the user sees. Data being right is not the page being right.
- Camps come in classic and block themes, and the local stack may only have one. Block-theme camps render `mu-plugins/theme-templates/block-templates/` around the post, so blocks there (session speakers, speaker sessions) sit outside the password form. To check, switch a camp to `twentytwentyfour` and put it back (see Afterwards).

## In the browser

- A green suite proves functions, not pages. Drive the real UI with `claude-in-chrome` against the local stack.
- The browser shows what a person sees. A raw request shows what the server sends. An attacker sends raw requests, so read both.
- Cookie flags, response headers and delivered markup are read off the response, never off the source.
- Walk it as every actor the change touches. Log out between them.
- Anonymous is an actor. Gated-content findings live there more often than anywhere else.
- Malformed input goes in the request, not the form. A field the page will not let you type into is not a field an attacker cannot send.
- Check who answers the URL first. Another local stack can hold 443; `curl -ksi` and read the headers. The WordCamp container is also on `:8443`.
- Block assets ship with a fixed `?ver=1.0.0`. After a rebuild, hard-reload (`cmd+shift+r`) before judging any styling, or you are looking at the old CSS.
- A 403 or 400 from a raw REST call is not yet the permission check. No nonce gives `rest_cookie_invalid_nonce`; get one from `admin-ajax.php?action=rest-nonce`. Arg validation runs before `permission_callback`, so send a full valid payload and look for `rest_forbidden`.
- `resize_window` can silently not apply. For widths, load the page in a same-origin iframe of that width and measure it there.
- Prefer `find` and click by ref. Coordinates go stale when the viewport moves under you.
- A click on a button below the fold can just scroll it into view. Screenshot before deciding a modal button does nothing, and click again.
- Refs go stale too when React re-mounts a control. Re-`find` right before each `form_input`.
- Login submit can silently no-op here. Press `Return` in the password field, then confirm on the toolbar before trusting anything after it.
- Never click a `window.confirm()` button. It blocks the page and automation cannot answer the dialog. Submit the form directly instead. The event modal's Cancel is one once a field has changed; if a tab freezes, close it with `tabs_close_mcp`.
- Decode a QR code in the page with Chrome's `BarcodeDetector` (draw the SVG to a canvas first). Asserting on `data-url` only proves the input, not the code.
- A print claim ("fits one page") is checked with headless Chrome's `--print-to-pdf`, then count the PDF's pages. It can hang after writing the file; kill it.
- Screenshot anything you intend to assert. A finding with a picture survives the round trip.

## Tests

- Break the code. Watch the test fail. Every time.
- A test that cannot fail is not a test.
- A test can pass for a reason you did not intend. Mutate the exact line you think it pins.
- Confirm the mutation landed before trusting a green run. Count the changed lines in `git diff`; a `sed` that misses a quote changes nothing and "passes".
- Beware tests that do the work themselves. Drive the real trigger, then read the result back.
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
- Seed through the real API, then read it back. GatherPress drops what the event or site forbids: group sites force anonymous RSVPs off, so a saved "anonymous" RSVP is an ordinary one.
- Publishing a seeded event emails every group member "New event". Harmless locally, but seed as a draft first if the mail log matters to the review.
- Name fixtures so core's 404 slug guessing can't match them. A post called `flyer-review-…` turned every unrouted `…/flyer/` into a 301 to it.
- On any route that takes a `recurrence_id`, send one that doesn't match the target RSVP's own date. Gates read the request's date, not the object's, and while that context is set `get_comments()` only returns that date's RSVPs, so derived state goes silently wrong too.
- Then send none at all on a series. With no date in context the series reads as one event: lists merge every date's RSVPs and time gates read the series start, so a future date's RSVP passes a gate the mismatched-date case was fixed for.
- Any new surface that renders event content or an auto-excerpt needs a recurring series. The recurring-events plugin prepends its occurrence selector through `the_content`, so an excerpt becomes a list of dates. Sunshine Coast has `weekly-community-standup` with dated `…/{Ymd}T{His}/` URLs.

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
- Query the real schema. Collation, charset, and casts belong to the table, not to your memory.
- Read core before reporting core behavior. Half of what you remember about it is one version stale.
- Two filters on one hook at one priority are decided by load order. `wp-settings.php` line numbers settle it; mu-plugins run before core registers its own late hooks.
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
- A theme switch is not undone by switching back. `wp theme activate` moves widgets to inactive, snapshots them into the old theme's `theme_mods_*`, and creates the new theme's mods. Restore `sidebars_widgets` from that snapshot, drop the snapshot and the new mods row. A theme outside WP-CLI's theme roots (Seattle 2023's `twentytwenty`) won't activate, so set `template` and `stylesheet` directly.
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
# The name is `wordpress_logged_in_` (empty hash). An old session cookie on a
# narrower path wins over one set on `/`, so expire those first and check who
# the toolbar says you are
docker compose exec -T wordcamp.test wp eval \
  'echo wp_generate_auth_cookie( <user_id>, time() + 3600, "logged_in" );' --url=<site>

# What the server actually sends. Flags, headers and body, with no browser in the way
curl -ksi -b 'tix_view_token=<value>' https://<site>/<path> | head -40

# Check what the database actually does. Collation decides comparisons, not the code
docker compose exec -T wordcamp.db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -t \
  wordcamp_dev -e "<query>"'
```

- Reproduce on the local multisite. Never against production.
- If another stack holds 80/443, don't stop it. Put `WORDCAMP_HTTP_PORT=8080` and `WORDCAMP_HTTPS_PORT=8443` in a gitignored `.env`, browse `https://…:8443/`, and delete `.env` afterwards.
- Squash merge only. One commit per PR, `(#NNNN)` in the subject.
- Never force push a pushed branch. Follow-up commits only.
