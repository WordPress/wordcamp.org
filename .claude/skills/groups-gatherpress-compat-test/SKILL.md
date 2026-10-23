---
name: groups-gatherpress-compat-test
description: Run the Groups/GatherPress front-end integration compatibility checklist — per-role browser pass, direct REST pass, GatherPress-off pass, and security/perf sanity checks. Use after any GatherPress version bump, or any change to mu-plugins/wporg-groups-frontend, mu-plugins/groups, or the groups-site theme.
---

# Groups / GatherPress compatibility checklist

GatherPress updates often, and its changes can silently break our Groups
integration. This checklist is self-contained. It covers
`mu-plugins/wporg-groups-frontend` (front-end event/member management),
`mu-plugins/groups/gatherpress-groups-tweaks.php` (settings/capability/query
overrides) and the `groups-site` block theme.

**Keep this skill current.** When a session on this integration (a version
bump, a bug fix, a flaky test, anything touching the code above) turns up an
essential finding (a root cause, a bug and its fix, a test-env gotcha, a
method that generalizes), add it before the session ends, in the section it
belongs to. "Essential" means the next person would hit the same wall
without it. Fold findings into existing prose: this file is current best
understanding, not a changelog.

**Compact it periodically.** When a section gets long or findings overlap,
reread the whole file: merge redundant points, cut what is superseded or no
longer true, and re-verify paths, function names and versions against the
repo instead of trusting older prose.

## Automated layers (run first)

Run both before the manual pass. If either fails, fix that first rather than
debugging across layers. The manual checklist covers what they can't: real
browser interaction, cross-plugin capability leaks, exploratory checks.

- **PHPUnit**: `docker compose -f docker-compose.phpunit.yml up` (or your
  usual invocation). The `WordCamp Groups` suite covers capability logic,
  `gatherpress-groups-tweaks.php` option/query overrides, `Members_Controller`
  role rules, REST permission/IDOR checks, draft flow, block registration.
- **E2E (Playwright)**: `npx playwright test`, or the `e2e-tests.yml` Action
  (manual, or on any PR touching the paths it lists). **Check it actually ran
  before trusting a green PR.** The path list is hand-maintained and has
  lagged: specs reach the recurring-events plugin's occurrence pages and
  match `groups-site-*` classes only the theme emits, so changes confined to
  either used to skip E2E. When a spec starts depending on a new path, add it
  there in the same PR.
  Covers the anonymous front page, an author creating an event through the
  real UI, per-event messaging, the auto-publish-notification email, the
  member directory, and Reply → Cancel on a group news post's comment form.
  Accounts (all `password`): `organiser1`–`4`, `eventorganiser1`–`7`,
  `member1`. **Every test that logs in has its own account, not just every
  spec file**: `organiser2`/`3`/`4` (editor tier) are `event-form.spec.js`'s,
  one per test, and `event-manage-messaging.spec.js` needs
  `eventorganiser2`/`4`/`5` because its three tests run concurrently under
  `fullyParallel`. Don't consolidate them onto shared accounts.

### Reading an E2E failure

A timeout on a UI interaction is not `fullyParallel` contention by default,
so don't reach for `--workers=1`. Every failure writes an `error-context.md`
(path in the terminal output) holding the page's accessibility tree at the
moment of timeout. Read it first. These deterministic bugs were all first
mistaken for flakiness:

- **Starter-pattern dialog.** "Choose a pattern" opens on every fresh
  `post-new.php` for a post type with starter patterns (`gatherpress_event`
  has them). Unlike the "Welcome to the editor" guide, its Close button and
  Escape don't dismiss it. It sat over the sidebar and hung every later
  interaction. Every spec creating an event must call
  `tests/e2e/utils/dismiss-editor-onboarding.js`.
- **Shared logins.** This environment allows one active session per user
  (see `tests/e2e/utils/login.js`), so one worker's login can invalidate
  another's established session mid-test; `error-context.md` shows a login
  form instead of the editor. `login()`'s retry only covers a collision
  during the login request itself. The only fix is one account per
  concurrently running test, including within one spec file.
- **Collapsed "Event settings" panel.** The panel holding
  `tests/e2e/utils/pin-event-far-future.js`'s "Date & time start" button is a
  `PanelBody` whose open state is a per-user preference persisted
  server-side. GatherPress force-opens it once, in a `domReady` callback
  checking `core/editor`'s `isEditorPanelOpened()`, which can race the
  preferences store's async hydration. If it loses, the panel stays
  collapsed for that account on every later load (never re-checked), so it
  reproduces deterministically per account. `pin-event-far-future.js` checks
  whether the button is visible and clicks the panel header if not.

Suspect genuine environment slowness only when `error-context.md` shows the
*expected* page mid-load (not a login form, error page or stray dialog), and
confirm it from the evidence.

**A test can be right that production is broken.**
`event-publish-notification.spec.js` intermittently found no email after a
real publish, with nothing logged. Several events publishing in the same
second (this suite does it under `fullyParallel`, real usage can too) raced
on `wp_schedule_single_event()`. Core's cron store is one `wp_options` row
(`cron`) updated by an unlocked read-modify-write (`_get_cron_array()` /
`_set_cron_array()` in `wp-includes/cron.php`), so concurrent callers can
clobber each other's job any time before wp-cron runs it, and the return
value only reflects the caller's own write. A retry with re-verification
around scheduling (PHPUnit regression test forcing `pre_option_cron` empty)
closes the common case but not a later clobber by any unrelated publish.

The real fix, `schedule_new_event_notification()` in
`mu-plugins/wporg-groups-frontend/inc/notifications.php`, bypasses WP-Cron:
it calls `\GatherPress\Core\Event\Rest_Api::get_instance()->send_emails()`
synchronously, the method GatherPress's `gatherpress_send_emails` cron
handler and its "Message all members" REST action both call, trading a brief
publish delay for no race. The send is deferred within the request:
`transition_post_status` fires inside `wp_insert_post()` before the
datetimes/venue are written (sending there produced "Date: —" emails), so
the hook only queues the event and `send_pending_new_event_notifications()`
sends at `shutdown` priority `PHP_INT_MAX`, after GatherPress's
default-priority shutdown datetime resolver.

Going synchronous has a blast radius: any PHPUnit test publishing a
`gatherpress_event` would render real email templates when the queue
drains, needing runtime (theme template functions, etc.) most bootstraps
don't load. `Groups_TestCase::setUp()` removes the action by default;
`Test_Groups_Notifications` re-adds it and drains the queue explicitly.
Before making any other cron-deferred integration point synchronous, check
which tests publish through the same hook.

## 0. Before bumping the pinned version

- **Confirm the target is stable**, not a beta/RC:
  `curl -s https://api.wordpress.org/plugins/info/1.0/gatherpress.json | python3 -c "import json,sys; print(json.load(sys.stdin)['version'])"`.
- **Diff GatherPress core** for breaking API changes before touching code
  here. In a local clone of `github.com/GatherPress/gatherpress`, run
  `git diff <old-tag> <new-tag>` and `git log <old-tag>..<new-tag> --oneline`.
  Check every class this integration touches,
  `GatherPress\Core\{Rsvp\Rsvp,Event\Event,Venue\Venue,Venue\Setup,User,Setup,Blocks\Setup,Blocks\Event_Query}`
  (grep `GatherPress\\\\` across `mu-plugins/groups`,
  `mu-plugins/wporg-groups-frontend` and `themes/groups-site` for the current
  list), for renamed/removed constants, changed return types (`Rsvp::get()`
  went from `array` to `array|null` in 0.35.0 and broke a test that indexed
  it), and classes made `final` (harmless unless we extend one:
  `grep -rn "extends.*GatherPress"`).
  **A class existing is not enough.** Verify every method/constant/property
  call site. A wrong-but-existing import (`Blocks\Event_Query` vs. the real
  `Event\Query`) passed every existence check across two versions and only
  surfaced as a production fatal. Run Section 8's audit now too, not only
  after landing.
- **Grep theme templates for GatherPress blocks**, not just PHP:
  `grep -rn "wp:gatherpress" public_html/wp-content/themes/groups-site`.
  GatherPress Alpha's migration only rewrites block content stored in the
  database, never theme `.html` files. A removed/renamed block (e.g.
  `gatherpress/icon` → `core/icon` in 0.35.0) needs a matching template edit
  or it silently renders blank.
- **Find every pinned copy of the version** (there is no single source of
  truth):
  `grep -rln "<old-version>" . --include="*.yml" --include="*.php" --include="*.md" --include="*.sh" | grep -v "plugins/gatherpress"`.
  At the 0.35.0 bump: `.github/workflows/e2e-tests.yml`,
  `.github/workflows/unit-tests.yml`, `.docker/bin/install-test-suite.sh`
  (already stale at an older version than the workflows, so don't assume
  they agree), and this skill's install commands below.

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
  https://github.com/GatherPress/gatherpress/releases/download/0.36.0/gatherpress.0.36.0.zip \
  --activate --url=events.wordpress.test/group/sunshine-coast-qld/
```

Environment gotchas:

- Adding/removing a `groups-site` pattern requires a theme `Version:` bump.
  Locally, clear the stale scan with
  `wp eval 'wp_get_theme()->delete_pattern_cache();' --url=<site>`;
  `wp cache flush` does not clear it.
- If PHPUnit can't read a mounted file that exists on the host, run
  `docker compose -f docker-compose.phpunit.yml up -d --force-recreate phpunit_wp`.
- Hooks added in `wpSetUpBeforeClass()` vanish after the first test of a full
  suite, because `WP_UnitTestCase` restores a process-wide hook snapshot.
  Re-add named callbacks in `setUp()`.
- `go_to( get_permalink( $id ) )` doesn't establish a singular query in this
  fixture. Use `go_to( home_url( "?p={$id}&post_type={$type}" ) )`.
- If a new test account's first E2E run fails in `dismissEditorOnboarding`,
  rerun once, and only when `error-context.md` shows the Welcome dialog
  reinitializing.

**After bumping the pin, install/update
[GatherPress Alpha](https://github.com/GatherPress/gatherpress-alpha)
(version-locked to core) and run its one-time migration.** GatherPress ships
breaking content migrations (old `gatherpress/icon` usage, old
`gatherpress/venue-map` width/height attributes, renamed RSVP settings
values) through it instead of an upgrade routine. Skipping it looks like a
GatherPress regression (a venue's address/phone/website/map vanish from the
front end) but is just unmigrated data:

```bash
docker compose exec wordcamp.test wp plugin install \
  https://github.com/GatherPress/gatherpress-alpha/releases/download/0.36.0/gatherpress-alpha.0.36.0.zip \
  --activate --url=events.wordpress.test/group/sunshine-coast-qld/
docker compose exec wordcamp.test wp gatherpress alpha fix --url=events.wordpress.test/group/sunshine-coast-qld/
```

Create one user per role tier with **both** a login password (browser pass)
and an application password (REST pass):

```bash
for pair in "organiser1:editor" eventorganiser{1..7}:author "member1:subscriber"; do
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
ORG="organiser1:<app-password>"       # editor tier — "Organizer"
AUTHOR="eventorganiser1:<app-password>" # author tier — "Event Organizer"
MEM="member1:<app-password>"          # subscriber tier — "Member"
BASE="https://events.wordpress.test/group/sunshine-coast-qld"
```

## 2. Direct REST pass (bypass the UI)

Confirm the `wporg-groups/v1` route list first:

```bash
curl -sk "$BASE/wp-json/wporg-groups/v1" | python3 -c "
import json, sys
d = json.load(sys.stdin)
for r, v in sorted(d['routes'].items()):
    print(r, sorted({m for e in v['endpoints'] for m in e['methods']}))
"
```

Expect these 27 routes plus the root. Methods are POST unless noted:

- `rest.php`: `event-form-data` (GET), `event-templates` (GET), `event`,
  `event/{id}`, `event/{id}/rsvp`, `drafts` (GET), `draft`, `draft/{id}`,
  `draft/{id}/publish`, `group-info` (GET and POST)
- `check-in.php`: `event/{id}/check-in` (GET),
  `event/{id}/check-in/{comment_id}`, `event/{id}/walk-in`,
  `event/{id}/walk-in-count`
- `export.php`: `export` (GET)
- `Members_Controller`: `members` (GET), `members/{id}` (GET),
  `members/{id}/role`, `members/me/role`, `members/join`, `members/leave`
  (DELETE), `members/notification-preference`
- `Ownership_Transfer_Controller`: `ownership-transfer` (GET),
  `ownership-transfer/initiate`, `ownership-transfer/accept`,
  `ownership-transfer/decline`, `ownership-transfer/cancel`

A route missing from the live list is a regression; one not listed here is
new, so add it before continuing. Regenerate from code with
`grep -rn -A3 register_rest_route public_html/wp-content/mu-plugins/wporg-groups-frontend/inc`.

Checks (anonymous and each role where relevant):

- [ ] **`GET /event-form-data` as author** → 200, populated `fields` + `venues`.
- [ ] **`POST /event` as editor** → published event, 200 with `{id, permalink, title}`.
- [ ] **Overnight rollover**: `date=2026-08-20 time_start=22:00 time_end=01:00`
  → `gatherpress_datetime_end` is the *next* day, not rejected as zero-length:
  `docker compose exec wordcamp.test wp post meta get <id> gatherpress_datetime_end --url=$BASE`.
- [ ] **IDOR**: `POST /event/<other_authors_event_id>` as `$AUTHOR` → 403
  `rest_forbidden`, title unchanged.
- [ ] **Author can edit their own event** → 200.
- [ ] **`GET /members`** is public and paginated (`per_page` default 100, max
  250 via `Members_Controller::MAX_PER_PAGE`): `X-WP-Total`/`X-WP-TotalPages`
  present. `per_page=250` → 200; `per_page=9999` → 400 `rest_invalid_param`
  (`validate_per_page()` rejects rather than caps). Each member has
  `role`/`roleLabel` (`administrator`/`editor→Organizer`,
  `author→Event Organizer`, `contributor`/`subscriber→Member`, per
  `Members_Controller::ROLE_LABELS`).
- [ ] **Editor promotes subscriber → author**:
  `POST /members/<member1_id>/role -d role=author` as `$ORG` → 200; confirm
  with `wp user get <id> --field=roles`.
- [ ] **Author blocked from role changes** → 403 `rest_cannot_edit_roles`
  (passes `current_user_can_manage_events()`, fails
  `current_user_can_manage_group_settings()`; both are required).
- [ ] **Editor blocked from `role=administrator`** → 400 `rest_invalid_param`
  (`ASSIGNABLE_ROLES` rejects it at the args layer, before the permission
  callback).
- [ ] **Can't change your own role** → 403 `cannot_change_own_role`.
- [ ] **Can't demote the last organizer** (only one editor/admin on the site)
  → 403 `cannot_remove_last_organizer`.
- [ ] **Draft flow**: `POST /draft` → `GET /drafts` → `POST /draft/{id}` →
  `POST /draft/{id}/publish`; `wp post get <id> --field=post_status` goes
  draft → publish.
- [ ] **`/group-info` is Organizer-only**: `GET` as `$ORG` → 200, as
  `$AUTHOR`/`$MEM` → 403. The About and Design tabs go through it because
  core's `/wp/v2/settings` requires `manage_options`.
- [ ] **`DELETE /members/leave` + `POST /members/join`** as `$MEM`: leave
  returns `{"success":true,"rsvps_cancelled":N}`, join
  `{"success":true,"memberCount":N}`; leave as `POST` gets 404
  `rest_no_route`. Confirm a non-empty nonce header. #1804 was a
  front-end-only bug; this confirms the REST layer works with a valid
  nonce/app-password.

## 3. Per-role browser pass

Log in as each role (`$BASE/wp-login.php`) and click through the UI, don't
just check curl output:

- **Event create/edit** via the front-end modal (not wp-admin): venue picker,
  date/time fields, featured image. Walk the online states too: hybrid
  (venue + online), online-only, clearing the link on a still-online event,
  and back offline, checking `wp post term list <id> _gatherpress_venue` and
  the `gatherpress_online_event_link` meta after each save.
  `Event::set_online()` keeps a saved link when handed an empty one, so
  clearing the field relies on the extra delete in `sync_event_venue()`.
  Headless, an empty `fill` and Ctrl/Cmd+A don't clear these
  React-controlled inputs; focus the field and send Backspace per character.
- **Also open a `gatherpress_event` in the wp-admin block editor** and check
  the console / PHP error log for fatals. Every block-editor load fetches
  `/wp/v2/block-patterns/patterns`, executing the PHP of *every* registered
  pattern, `Inserter: no` and unplaced ones included. A broken but unused
  pattern only surfaces here: three `groups-site` event-card patterns
  importing the wrong GatherPress class (fixed in #1874) went unnoticed
  through several versions until a production rollout hit it.
- **RSVP** as Member and as Event Organizer.
- **Group settings tabs** (Events / Venues / Members / Design / About /
  Export) load
  without console errors or 403s; Organizer-only tabs are absent (not
  disabled) for lower roles.
- **Member directory** (`/members/`): role labels correct, join/leave work.
  A 404 usually means a fresh/reset site has no page with slug `members`
  (the theme renders it via `page-members.html`; check
  `wp post list --post_type=page`, create with
  `wp post create --post_type=page --post_title=Members --post_name=members --post_status=publish`).
- **wp-admin as each non-admin role**: capability leaks (like the fixed
  `promote_users` escalation) only show here. Can they reach a Users
  role-promotion UI beyond what the front end allows? Site Editor should be
  reachable for editors (the `edit_theme_options` grant); confirm the grant
  unlocks nothing broader.
- **Anonymous**: public pages render; every interactive action (RSVP, join,
  edit) redirects to login rather than failing silently or 403ing with no
  feedback.

Note what is *absent* per lower role, not just disabled: a Member sees **no**
event-management UI at all, not a greyed-out button.

## 4. GatherPress-deactivated pass

**`wp plugin deactivate gatherpress` doesn't work here.**
`mu-plugins/wcorg-network-plugin-control.php` force-activates
`gatherpress/gatherpress.php` on every request for the groups network
(`GROUPS_NETWORK_ID`); even `--network` reports success while routes/classes
stay live on the next request. Move the files instead:

```bash
docker compose exec wordcamp.test mv wp-content/plugins/gatherpress wp-content/plugins/gatherpress.disabled
# ... run the checks below ...
docker compose exec wordcamp.test mv wp-content/plugins/gatherpress.disabled wp-content/plugins/gatherpress
```

- [ ] Every groups-network page (front page, single event, single venue,
  `/members/`) and wp-admin: **no PHP fatals** anywhere on the network.
- [ ] Every GatherPress reference is guarded:
  ```bash
  grep -rn "GatherPress\\\\Core\|gatherpress_" \
    public_html/wp-content/mu-plugins/wporg-groups-frontend \
    public_html/wp-content/mu-plugins/groups \
    --include="*.php" | grep -v "class_exists\|function_exists"
  ```
  A hit not already behind an earlier `class_exists()`/`function_exists()`
  (e.g. `wporg-groups-frontend.php`'s bootstrap guard,
  `gatherpress-groups-tweaks.php`'s per-function guards) is a gap.
- [ ] Restore GatherPress and confirm everything is back to normal.

## 5. Security-focused pass

- [ ] Enumerate every `user_has_cap`/`map_meta_cap` filter:
  ```bash
  grep -rn "user_has_cap\|map_meta_cap" \
    public_html/wp-content/mu-plugins/wporg-groups-frontend \
    public_html/wp-content/mu-plugins/groups --include="*.php"
  ```
  Each must grant only a plugin-scoped or clearly intentional capability
  (e.g. `edit_theme_options` for editors' Site Editor access), never an
  unscoped core capability reachable from stock wp-admin without a
  plugin-level ceiling. That was the fixed escalation: editors got
  `promote_users`, correct through our REST route but also unlocking stock
  wp-admin's role screen. Regression check:
  `wp eval 'var_dump( user_can( <editor_id>, "promote_users" ) );' --url=$BASE`
  → `false`.
- [ ] `GET /members` is public on purpose, a tracked product/privacy question
  (see the issue tracker for the current decision). Fields should match it:
  as of writing name, avatar, profile link, bio, role; no email, no
  `registered` date.
- [ ] Sanitization/escaping of user data reaching storage or output: event
  title/description (`persist_event()`/`build_post_content()` in
  `inc/rest.php`), venue address (`resolve_venue_id()`), draft fields. Send
  `<script>alert(1)</script>` as an event title via `POST /event`; it must
  render escaped.
- [ ] `featured_image_id` rejects an attachment the user can't read
  (`current_user_can_use_attachment()` in `inc/rest.php`): pass another
  user's private attachment ID as a lower role.

## 6. Performance sanity check

- [ ] `GET /members` is paginated (section 2), not a full-network user dump.
- [ ] Front page / event archive / member directory queries carry
  `posts_per_page`/`number` caps and don't scale with network size
  (`gatherpress-groups-tweaks.php`'s `pre_get_posts` filter,
  `Members_Controller::get_items()`).
- [ ] `group-members` and `event-rsvp` blocks batch per-user meta lookups
  (#1793). Check query count on a page with many members/RSVPs (Query Monitor
  or `$wpdb->num_queries`).

## 7. Production rollout notes

Found the hard way during the 0.35.0 rollout on `events.wordpress.org`.

- **`wp plugin update` alone isn't durable on production.**
  `wp-content/plugins` there is `svn:externals` pinned to a tag per plugin;
  check with `svn propget svn:externals -R` (from `wp-content/plugins`).
  `wp plugin update` only replaces files, so the next `svn update` by anyone
  silently reverts it. Instead `svn propedit svn:externals .` and hand-edit
  only that plugin's line (the property holds ~30 plugins' pins in one blob;
  a scripted find/replace can corrupt all of them). `svn diff --depth=empty .`
  must show only that line changed. Then `svn commit` and `svn update`, which
  should reconcile with zero file churn.
- **Run GatherPress Alpha's fix per site with visible progress**, not bare
  `wp gatherpress alpha fix`: the bare command's multisite loop caps at 100
  sites (gatherpress-alpha#66) and is silent until done, which reads as hung
  and invites a premature Ctrl-C. Call the underlying method, scoped by
  `--url`:
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
  `fix()` adds only a capability check (irrelevant under CLI) and that
  loop. The fixes are idempotent (confirmed upstream on PR #1873 and live: an
  interrupted run recorded no `gatherpress_alpha_last_version` and a re-run
  started clean).
- **Checking exposure to the RSVP migration gap (gatherpress#2135) needs the
  raw DB value.** `gatherpress-groups-tweaks.php`'s
  `pre_option_gatherpress_settings` filter always returns a synthetic
  `{show_timezone, enable_anonymous_rsvp}` array, so `wp option get` tells you
  nothing about `rsvp_mode`:
  ```bash
  wp eval 'global $wpdb; var_dump( $wpdb->get_var( $wpdb->prepare(
    "SELECT option_value FROM $wpdb->options WHERE option_name = %s",
    "gatherpress_settings" ) ) );' --url=<site>
  ```
- **No output for N seconds isn't a hang.** Check for a live query. There
  were no `mysql`/`mysqladmin` binaries (DB reachable only via PHP), so:
  ```bash
  wp eval 'global $wpdb; foreach ( $wpdb->get_results( "SHOW FULL PROCESSLIST" ) as $row ) { if ( "Sleep" !== $row->Command ) { print_r( $row ); } }' --url=<site>
  ```

## 8. Post-upgrade code audit (mandatory after every bump)

Run locally after bumping the pin and again after the production rollout.
It would have caught #1874's `Blocks\Event_Query` vs. `Event\Query` before
production. The point is mechanically verifying *every* call site, not the
ones you remember.

1. **List every GatherPress class imported**, across all three plugins:
   ```bash
   grep -rn "^use GatherPress\|GatherPress\\\\Core" \
     public_html/wp-content/mu-plugins/groups \
     public_html/wp-content/mu-plugins/wporg-groups-frontend \
     public_html/wp-content/themes/groups-site \
     --include="*.php" | grep -v "/tests/\|/build/"
   ```
   `/build/` is compiled from `/src/`; `diff` the two afterward to confirm
   they're in sync.
2. **For each class, find every method/constant/property call site**, not
   just those near the `use`: grep `ClassName::`, `new ClassName(`, and
   `$var->method(` for every plausible variable name (`$event`, `$rsvp`,
   `$venue`, `$query`, `$setup`). When `$obj->` finds nothing, read the
   surrounding code; the call is often lines later
   (`$venue = new Venue(...);` then `$term = $venue->get_term();`).
3. **Cross-check against the installed version's source**, from a clone of
   `github.com/GatherPress/gatherpress`:
   ```bash
   git show <new-tag>:path/to/class-file.php | grep -n "function method_name\|const CONST_NAME"
   ```
   A hit confirms it; no hit is a break. Also
   `git diff <old-tag> <new-tag> -- path`: an empty diff is the strongest
   signal nothing changed.
4. **Report clean only when every call site is individually confirmed.**
   "The class exists" or "the diff was small" are not substitutes.
5. Record the audit's scope and result in the version-bump PR and the
   tracking issue, like the test passes.

## 9. Extend automated coverage (mandatory after every bump)

Every bump so far surfaced something the suites missed (tracked by
[#1863](https://github.com/WordPress/wordcamp.org/issues/1863), "Identify
gaps in unit and e2e test coverage"). Turn each finding into a permanent
regression test before moving on.

Reference tests live in `mu-plugins/groups/tests/`, not
`themes/groups-site/tests/` (doesn't exist, not in `phpunit.xml.dist`; theme
code is tested from there by including the real theme files):

- **`test-gatherpress-api-contract.php`**: data-provider test asserting
  every GatherPress class/method/constant we call still exists, from
  Section 8's list. Extend `CONTRACT` whenever the audit finds an uncovered
  call site, and prune entries when call sites disappear (0.36.0's
  `Event::$post` went once check-in stopped reading it). Section 8's greps
  match comments too, and our comments name plenty of GatherPress methods
  nothing calls: strip comments with `token_get_all()` before deciding a
  call site exists. The test also fails on any name GatherPress keeps only
  through `includes/core/register-class-aliases.php` (`GatherPress\Core\Venue`
  and the like): a `class_exists()` guard on an alias stays true until the
  shim is dropped, then the guarded code goes dormant without an error.
- **`test-groups-site-gatherpress-blocks.php`**: the PHP contract can't see
  blocks, and a dropped block renders blank. This scans the theme's
  templates, parts and patterns for `wp:gatherpress/*` and asserts each is
  registered, and checks the event Query Loops' `namespace` still equals
  `Blocks\Event_Query::BLOCK_NAME`, the only thing that makes GatherPress
  apply its event query. Blocks placed from PHP strings outside those three
  directories aren't scanned.
- **`test-groups-site-event-cards.php`** (successor to the removed
  `test-groups-site-event-cards-patterns.php`) preserves thumbnail cache
  priming that core can't infer through a `core/pattern` reference. Directly
  include any future orphaned PHP pattern that normal template rendering
  can't exercise.

For anything a bump or this checklist caught that CI didn't:

1. Find *why*: which code path, and what (if anything) exercises it in CI.
2. Write the smallest test that fails before the fix and passes after.
   Prove it: `git stash` the fix, confirm a clear failure, `git stash pop`.
3. Prefer extending a contract-style test (`CONTRACT`) over a one-off when
   the gap is "we don't check X across the board".
4. Note what was added and why in the PR and tracking issue, and in #1863 if
   it changes *how* this integration should be tested.

## 10. Event email rewrites (mandatory after every bump)

GatherPress renders every event email (publish notification, "Message all
members", "Message attendees") from
`includes/templates/admin/emails/event-email.php`, which is neither
filterable nor theme-overridable (`Rest_Api::send_event_email_to_recipient()`
uses a hardcoded path). We correct the body on the way out with `wp_mail`
filters keyed on the template's marker comments (`<!-- Featured Image -->`,
`<!-- Event Title -->`, `<!-- RSVP Button -->`):

- `wporg-groups-frontend/inc/event-email.php` gives the featured image
  `height: auto` (it ships real `width`/`height` attributes plus a
  `max-width: 100%` cap, so phones squash it) and rebuilds the RSVP button as
  a table (an inline `<a>` with its own padding overlaps the line above in
  clients ignoring inline padding, e.g. Help Scout, Outlook).
- `wporg-groups-frontend/inc/notifications.php` tailors the publish
  notification per recipient, stripping that button from the author's copy.

**A bump can break this silently**: a marker that stops matching yields a
correct-looking email with the old bug back. After every bump, run
`git diff <old-tag> <new-tag> -- includes/templates/admin/emails/event-email.php`
and confirm the three markers and the button's wrapper `<div>` keep their
shape. Run `test-event-email.php` and `test-notifications.php`, which pin how
the two rewrites interact. `test_fixes_apply_to_the_real_template()` sends
through GatherPress's own template with a featured image, so a renamed
marker fails there rather than shipping; the other cases use a copied body,
which can't drift. Since 0.36.0 the template writes `height: auto` on the
image itself, so the image fix only matters on older versions.

Two rules for any such filter:

- **Bound it to the template, not just the send.** Scoping a `wp_mail` filter
  to one `send_emails()` call with `add_filter`/`finally` isn't enough: other
  mail sent in that window (a plugin acting on a per-recipient GatherPress
  hook) gets rewritten too. A password-reset email went out subjected "Your
  event has been published". Require a template marker in the body first.
- **A regex over rendered HTML must fail closed.** `<div\b[^>]*>.*?</div>`
  stops at the *first* `</div>`, so a nested one leaves a dangling tag and a
  broken email. Exclude the tag from the match body (`(?:(?!</?div\b).)*?`)
  so an unexpected shape doesn't match.

Checking email locally: MailCatcher's host port is 1080 only for a stack on
default ports. A second stack (a worktree beside the main one, or
`WORDCAMP_MAILCATCHER_PORT` set) has its own, and 1080 then shows the
*other* stack's inbox. Ask the stack you're driving, from its checkout:

```bash
MAIL="http://localhost:$(docker compose port wordcamp.test 1080 | head -1 | sed 's/.*://')"
curl -s "$MAIL/messages"              # the list
curl -s "$MAIL/messages/<id>.html"    # one body
curl -s -X DELETE "$MAIL/messages"    # clear
```

Publishing through the real front-end dialog exercises the true path;
headlessly, `do_action( 'shutdown' )` after `wp_update_post()` in `wp eval`
flushes the notification queue. To judge a body at phone width, open it in
the browser and measure the `img` with `getBoundingClientRect()`; eyeballing
misses aspect-ratio regressions.

## 11. Timezones, and series-level data reaching occurrences

Found by a browser pass the automated suites passed clean (#2021).

- **GatherPress spells a UTC offset two ways.** `Utility::timezone_choices()`
  (parsed from `wp_timezone_choice()`) says `UTC+10`; the events table,
  `wp_timezone_string()` and `Utility::list_timezone_and_utc_offsets()` say
  `+10:00`. `Utility::normalize_timezone_string()` maps the first to the
  second except at zero, which becomes `UTC` while `wp_timezone_string()`
  says `+00:00`. Anything round-tripping a zone between a form and the events
  table must canonicalize both sides (`wporg-groups-frontend/inc/event-timezone.php`).
  Silent and destructive: a `<select>` whose value matches no option falls
  back to the first, so opening an event and saving rescheduled it into
  Africa/Abidjan.
- **`show_timezone` is read before the block attribute**; see Section 13.
- **Series data doesn't reach already-projected occurrences unless the
  projection updates them.** `Occurrences::project()` upserts (not
  `INSERT IGNORE`), updating schedule columns but keeping `status` and
  `created_gmt` so a cancelled occurrence survives. Anything else that
  becomes series-level needs the same, plus a test that cancels an occurrence
  and re-projects.
- **`save_post` is the wrong moment to project.** GatherPress writes the
  schedule on `wp_after_insert_post` (at `shutdown` for a new post), and the
  front-end form writes it after `wp_update_post()` returns. Either way
  `save_post_gatherpress_event` projects the *previous* schedule and sets the
  6-hour `gpre_projected_` marker that stops `maybe_project()` repairing it,
  so a changed zone stayed stale until the daily cron.
  `Occurrences::reproject_on_schedule_write()` watches the per-field meta
  `Event::save_datetimes()` writes, the only observable signal (it fires no
  action). Verify by reading occurrence rows after a plain save, never by
  calling `project()` yourself; that passes whether the hook works or not.
- **Anything GatherPress caches per post is shared by every occurrence.** An
  occurrence is the series post plus the `gpre_occurrence` query var, so a
  cache keyed on the queried post serves one date to all of them. 0.36.0's
  iCal cache gave every occurrence's `.ics` the first downloaded date;
  `Plugin::calendar_max_age()` disables it for occurrence requests. After
  each bump, compare two occurrences' `.ics` `DTSTART`, and look for new
  caches in the changelog.

An occurrence page reads its date via `Context::metadata()`, from the
occurrence row's own columns. Checking the series post's meta doesn't
predict the page; look at the `{prefix}gatherpress_occurrences` row.

## 12. Adding an events-archive filter

The archive filters (`event_time`, `event_format`, `event_language`) live in
`gatherpress-groups-tweaks.php` and share three easily broken constraints:

- **Resolve every ID-based filter into one `post__in`.** WP_Query's
  `parse_where()` treats `post__in`/`post__not_in` as if/elseif, so a
  `post__not_in` filter (in-person: no venue) is silently dropped once another
  sets `post__in`. Narrow one ID set in PHP instead of a query var per filter.
  An empty result must be `array( 0 )`; an empty `post__in` is ignored and
  shows the whole archive.
- **Keep joins off the archive's own query.** A `tax_query`/`meta_query` on
  the main query makes it `DISTINCT`, collapsing the duplicate rows
  `gatherpress-recurring-events`'s `Query::clauses()` adds for one row per
  date, so a weekly series shows once, only while filtered. Resolve IDs in a
  separate `fields => ids` query (`Query::clauses()` bails on it, so no
  expansion) and prime the meta cache once for the set.
- **Each `wporg/query-filter` form holds only its own control**, so every
  filter must carry the others' state and the search form all of them, or
  submitting one resets the rest.

Before adding a fourth: the row is `nowrap`, and three toggles already take
~410px of ~360px usable at 400px (it wraps below 781px in `responsive.css`
for that reason). A data-driven control should return an empty options
array when there's nothing to choose, which makes `wporg/query-filter`
render nothing.

## 13. Influencing how a GatherPress date renders

The lever depends on which half of the format you want, and the answers are
opposites (#2033, #2021).

- **An explicit block format beats the filters.**
  `Event::get_display_datetime()` applies `gatherpress_date_format`/
  `gatherpress_time_format` only when the block passes no format, and
  groups-site templates pass explicit ones. Reaching them means rewriting the
  attribute in `render_block_data`; the GatherPress filters then cover only
  unformatted calls, i.e. every event email.
  `wporg-groups-frontend/inc/event-date-format.php` needs both.
- **`show_timezone` is the other way round.** The global setting is resolved
  *before* the block's `showTimezone`, and the zone is an empty string when
  it's off, so the attribute alone does nothing. Turning the global on is the
  only lever reaching event emails (which call `get_display_datetime()` with
  no arguments); a block that should stay quiet then sets
  `showTimezone: "no"`, which wins once the global is on.

A template block carrying a marker for one of these filters should use
`className`, not an attribute missing from the block's `block.json`:
Gutenberg drops undeclared attributes when the template is saved in the Site
Editor; a class name survives.

## Known-issues appendix

Separates "new finding" from "already filed". Check the issue tracker for
current status before treating any as new:

| Symptom | Exercised by | Notes |
|---|---|---|
| Join/leave silently 403s via the UI (front-end nonce lookup finds nothing) | Section 3 join/leave | Front-end only; REST works with a valid nonce (section 2). |
| `wporg/event-manage` registered but in no template | Section 3/4 grep, or a `wp eval` block-registry dump | Dead code; Event Organizers manage events via wp-admin. |
| `/members` fully public | Section 5 | Open product/privacy question, not a bug in itself. |
| `my-events` empty for a creator who never RSVP'd | Section 3 as an event creator | RSVP-based by design; confirm it still matches the product decision. |
| A departed member's RSVP still in `wp_comments` with `comment_approved = trash` | Section 3 leave-group, then inspect the comment rows | Expected. `Leave_Cleanup\cancel_future_rsvps()` uses `wp_delete_comment()` without force, matching GatherPress's `no_status` path (`Rsvp\Storage::save()`), so with trash enabled (default) it trashes. The seat is released either way (RSVP counts read approved comments only). `deleted_comment` doesn't fire, so the recurring-events occurrence mapping is cleaned when the trash is emptied. Past RSVPs kept on purpose (attendance history). |
| `groups-site` activatable on non-groups-network sites | Not covered (network-admin action) | Try `wp theme activate groups-site --url=<non-groups-network-site>`; it should be blocked. |
