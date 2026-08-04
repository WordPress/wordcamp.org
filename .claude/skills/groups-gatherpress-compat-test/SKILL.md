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
  end-to-end through the real browser UI, and the member directory.

If either automated suite fails, stop and fix that before doing the manual
pass below — don't duplicate debugging effort across layers.

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
  https://github.com/GatherPress/gatherpress/releases/download/0.34.0-alpha.2/gatherpress.0.34.0-alpha.2.zip \
  --activate --url=events.wordpress.test/group/sunshine-coast-qld/
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
- **RSVP** — as Member and as Event Organiser.
- **Group settings tabs** (Events / Venues / Members / Design / About) —
  confirm each tab loads without a console error or 403, and that
  Organiser-only tabs are entirely absent (not just disabled) for lower
  roles.
- **Member directory** (`/members/`) — role labels correct, join/leave
  buttons behave.
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
