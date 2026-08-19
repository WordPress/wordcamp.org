---
name: create-pr
description: End-to-end workflow for shipping a non-trivial feature or fix as a pull request in this repo — plan and get sign-off, implement, verify with PHPUnit/phpcs/build, verify manually in a real browser session, open a draft PR with a structured description, and triage/respond to review comments. Use when asked to implement something and open a PR, or to "ship" a change end-to-end.
---

# Create a PR

Phases in order — each catches something the previous one can't.

## 1. Plan

Skip only for trivial changes. Otherwise: read the closest existing
analogues in this repo and copy their pattern rather than inventing a new
one. Use `AskUserQuestion` for genuine open design forks, `EnterPlanMode`
for anything multi-file, and get explicit sign-off before writing code.

## 2. Implement

Match existing conventions file-by-file. Prefer narrow, purpose-built
capability/permission checks over broad grants (e.g. a role check or a
specific documented capability) — a broad grant tends to unlock more than
intended. `php -l` (or the language equivalent) after every edit.

## 3. Automated tests

```bash
docker exec wordcamporg-phpunit_wp-1 bash -lc 'cd /app && phpunit --testsuite "<suite name>"'
public_html/wp-content/mu-plugins/vendor/bin/phpcs <files>   # host only — the
                                                               # container lacks phpcs.xml.dist
public_html/wp-content/mu-plugins/vendor/bin/phpcbf <files>  # auto-fix
cd public_html/wp-content/mu-plugins/<plugin>/ && npm run build   # if JS changed
```

Check `phpunit.xml.dist` for the suite name covering the changed area, and
run the broader regression suite too before opening the PR.

New code needs new tests, not just a green existing suite — happy path,
every permission gate, edge-case invariants. If the feature sends email,
look for an existing `pre_wp_mail`-capture test elsewhere in the suite and
follow its pattern rather than inventing a new mocking approach.

Test-env gotchas:
- `Database_TestCase` truncates sitemeta but not the object cache, so
  `update_site_option('site_admins', ...)` to simulate a super admin is
  unreliable across a full suite run. Set `$GLOBALS['super_admins']`
  directly instead (unset in `tearDown()`).
- A check that reads `wp_get_current_user()` needs `wp_set_current_user()`
  in the test even if the function also takes an explicit user-ID param for
  something else — verify what the function actually authorizes off.

## 4. Manual browser verification

A green suite isn't proof — it exercises functions directly, not the
rendered UI. Use `claude-in-chrome` against the local dev stack. Default
login `admin`/`password`; reset via:
```bash
docker exec wordcamporg-wordcamp.test-1 bash -lc \
  'wp --path=/usr/src/public_html/mu user update admin --user_pass=password --allow-root'
```

Test as every distinct actor/role the feature has, not just the happy-path
user — log out/in between each. `wp super-admin add <user>` grants on
whichever network `--url=` points at; pass the target network's URL
explicitly or the grant silently lands on the wrong network.

Known flakiness, and the fix:
- **Coordinates go stale** if the viewport resizes mid-session (observed
  with no explicit resize action taken). Prefer `find` + click-by-`ref`;
  re-screenshot before assuming a click failed for a logic reason.
- **Login submit can silently no-op** (a documented per-user session-limit
  quirk in this stack). Click the password field and press `Return` instead
  of clicking submit; confirm login by checking the toolbar/"Howdy" before
  proceeding.
- **Never click a `window.confirm()`-guarded button** — it blocks the page
  and automation can't interact with the native dialog. `Escape` dismisses
  it safely if triggered by accident. To exercise the action anyway, submit
  the underlying form directly instead of clicking:
  ```js
  document.querySelector('form:has(input[name="field"][value="value"])').submit();
  ```

Screenshot each state transition with `save_to_disk: true`. Cross-check end
state against the DB directly (`wp <entity> list --fields=...`), not just
what the UI shows. Clean up test fixtures afterward.

## 5. Branch and draft PR

1. `gh api repos/<owner>/<repo> --jq '.permissions'` — branch directly off
   upstream if `push: true`, else fork.
2. Branch off main, name it `<area>/<feature>`. Stage only files that belong
   to the change.
3. Commit message explains *why*; end with
   `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. If a heredoc
   commit fails on quoting, write the message to a temp file and use
   `git commit -F <file>`.
4. `gh pr create --draft` with this description structure:

```markdown
## Summary
<What this closes/fixes, a few bullets naming the core mechanism.>

## How to review this PR
<Only for large/complex PRs. Short: suggested file order + 3-5 bullets on
decisions a reviewer would otherwise have to reverse-engineer.>

## Test plan
- [x] phpunit — N/N passing
- [x] phpcs clean
- [x] npm run build (if applicable)
- [x] Manual browser pass (steps below)

### Manual test steps
<Numbered, one step per actor/action. Note what was exercised manually vs.
only covered by automated tests — don't overclaim.>

#### Screenshots
| Description | Screenshot |
| --- | --- |
```

If the user attached images to the PR, fetch the body first
(`gh pr view <n> --json body -q .body`) — attachments already appear there
as `<img src="https://github.com/user-attachments/...">`; rearrange into the
table rather than asking for URLs separately.

## 6. Respond to review comments

1. Fetch all comments first: `gh api repos/<owner>/<repo>/pulls/<n>/comments --jq '.[] | {id, path, line, body}'`.
2. Verify each against actual current code — don't trust the framing. A
   correct-in-principle suggestion can still be inapplicable to *this* PR
   (e.g. fixing 2 new lines while a dozen pre-existing identical ones stay
   unfixed makes the file more inconsistent, not less) — decline explicitly
   with that reasoning rather than silently skipping or half-applying it.
3. Fix what's valid, each with a regression test. Re-run the full suite.
4. Reply to each comment thread individually (not one bundled comment):
   `gh api repos/<owner>/<repo>/pulls/<n>/comments/<id>/replies -f body="..."`.
   For anything you're not acting on, say so and why.
5. Update stale test-plan numbers in the PR description while you're in there.
