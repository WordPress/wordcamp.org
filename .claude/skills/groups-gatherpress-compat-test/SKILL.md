---
name: groups-gatherpress-compat-test
description: Run the Groups/GatherPress front-end integration compatibility checklist — per-role browser pass, direct REST pass, GatherPress-off pass, and security/perf sanity checks. Use after any GatherPress version bump, or any change to mu-plugins/wporg-groups-frontend, mu-plugins/groups, or the groups-site theme.
---

# Groups / GatherPress compatibility checklist

This checklist exists because GatherPress updates fairly often and its
changes can silently break backwards compatibility with our Groups
integration. It is self-contained — follow it with no other context. It
covers three plugins: `mu-plugins/wporg-groups-frontend` (front-end
event/member management), `mu-plugins/groups/gatherpress-groups-tweaks.php`
(settings/capability/query overrides), and the `groups-site` block theme.

**Keep this skill current.** This file is a living record, not a one-time
checklist — whenever a session working on this integration (a version bump,
a bug fix, a test-flakiness investigation, anything touching the plugins or
theme above) turns up an essential finding — a real root cause, a bug in
GatherPress or this integration and its fix, a test-environment gotcha, a
methodology that generalizes — add it here before the session ends, in the
section it belongs to (or a new one, if it doesn't fit an existing one).
"Essential" means: would the next person hit the same wall without it?
Prefer folding a finding into existing prose over appending a new bullet
list per session — this file should read as current best understanding, not
a changelog.

**Compact it periodically.** Growth without pruning eventually makes this
file slower to read than it's worth. Every so often (when a section is
getting long, or findings start to overlap/repeat), reread the whole file
and tighten it: merge redundant points, cut anything superseded by a later
fix or no longer true of the current code, and re-verify specifics (file
paths, function names, version numbers) against the actual repo rather than
trusting older prose. Compacting is itself worth a small dedicated pass, not
just an incidental side effect of adding one more finding.

Two automated layers exist alongside this manual checklist — run them
first, then use this checklist for what they can't cover (real browser
interaction, cross-plugin capability leaks, exploratory checks):

- **PHPUnit** — `docker compose -f docker-compose.phpunit.yml up` (or your
  usual local phpunit invocation). Covers the `WordCamp Groups` suite:
  capability logic, `gatherpress-groups-tweaks.php` option/query overrides,
  `Members_Controller` role rules, REST permission/IDOR checks, draft flow,
  block registration.
- **E2E (Playwright)** — `npx playwright test` (or trigger the
  `e2e-tests.yml` GitHub Action manually — it's `workflow_dispatch`-only).
  Covers anonymous front-page rendering, an author creating an event
  end-to-end through the real browser UI, per-event messaging, the
  auto-publish-notification email, and the member directory. Needs
  `organiser1`/`eventorganiser1`/`eventorganiser2`/`eventorganiser3`/
  `eventorganiser4`/`eventorganiser5`/`member1` (all `password`) — **every
  individual test that logs in as an author has its own dedicated
  `eventorganiser*` account, not just every spec *file*.**
  `event-manage-messaging.spec.js` alone needs three (`2`/`4`/`5`) because
  its own three tests also run concurrently against each other under
  `fullyParallel`, not just against other files. Don't consolidate any of
  these back onto a shared account.
  **If a spec times out waiting on a UI interaction, don't assume it's
  `fullyParallel` contention and reach for `--workers=1` — read the actual
  failure first.** Every Playwright failure writes an `error-context.md`
  (path is in the terminal output) with a full accessibility-tree snapshot
  of the page at the moment of timeout: read it before touching the test.
  Several real, deterministic bugs were all initially mistaken for "just
  flaky":
    - A "Choose a pattern" starter-pattern dialog appears on every fresh
      `post-new.php` visit for a post type with starter patterns
      (`gatherpress_event` has them) and — unlike the separate "Welcome to
      the editor" guide the specs already handled — its own Close button
      and Escape do **not** dismiss it. It silently sat on top of the
      sidebar, and every subsequent interaction hung until the test
      timeout. Fixed by `tests/e2e/utils/dismiss-editor-onboarding.js`,
      which every spec creating an event must call.
    - Sharing one login across concurrent tests. This environment only
      supports one active session per user (see the comment in
      `tests/e2e/utils/login.js`), so under `fullyParallel` one worker's
      fresh login can invalidate another's already-established session
      mid-test — the `error-context.md` for that failure shows the page
      stuck on a login form instead of the expected editor. `login()`'s own
      retry loop only covers a collision *during the login request itself*;
      it can't help once a later worker's login invalidates an earlier
      worker's session that already succeeded. The only real fix is one
      account per concurrently-running test, including within a single
      spec file (see above).
    - GatherPress's "Event settings" document panel (which
      `tests/e2e/utils/pin-event-far-future.js`'s "Date & time start"
      button lives inside) is a collapsible `PanelBody` whose open/closed
      state is a **preference persisted server-side per WordPress user**
      (WordPress's editor-preferences API), not freshly defaulted each
      browser session. GatherPress force-opens it once via a `domReady`
      callback that checks `core/editor`'s `isEditorPanelOpened()` — but
      that can race the preferences store's own async hydration of that
      user's saved state, and if it loses the race the panel renders
      collapsed. Once that happens for a given test account, it stays
      collapsed on every later editor load too (GatherPress never re-checks
      after that first callback), which is why this reproduced
      deterministically for whichever account had ever hit the race, not
      randomly. Fixed in `pin-event-far-future.js`: check whether the
      "Date & time start" button is visible first, and if not, click the
      "Event settings" panel header to expand it before proceeding — don't
      assume the panel is already open.
  If a timeout's `error-context.md` shows the *expected* page mid-load
  (not stuck on a login form, error page, or unrelated dialog), *then*
  it's reasonable to suspect genuine environment slowness — but confirm
  that from the evidence, don't default to it.
  **A test can also be right that something is broken in production, not
  just in the test.** The auto-publish-notification test
  (`event-publish-notification.spec.js`) intermittently found zero email
  after a real publish, with no error logged anywhere. Root cause: multiple
  events publishing within the same second — which this suite's own
  `fullyParallel` execution reliably does, and which real usage can do too
  — raced on `wp_schedule_single_event()`. WordPress core's cron store is a
  single `wp_options` row (`option_name = 'cron'`) updated via a plain,
  unsynchronized read-modify-write (`_get_cron_array()` /
  `_set_cron_array()` in `wp-includes/cron.php`, no locking); two nearly
  simultaneous callers can silently clobber each other's scheduled job at
  any point up until wp-cron actually executes it, and
  `wp_schedule_single_event()`'s own return value can't detect this since
  it only reflects the calling request's own write. A bounded retry with
  re-verification around the *scheduling* call closes the common case
  (confirmed via a dedicated PHPUnit regression test, `pre_option_cron`
  forced empty to simulate the race) but not the deeper one — a job can
  still be clobbered *after* being confirmed scheduled, by a totally
  unrelated later publish, since every `wp_schedule_single_event()` caller
  on the site shares the same unsynchronized option. The real fix
  (`schedule_new_event_notification()` in
  `mu-plugins/wporg-groups-frontend/inc/notifications.php`) bypasses
  WP-Cron for this entirely: it calls
  `\GatherPress\Core\Event\Rest_Api::get_instance()->send_emails()`
  directly and synchronously, the same method GatherPress's own
  `gatherpress_send_emails` cron handler and its "Message all members"
  REST action both call — trading a brief publish-request delay (already
  true of GatherPress's own dispatch once cron picks it up) for
  eliminating the race category outright. The send is deferred *within*
  the request, though: `transition_post_status` fires inside
  `wp_insert_post()` before the event's datetimes/venue are written
  (sending there produced "Date: —" emails), so the hook only queues the
  event and `send_pending_new_event_notifications()` sends at `shutdown`
  priority `PHP_INT_MAX` — after GatherPress's own default-priority
  shutdown datetime resolver. **Going synchronous has a real blast radius
  beyond the notification feature itself**: since `transition_post_status`
  now runs on *every* `gatherpress_event` publish, any PHPUnit test that
  publishes one — not just tests about notifications — would execute real
  email-template rendering when the queue drains, which needs runtime
  (theme template functions, etc.) most test bootstraps don't load.
  `Groups_TestCase::setUp()` removes this action for every test by
  default; `Test_Groups_Notifications` is the one class that re-adds it
  (and drains the queue explicitly), for its own coverage. Keep this in
  mind before converting any other deferred-to-cron integration point to
  a synchronous call — check whether other tests publish through the same
  hook first.

If either automated suite fails, stop and fix that before doing the manual
pass below — don't duplicate debugging effort across layers.

## 0. Before bumping the pinned version

- **Confirm the target version is actually stable**, not a beta/RC:
  `curl -s https://api.wordpress.org/plugins/info/1.0/gatherpress.json | python3 -c "import json,sys; print(json.load(sys.stdin)['version'])"`.
- **Diff GatherPress core itself** for breaking API changes before touching
  any code here. Clone/pull `github.com/GatherPress/gatherpress` locally and
  run `git diff <old-tag> <new-tag>` (and `git log <old-tag>..<new-tag>
  --oneline` for the human-readable summary). Specifically check every class
  this integration touches directly —
  `GatherPress\Core\{Rsvp\Rsvp,Event\Event,Venue\Venue,Venue\Setup,User,Setup,Blocks\Setup,Blocks\Event_Query}`
  (grep `GatherPress\\\\` across `mu-plugins/groups`,
  `mu-plugins/wporg-groups-frontend`, and `themes/groups-site` to get the
  current list) — for renamed/removed constants, changed return types
  (`Rsvp::get()` went from always-`array` to `array|null` in 0.35.0 and broke
  a test that indexed it directly), and classes marked `final` (harmless
  unless something here extends one — check with
  `grep -rn "extends.*GatherPress"`).
  **Checking that a class still exists is not enough** — verify every
  individual method/constant/property call site, not just the class. A
  wrong-but-existing class import (`Blocks\Event_Query` vs. the real
  `Event\Query`) passed every "does this class exist" check across two
  GatherPress versions and only surfaced as a live production fatal,
  because nothing had verified the *specific methods called* actually
  existed on *that specific class*. Section 8 below has the exhaustive,
  mechanical version of this check — run it now too, not just after
  landing, since catching this before the bump is cheaper than catching it
  in production.
- **Grep theme templates for GatherPress block usage**, not just PHP:
  `grep -rn "wp:gatherpress" public_html/wp-content/themes/groups-site`.
  GatherPress Alpha's migration (see below) only rewrites block content
  *stored in the database* — it does not touch theme `.html` template
  files. If a version bump removes/renames a block GatherPress itself used
  to ship (e.g. `gatherpress/icon` → core `core/icon` in 0.35.0), any
  in-repo template using it needs a manual, matching edit or it silently
  renders blank.
- **Find every place the version string is pinned** — there is no single
  source of truth, so grep for the old version across the whole repo before
  declaring the bump done:
  `grep -rln "<old-version>" . --include="*.yml" --include="*.php" --include="*.md" --include="*.sh" | grep -v "plugins/gatherpress"`.
  As of the 0.35.0 bump these were: `.github/workflows/e2e-tests.yml`,
  `.github/workflows/unit-tests.yml`, `.docker/bin/install-test-suite.sh`
  (which was *already* stale at a different, older version than the two
  CI workflows — don't assume they're in sync), and this skill's own
  install command below.

## 1. Environment setup

```bash
# Checkout & build
npm ci
npm run build --workspace=public_html/wp-content/mu-plugins/wporg-groups-frontend

# Confirm the dev stack is up
docker compose ps   # expect wordcamp.test + wordcamp.db running

# Confirm GatherPress is installed & active on the groups site (gitignored,
# not committed — install manually if missing):
docker compose exec wordcamp.test wp plugin list --url=events.wordpress.test/group/sunshine-coast-qld/ | grep gatherpress
# If missing/inactive:
docker compose exec wordcamp.test wp plugin install \
  https://github.com/GatherPress/gatherpress/releases/download/0.35.0/gatherpress.0.35.0.zip \
  --activate --url=events.wordpress.test/group/sunshine-coast-qld/
```

**After bumping the pinned GatherPress version, also install/update
[GatherPress Alpha](https://github.com/GatherPress/gatherpress-alpha)
(version-locked to core) and run its one-time migration.** GatherPress ships
breaking *content* migrations (old `gatherpress/icon` block usage, old
`gatherpress/venue-map` width/height attributes, renamed RSVP settings
values) through this companion plugin rather than an automatic upgrade
routine. Skipping this step is exactly what it looks like when a venue's
address/phone/website/map silently vanish from the front end after a version
bump — it reads like a GatherPress regression but is actually just unmigrated
data:

```bash
docker compose exec wordcamp.test wp plugin install \
  https://github.com/GatherPress/gatherpress-alpha/releases/download/0.35.0/gatherpress-alpha.0.35.0.zip \
  --activate --url=events.wordpress.test/group/sunshine-coast-qld/
docker compose exec wordcamp.test wp gatherpress alpha fix --url=events.wordpress.test/group/sunshine-coast-qld/
```

Create one test user per role tier, with **both** a browser login password
and a REST application password (the browser pass needs the former, the
REST pass needs the latter):

```bash
for pair in "organiser1:editor" "eventorganiser1:author" "member1:subscriber"; do
  u="${pair%%:*}"; role="${pair##*:}"
  docker compose exec wordcamp.test wp user create "$u" "$u@example.test" \
    --role="$role" --user_pass=password \
    --url=events.wordpress.test/group/sunshine-coast-qld/
  docker compose exec wordcamp.test wp user application-password create "$u" "compat-test" --porcelain \
    --url=events.wordpress.test/group/sunshine-coast-qld/
done
```

Save the printed application passwords, then:

```bash
ORG="organiser1:<app-password>"       # editor tier — "Organiser"
AUTHOR="eventorganiser1:<app-password>" # author tier — "Event Organiser"
MEM="member1:<app-password>"          # subscriber tier — "Member"
BASE="https://events.wordpress.test/group/sunshine-coast-qld"
```

## 2. Direct REST pass (bypass the UI)

Namespace `wporg-groups/v1` should expose these routes — confirm the full
list first:

```bash
curl -sk "$BASE/wp-json/wporg-groups/v1" | python3 -c "
import json, sys
d = json.load(sys.stdin)
for r in sorted(d['routes'].keys()): print(r)
"
```

Expect: `event-form-data`, `event`, `event/{id}`, `drafts`, `draft`,
`draft/{id}`, `draft/{id}/publish`, `members`, `members/{id}`,
`members/{id}/role`, `members/join`, `members/leave` (plus the namespace
root).

Checks (run as anonymous + each role where relevant):

- [ ] **`GET /event-form-data` as author** → 200, populated `fields` +
  `venues`.
- [ ] **`POST /event` as editor** → creates a published event, 200 with
  `{id, permalink, title}`.
- [ ] **Overnight rollover** — `date=2026-08-20 time_start=22:00 time_end=01:00`
  → `gatherpress_datetime_end` meta on the created post is the *next*
  calendar day, not rejected as zero-length:
  ```bash
  docker compose exec wordcamp.test wp post meta get <id> gatherpress_datetime_end --url=$BASE
  ```
- [ ] **IDOR — author cannot hijack another author's event**:
  `POST /event/<other_authors_event_id>` as `$AUTHOR` → 403 `rest_forbidden`,
  original title unchanged.
- [ ] **Author CAN edit their own event** → 200.
- [ ] **`GET /members`** — public, paginated (`per_page` default 100, max
  250 via `Members_Controller::MAX_PER_PAGE`) — confirm `X-WP-Total`/
  `X-WP-TotalPages` headers present and a `per_page=9999` request is capped,
  not unbounded. Each member has `role`/`roleLabel`
  (`editor→Organiser`, `author→Event Organiser`, `subscriber→Member`).
- [ ] **Editor promotes subscriber → author**:
  `POST /members/<member1_id>/role -d role=author` as `$ORG` → 200; confirm
  via `wp user get <id> --field=roles`.
- [ ] **Author blocked from role changes** → 403 `rest_cannot_edit_roles`
  (author passes `current_user_can_manage_events()` but fails
  `current_user_can_manage_group_settings()` — both are required).
- [ ] **Editor blocked from `role=administrator`** → 400 `rest_invalid_param`
  (rejected by `ASSIGNABLE_ROLES` at the schema/args layer, before the
  permission callback even runs).
- [ ] **Can't change your own role** → 403 `cannot_change_own_role`.
- [ ] **Can't demote the last organiser** — with only one editor/admin on
  the site, attempting to demote them → 403 `cannot_remove_last_organizer`.
- [ ] **Draft flow** — `POST /draft` (save) → `GET /drafts` (list) →
  `POST /draft/{id}` (update) → `POST /draft/{id}/publish`; confirm
  `post_status` transitions draft → publish via `wp post get <id> --field=post_status`.
- [ ] **`/members/leave` + `/members/join` round-trip** as `$MEM` — both
  return `{"success":true}` (join includes `memberCount`); confirm nonce
  header on the request is non-empty (this is the exact failure mode of
  #1804 — a front-end-only bug, but worth confirming the REST layer itself
  works when hit directly with a valid nonce/app-password).

## 3. Per-role browser pass

Log in as each role in turn (`$BASE/wp-login.php`) and walk the actual UI —
don't just check curl responses render correctly, click through:

- **Event create/edit** — via the front-end modal (not wp-admin). Confirm
  the venue picker, date/time fields, and featured image work.
- **Also open the block editor for a `gatherpress_event` post in wp-admin**
  (not just the front-end modal) and check the browser console / PHP error
  log for fatals. WordPress enumerates *every* registered block pattern —
  including `Inserter: no` ones never placed in any template — via
  `/wp/v2/block-patterns/patterns` on every block-editor page load,
  executing each pattern's PHP to build the response. A pattern that's
  broken but unused on the front end (wrong query, undefined method, etc.)
  only surfaces here. This is exactly how a pre-existing bug (three
  `groups-site` event-cards patterns importing the wrong GatherPress class —
  fixed in #1874) went undetected through multiple GatherPress versions
  until a real production rollout finally hit it.
- **RSVP** — as Member and as Event Organiser.
- **Group settings tabs** (Events / Venues / Members / Design / About) —
  confirm each tab loads without a console error or 403, and that
  Organiser-only tabs are entirely absent (not just disabled) for lower
  roles.
- **Member directory** (`/members/`) — role labels correct, join/leave
  buttons behave. If this 404s, check `wp post list --post_type=page` for a
  page with slug `members` before assuming it's broken — the theme resolves
  it via the `page-members.html` template, so it needs an actual WP page
  with that slug to exist; a fresh/reset dev site may simply be missing it
  (`wp post create --post_type=page --post_title=Members --post_name=members
  --post_status=publish`).
- **wp-admin, not just the front end** — capability leaks (like the fixed
  `promote_users` escalation) only show up here. Log in as each non-admin
  role and check: can they reach Users → a role-promotion UI beyond what
  the front-end allows? Can they reach Site Editor (should be yes for
  editors, via the `edit_theme_options` grant — confirm that grant doesn't
  also unlock anything broader)?
- **Anonymous (logged out)** — public pages render; every interactive
  action (RSVP, join, edit) redirects to login rather than silently
  failing or 403ing with no feedback.

Explicitly note what's *absent* for each lower role, not just what's
disabled — a Member should see **no** event-management UI rendered at all,
not a greyed-out button.

## 4. GatherPress-deactivated pass

**`wp plugin deactivate gatherpress` does NOT work in this environment** —
`mu-plugins/wcorg-network-plugin-control.php` force-activates
`gatherpress/gatherpress.php` on every request for the groups network
(`GROUPS_NETWORK_ID`), silently re-activating it immediately after
deactivation. Verified: `wp plugin deactivate gatherpress --network` reports
success, but the plugin's REST routes/classes are still live on the very
next request. To genuinely test with GatherPress absent, remove the plugin
files instead:

```bash
docker compose exec wordcamp.test mv wp-content/plugins/gatherpress wp-content/plugins/gatherpress.disabled
# ... run the checks below ...
docker compose exec wordcamp.test mv wp-content/plugins/gatherpress.disabled wp-content/plugins/gatherpress
```

- [ ] Visit every groups-network page (front page, single event, single
  venue, `/members/`) and wp-admin — confirm **no PHP fatals** anywhere in
  the network, not just the groups site.
- [ ] Grep both plugins for every GatherPress class/function reference and
  confirm each call site is guarded:
  ```bash
  grep -rn "GatherPress\\\\Core\|gatherpress_" \
    public_html/wp-content/mu-plugins/wporg-groups-frontend \
    public_html/wp-content/mu-plugins/groups \
    --include="*.php" | grep -v "class_exists\|function_exists"
  ```
  Any hit here that isn't inside a code path already gated by an earlier
  `class_exists()`/`function_exists()` check (e.g. the plugin-level guard
  in `wporg-groups-frontend.php`'s bootstrap, or
  `gatherpress-groups-tweaks.php`'s per-function guards) is a gap.
- [ ] Reactivate GatherPress and confirm everything returns to normal
  before continuing.

## 5. Security-focused pass

- [ ] Enumerate every `user_has_cap`/`map_meta_cap` filter across both
  plugins:
  ```bash
  grep -rn "user_has_cap\|map_meta_cap" \
    public_html/wp-content/mu-plugins/wporg-groups-frontend \
    public_html/wp-content/mu-plugins/groups --include="*.php"
  ```
  For each, confirm it grants only a plugin-scoped or clearly-intentional
  capability (e.g. `edit_theme_options` for editors, for Site Editor
  access) — and never an unscoped core capability reachable from stock
  wp-admin without an equivalent plugin-level ceiling. This is the exact
  shape of the fixed privilege-escalation bug (editors were granted
  `promote_users`, which worked correctly through this plugin's own REST
  route but also silently unlocked stock wp-admin's user-role screen).
  Regression-check it explicitly: `wp eval 'var_dump( user_can( <editor_id>, "promote_users" ) );' --url=$BASE` → must be `false`.
- [ ] `GET /members` is intentionally public (no auth) — this is a known,
  tracked product/privacy question (see the repo's open issue tracker for
  the current decision), not an oversight. Confirm the fields returned
  match whatever the current decision is (as of writing: name, avatar,
  profile link, bio, role — no email, no `registered` date).
- [ ] Spot-check sanitization/escaping on user-controlled data that reaches
  storage or output: event title/description (`persist_event()`/
  `build_post_content()` in `inc/rest.php`), venue address
  (`resolve_venue_id()`), draft fields. Try an XSS payload
  (`<script>alert(1)</script>`) in an event title via `POST /event` and
  confirm it's escaped on render, not executed.
- [ ] `featured_image_id` on event create/update rejects an attachment ID
  the current user can't read (`current_user_can_use_attachment()` in
  `inc/rest.php`) — try passing another user's private attachment ID as a
  lower-privileged role and confirm rejection.

## 6. Performance sanity check

- [ ] `GET /members` is paginated (see section 2) — not an unconditional
  full-network user dump.
- [ ] Front page / event archive / member directory queries don't scale
  linearly with total network size — check for `posts_per_page`/`number`
  caps in the relevant `WP_Query`/`WP_User_Query` calls
  (`gatherpress-groups-tweaks.php`'s `pre_get_posts` filter,
  `Members_Controller::get_items()`).
- [ ] `group-members` and `event-rsvp` blocks batch their per-user meta
  lookups rather than querying per row (this was a specific fix in #1793 —
  confirm it hasn't regressed by checking query count on a page with many
  members/RSVPs, e.g. via Query Monitor or `$wpdb->num_queries`).

## 7. Production rollout notes

Once the passes above are green and the version-bump PR is merged, the
actual production deploy has its own gotchas — found the hard way during
the 0.35.0 rollout on `events.wordpress.org`.

- **`wp plugin update` alone is not sufficient/durable on production.**
  `wp-content/plugins` there is managed via `svn:externals` pinned to a
  specific tag per plugin. Check what's actually tracked *before* updating
  anything: `svn propget svn:externals -R` (run from `wp-content/plugins`).
  `wp plugin update` only replaces files on disk — it does **not** update
  the tracked external pin, so the change is a silent, undurable override
  that the next `svn update` (run by anyone, any time, for any reason)
  would silently revert. To make it durable: `svn propedit svn:externals .`
  and hand-edit only the one plugin's line (this property holds ~30
  plugins' pins in a single multi-line blob — don't reconstruct it with a
  scripted find/replace, one wrong character corrupts every other plugin's
  pin too). Review with `svn diff --depth=empty .` and confirm **only**
  that one line changed before committing. Then `svn commit` and
  `svn update` — this should reconcile with zero file churn, since it's
  the same code `wp plugin update` already fetched from the same wp.org
  release.

- **Run GatherPress Alpha's fix per-site with visible progress, not the
  bare `wp gatherpress alpha fix` command** — not only because of the
  100-site cap (see the `[gatherpress-alpha#66]` note above), but because
  the bare command is completely silent until it finishes, which reads as
  "hung" on a slow/first-ever run and invites a premature Ctrl-C. Call the
  same underlying method directly instead, scoped to one site:
  ```bash
  wp eval '
  $setup = \GatherPress_Alpha\Setup::get_instance();
  $ref = new ReflectionMethod( $setup, "run_fixes" );
  $ref->setAccessible( true );
  echo "Starting...\n";
  $start = microtime( true );
  $ref->invoke( $setup );
  echo "Done in " . round( microtime( true ) - $start, 2 ) . "s\n";
  echo "gatherpress_alpha_last_version: " . get_option( "gatherpress_alpha_last_version" ) . "\n";
  ' --url=<site>
  ```
  This runs the exact same code `fix()` would call — `fix()` itself only
  adds a capability check (irrelevant under CLI) and the buggy/capped
  multisite loop, both irrelevant/skipped here since `--url` already scopes
  wp-cli to one site. If a run does get interrupted early, it's safe:
  GatherPress Alpha's fixes are idempotent (confirmed by an upstream
  reviewer on PR #1873, and again live here — an interrupted run left no
  `gatherpress_alpha_last_version` recorded at all, so re-running just
  started clean with nothing partially applied).

- **Checking whether a site is exposed to the RSVP migration-gap bug
  (gatherpress#2135) needs the raw DB value, not `wp option get`.**
  `gatherpress-groups-tweaks.php` installs a `pre_option_gatherpress_settings`
  filter that unconditionally returns a synthetic
  `{show_timezone, enable_anonymous_rsvp}` array regardless of what's
  actually in the database — so `wp option get gatherpress_settings`
  always looks identical and tells you nothing about `rsvp_mode`. Query
  the DB directly instead:
  ```bash
  wp eval 'global $wpdb; var_dump( $wpdb->get_var( $wpdb->prepare(
    "SELECT option_value FROM $wpdb->options WHERE option_name = %s",
    "gatherpress_settings" ) ) );' --url=<site>
  ```

- **A WP-CLI command producing no output for N seconds is not the same as
  a hung one** — don't judge by wall-clock time alone, check for an
  actually-live query. This environment didn't have the `mysqladmin`/`mysql`
  CLI binaries available (DB reachable only through PHP's own driver), so
  use `wp eval` with `$wpdb` instead:
  ```bash
  wp eval 'global $wpdb; foreach ( $wpdb->get_results( "SHOW FULL PROCESSLIST" ) as $row ) { if ( "Sleep" !== $row->Command ) { print_r( $row ); } }' --url=<site>
  ```

## 8. Post-upgrade code audit (mandatory, run after every version bump lands)

Run this once the plugin update is live (locally after bumping the pin, and
again after the production rollout) — it's what would have caught the
`Blocks\Event_Query` vs. `Event\Query` wrong-class bug (#1874) before it hit
production instead of after, since that bug passed every class-existence
check across two GatherPress versions and only surfaced as a live fatal.
The point isn't spot-checking "the classes we remember using" — it's
mechanically verifying *every* call site, including the ones nobody's
thought about in months.

1. **Extract every GatherPress class this integration actually imports**,
   across all three plugins:
   ```bash
   grep -rn "^use GatherPress\|GatherPress\\\\Core" \
     public_html/wp-content/mu-plugins/groups \
     public_html/wp-content/mu-plugins/wporg-groups-frontend \
     public_html/wp-content/themes/groups-site \
     --include="*.php" | grep -v "/tests/\|/build/"
   ```
   (Exclude `/build/` — it's compiled from `/src/`; diff the two afterward
   with plain `diff` to confirm they're actually in sync rather than
   assuming it.)

2. **For each imported class, extract every method/constant/property call
   site** — not just the ones near the `use` statement. A call several
   functions away from the import, or reached through a differently-named
   local variable, is exactly the kind of thing a quick skim misses. Grep
   broadly per class/alias: `ClassName::`, `new ClassName(`, and
   `$lowercase_var->method(` for every plausible variable name that
   instance might be assigned to (`$event`, `$rsvp`, `$venue`, `$query`,
   `$setup` — check the surrounding code when a grep for `$obj->` comes up
   empty, since the call is often on the next line rather than the same
   one, e.g. `$venue = new Venue(...);` then `$term = $venue->get_term();`
   two lines later).

3. **Cross-check each one against the actual GatherPress source** for the
   *now-installed* version (not the old one):
   ```bash
   # from a local clone of github.com/GatherPress/gatherpress
   git show <new-tag>:path/to/class-file.php | grep -n "function method_name\|const CONST_NAME"
   ```
   A hit confirms it; nothing confirms a break. Also diff the whole file
   against the previous tag (`git diff <old-tag> <new-tag> -- path`) — an
   empty diff is the strongest possible signal that nothing there changed.

4. **Report clean only when every single call site has been individually
   confirmed** — "the class exists" or "the file's diff was small" are not
   substitutes for "this exact method still exists with this signature."

5. Note the audit's scope and result in the version-bump PR description and
   the tracking issue, the same way the manual/automated test passes are
   recorded.

## 9. Extend automated test coverage (mandatory, run after every version bump)

Every version bump so far has surfaced at least one thing the automated
suites didn't catch — that's exactly the gap tracked by
[issue #1863](https://github.com/WordPress/wordcamp.org/issues/1863)
("Identify gaps in unit and e2e test coverage"). Manually re-discovering
the same class of bug on the *next* upgrade is a wasted rediscovery — turn
what this pass found into a permanent regression test before moving on,
not a one-off fix.

Two tests written after the 0.35.0 bump are the reference examples for
what this looks like in practice — both live in
`mu-plugins/groups/tests/` (not `themes/groups-site/tests/`, which doesn't
exist and isn't wired into `phpunit.xml.dist` — theme code is tested from
there by including the real theme files directly, see the first example):

- **`test-groups-site-event-cards-patterns.php`** — executes the actual
  `groups-site` theme pattern files GatherPress renders via the
  block-patterns REST endpoint (the exact code path #1874's bug lived in
  and nothing else exercises), asserting real output for both the
  has-events and no-events branches of each pattern. The template for
  "this class of bug had zero coverage because nothing calls this code
  path" — find other such gaps by asking what *does* exercise a given file
  today, and whether "nothing" is an acceptable answer.
- **`test-gatherpress-api-contract.php`** — a data-provider-driven test
  asserting every GatherPress class/method/constant/property this
  integration calls still exists, sourced from the same list Section 8's
  audit builds. This is what makes Section 8's audit durable instead of
  a one-time manual pass that has to be redone by hand on every future
  bump — extend `CONTRACT` in that file whenever the audit finds a call
  site it doesn't cover yet, rather than leaving the gap for next time.

When a version bump (or this checklist) surfaces something that wasn't
caught automatically, before moving on:

1. Identify *why* it wasn't caught — what code path was it in, and what
   (if anything) currently exercises that path in CI.
2. Write the smallest test that would have failed before the fix and
   passes after it. Verify this concretely, not just in principle — run
   the test against the broken state (e.g. `git stash` the fix, run the
   test, confirm it fails with a clear message, `git stash pop`) before
   trusting it as a regression guard.
3. Prefer extending an existing data-provider/contract-style test (like
   `test-gatherpress-api-contract.php`'s `CONTRACT` list) over writing a
   narrow one-off test, when the gap is really "we don't check X across
   the board" rather than "this one specific call is wrong."
4. Note what was added (and why) in the version-bump PR description, the
   tracking issue, and — if it changes the shape of *how* this integration
   should be tested going forward, not just *what* — issue #1863 too.

## Known-issues appendix

Use this to distinguish "this checklist found something new" from
"this is a known, already-filed issue." Check your repo's issue tracker for
current status before treating any of these as new bugs:

| Symptom | Exercised by | Notes |
|---|---|---|
| Join/leave silently 403s via the UI (nonce lookup finds nothing on the front end) | Section 3, join/leave click-through | Front-end-only bug — the REST layer itself works fine when hit directly with a valid nonce (section 2's join/leave check). |
| Group Settings → About tab 403s for Organisers | Section 3, About tab | Calls a core admin-only REST endpoint directly instead of a plugin-scoped one. |
| `wporg/event-manage` block registered but not placed in any template | Section 3/4 grep, or `wp eval` block-registry dump | Dead code, not a functional gap — Event Organisers manage events fine via wp-admin. |
| `/members` fully public with no membership/auth requirement | Section 5 | Open product/privacy question, not a bug in itself — confirm current decision, don't assume it's wrong. |
| `my-events` block empty for a user who created events but never RSVP'd | Section 3, per-role browser pass as an event creator | RSVP-attendance based by design — confirm this still matches the current product decision. |
| `groups-site` theme activatable on non-groups-network sites | Not covered by this checklist (network-admin action, not a groups-site page) | If auditing this, attempt `wp theme activate groups-site --url=<non-groups-network-site>` and confirm it's blocked. |
