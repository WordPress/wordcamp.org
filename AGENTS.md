# Agent instructions for `WordPress/wordcamp.org`

## Repository shape

- Main application code is under `public_html/wp-content/` in plugins,
  mu-plugins, and themes.
- PHP dependencies are managed at the repository root by `composer.json`.
  The minimum PHP version is 8.3; CI tests against 8.3, 8.4, and 8.5.
- JavaScript dependencies use npm workspaces from the repository root through
  `package.json` and `package-lock.json`.
- CI definitions to mirror are:

  - `.github/workflows/linter.yml`
  - `.github/workflows/unit-tests.yml`

## Preferred workflow

1. Start at the repository root.
2. Install JavaScript dependencies with `npm ci`.
3. Run the smallest relevant checks for touched files, then expand if needed.
4. For final pull-request verification, mirror CI commands as closely as
   possible.

## Groups site UI

When work changes or reviews public-facing UI in either of these paths:

- `public_html/wp-content/themes/groups-site/`
- `public_html/wp-content/mu-plugins/wporg-groups-frontend/`

Read and follow `docs/design/groups-site.md` in full before editing.

- Treat `public_html/wp-content/themes/groups-site/theme.json` as the canonical source for available design tokens.
- Prefer existing WordPress preset variables over raw colors, font sizes, or arbitrary spacing values.
- Preserve semantic heading levels and DOM/keyboard order even when the visual treatment differs.
- Before review, verify the interaction states and responsive/accessibility matrix in the design guide.
- If approved task-specific design direction conflicts with the guide, follow the newer direction and update the guide or explicitly record the discrepancy.

## Canonical validation commands

### JavaScript and CSS

- Install dependencies: `npm ci`
- Build all workspaces: `npm run build --workspaces --if-present`
- Lint JavaScript: `npm run lint:js --workspaces --if-present`
- Lint CSS: `npm run lint:css --workspaces --if-present`
- Test WordCamp blocks: `npm run test --workspace=wordcamp-blocks`
- Test the Groups frontend: `npm run test --workspace=wporg-groups-frontend`

### PHP

- Install dependencies: `composer install`
- Run the lint script: `composer run lint`
- Run the test script: `composer run test`

CI-specific PHP parity also requires:

- Subversion installed before Composer on a fresh runner.
- The WordPress test suite installed with
  `bash .docker/bin/install-wp-tests.sh wcorg_test root root "127.0.0.1:${DB_PORT}" latest true`,
  where `DB_PORT` is the mapped MariaDB service port.
- GatherPress installed at the version pinned by CI/local development.
- Groups frontend blocks built before the Groups PHPUnit suite runs.
- PHPUnit run directly with
  `./public_html/wp-content/mu-plugins/vendor/bin/phpunit -c phpunit.xml.dist`.
- Changed-lines PHPCS run in CI style with
  `BASE_REF=<base-branch> php .github/bin/phpcs-branch.php`.

## Known setup errors and workarounds

These may occur in fresh environments:

1. `composer install` fails with `Could not authenticate against github.com`.
   Private or VCS dependencies require GitHub authentication. Provide a valid
   GitHub token through Composer authentication, then retry.
2. `composer run lint` reports `phpcs: not found`. PHP tooling was not
   installed because Composer did not complete. Fix Composer first.
3. `composer run test` cannot find
   `/tmp/wp/wordpress-tests-lib/includes/functions.php`. Install the WordPress
   PHPUnit test suite before running PHPUnit.
4. npm reports deprecation or optional-dependency warnings. Treat them as
   non-blocking unless the command exits non-zero.

## PHP test environments

The Docker workflows in `.docker/readme.md` are for local development:

| Environment | Compose file | Purpose |
|---|---|---|
| Full development | `docker-compose.yaml` | Local browser testing |
| PHPUnit only | `docker-compose.phpunit.yml` | Isolated local PHP tests with Docker-specific database credentials |

### GitHub Actions and Copilot Cloud Agent

Copilot Cloud Agent runs in a GitHub Actions-style environment. Use the CI
service and dependency sequence rather than the local Docker credentials:

1. Provide a MariaDB LTS service with database `wcorg_test`, root password
   `root`, and a mapped port on `127.0.0.1`.
2. Install Subversion.
3. Run `composer install` with GitHub authentication available.
4. Install the WordPress test suite:

   ```bash
   bash .docker/bin/install-wp-tests.sh wcorg_test root root "127.0.0.1:${DB_PORT}" latest true
   ```

5. Install GatherPress at the version used by CI.
6. Run `npm ci`, then build the Groups frontend workspace:

   ```bash
   npm run build --workspace=public_html/wp-content/mu-plugins/wporg-groups-frontend
   ```

7. Run PHPUnit:

   ```bash
   ./public_html/wp-content/mu-plugins/vendor/bin/phpunit -c phpunit.xml.dist
   ```

The local Docker PHPUnit environment uses database `wordpress_test`, an empty
root password, and host `phpunit_db`. Do not use those credentials in the
CI-style sequence.

## Practical scope guidance

- If changes are limited to one workspace, prefer
  `npm run <script> --workspace=<name>` where available.
- If PHP dependencies cannot be installed because authentication is
  unavailable, complete relevant JavaScript-only or documentation-only work
  and report which PHP validations were blocked.
- Avoid broad unrelated refactors; this repository is large and
  multi-component.
