---
name: create-pr
description: End-to-end workflow for shipping a non-trivial feature or fix as a pull request in this repo — plan and get sign-off, implement, verify with PHPUnit/phpcs/build, verify manually in a real browser session, open a draft PR with a structured description, and triage/respond to review comments. Use when asked to implement something and open a PR, or to "ship" a change end-to-end.
---

# Create a PR

Phases in order; each catches something the previous can't.

## 1. Plan

Skip only for trivial changes. Read the closest existing analogues and copy
their pattern. `AskUserQuestion` for genuine design forks, `EnterPlanMode`
for anything multi-file, and explicit sign-off before writing code.

## 2. Implement

Match conventions file by file. Prefer narrow capability/permission checks
(a role check, a specific documented capability) over broad grants, which
tend to unlock more than intended. `php -l` (or equivalent) after every edit.

## 3. Automated tests

```bash
docker exec wordcamporg-phpunit_wp-1 bash -lc 'cd /app && phpunit --testsuite "<suite name>"'
public_html/wp-content/mu-plugins/vendor/bin/phpcs <files>   # host only; the
                                                               # container lacks phpcs.xml.dist
public_html/wp-content/mu-plugins/vendor/bin/phpcbf <files>  # auto-fix
cd public_html/wp-content/mu-plugins/<plugin>/ && npm run build   # if JS changed
```

Find the suite for the changed area in `phpunit.xml.dist`, and run the
broader regression suite before opening the PR.

New code needs new tests: happy path, every permission gate, edge-case
invariants. For email, follow an existing `pre_wp_mail`-capture test rather
than inventing a mocking approach.

Test-env gotchas:
- `Database_TestCase` truncates sitemeta but not the object cache, so
  `update_site_option('site_admins', ...)` is unreliable across a full run.
  Set `$GLOBALS['super_admins']` directly (unset in `tearDown()`).
- A check reading `wp_get_current_user()` needs `wp_set_current_user()` even
  if the function also takes a user-ID param for something else. Verify what
  it actually authorizes off.

## 4. Manual browser verification

A green suite exercises functions, not the rendered UI. Use
`claude-in-chrome` against the local stack. Default login `admin`/`password`;
reset with:
```bash
docker exec wordcamporg-wordcamp.test-1 bash -lc \
  'wp --path=/usr/src/public_html/mu user update admin --user_pass=password --allow-root'
```

Test as every actor/role the feature has, logging out between them.
`wp super-admin add <user>` grants on whichever network `--url=` points at;
pass the target network's URL or it silently lands on the wrong one.

Known flakiness:
- **Coordinates go stale** if the viewport resizes (even unprompted). Prefer
  `find` + click by `ref`; re-screenshot before blaming logic for a failed click.
- **Login submit can silently no-op** (per-user session-limit quirk). Press
  `Return` in the password field; confirm "Howdy" in the toolbar.
- **Never click a `window.confirm()` button**: automation can't answer the
  native dialog (`Escape` dismisses it if triggered). Submit the form directly:
  ```js
  document.querySelector('form:has(input[name="field"][value="value"])').submit();
  ```

Screenshot each state transition with `save_to_disk: true`. Cross-check end
state in the DB (`wp <entity> list --fields=...`), not just the UI. Clean up
fixtures afterward.

## 5. Branch and draft PR

1. `gh api repos/<owner>/<repo> --jq '.permissions'`: branch off upstream if
   `push: true`, else fork.
2. Branch off main as `<area>/<feature>`. Stage only files belonging to the change.
3. Commit message explains *why*; end with
   `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. If a heredoc
   fails on quoting, write the message to a temp file and `git commit -F <file>`.
4. `gh pr create --draft` with:

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

If the user attached images, they're already in the body
(`gh pr view <n> --json body -q .body`) as
`<img src="https://github.com/user-attachments/...">`; move them into the
table instead of asking for URLs.

## 6. Respond to review comments

1. Fetch all first: `gh api repos/<owner>/<repo>/pulls/<n>/comments --jq '.[] | {id, path, line, body}'`.
2. Verify each against current code; don't trust the framing. A
   correct-in-principle suggestion can be wrong for *this* PR (fixing 2 new
   lines while a dozen identical pre-existing ones stay makes the file less
   consistent). Decline explicitly with the reasoning, never silently skip
   or half-apply.
3. Fix what's valid, each with a regression test. Re-run the full suite.
4. Reply per thread, not one bundled comment:
   `gh api repos/<owner>/<repo>/pulls/<n>/comments/<id>/replies -f body="..."`.
   Say why for anything you're not acting on.
5. Update stale test-plan numbers in the PR description.
