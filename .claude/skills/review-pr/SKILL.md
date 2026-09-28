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

## In the browser

- A green suite proves functions, not pages. Drive the real UI with `claude-in-chrome` against the local stack.
- The browser shows what a person sees. A raw request shows what the server sends. An attacker sends raw requests, so read both.
- Cookie flags, response headers and delivered markup are read off the response, never off the source.
- Walk it as every actor the change touches. Log out between them.
- Anonymous is an actor. Gated-content findings live there more often than anywhere else.
- Malformed input goes in the request, not the form. A field the page will not let you type into is not a field an attacker cannot send.
- Prefer `find` and click by ref. Coordinates go stale when the viewport moves under you.
- Login submit can silently no-op here. Press `Return` in the password field, then confirm on the toolbar before trusting anything after it.
- Never click a `window.confirm()` button. It blocks the page and automation cannot answer the dialog. Submit the form directly instead.
- Screenshot anything you intend to assert. A finding with a picture survives the round trip.

## Tests

- Break the code. Watch the test fail. Every time.
- A test that cannot fail is not a test.
- A test can pass for a reason you did not intend. Mutate the exact line you think it pins.
- Beware tests that do the work themselves. Drive the real trigger, then read the result back.
- Exercise the edges the PR claims in prose. Those are the wrong ones most often.
- New branch, no test, is a finding. Say so even when the branch works.

## Fixtures

- Suspect your fixtures before the code.
- A direct write earlier in the session can fake a bug no code path produces.
- Check the fixture can tell the cases apart. Identical fixtures make a test vacuous.
- Seed the data the edge case needs. Absent data passes every assertion.

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
- Every superglobal is attacker-typed. `isset` and `! empty` do not make it a string.
- A check in PHP and a comparison in MySQL are two different checks. Collations are `_ci`.
- Format is not entropy. Case folding is nothing to a random token and fatal to a fixed-format one.
- Follow the cookie scope. A network domain turns a per-site bug into a network one.
- Fix the class, not the instance. A patch that opens a new false-positive class is not the fix.
- Residual risk is accepted out loud or it is not accepted.

## Proving it

- Prove the bypass. A plausible bypass is not a finding.
- Run it in the container, at the version that ships.
- Query the real schema. Collation, charset, and casts belong to the table, not to your memory.
- Read core before reporting core behavior. Half of what you remember about it is one version stale.
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
- Conflict resolution is review work.
- "Keep both" can be syntactically wrong. Adjacent additions often share a closing brace.
- A conflict can offer two whole lines, each carrying a different shipped feature. Taking either reverts one.
- Lint and run suites on the merge result, not just the branches.
- After the last merge, check the features compose, not just coexist.

## Afterwards

- Reviews mutate local state. Write down what you touch, put it back.
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

# Log in without typing a password. Mint the cookie, then set it in the browser
docker compose exec -T wordcamp.test wp eval \
  'echo wp_generate_auth_cookie( <user_id>, time() + 3600, "logged_in" );' --url=<site>

# What the server actually sends. Flags, headers and body, with no browser in the way
curl -ksi -b 'tix_view_token=<value>' https://<site>/<path> | head -40

# Check what the database actually does. Collation decides comparisons, not the code
docker compose exec -T wordcamp.db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -t \
  wordcamp_dev -e "<query>"'
```

- Reproduce on the local multisite. Never against production.
- Squash merge only. One commit per PR, `(#NNNN)` in the subject.
- Never force push a pushed branch. Follow-up commits only.
