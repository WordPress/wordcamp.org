# Copilot cloud agent instructions for `WordPress/wordcamp.org`

## Repository shape

- Main app code is under `public_html/wp-content/` (plugins, mu-plugins, themes).
- PHP dependencies are managed at repo root by `composer.json`.
- JavaScript uses Yarn workspaces from repo root (`package.json` + `yarn.lock`).
- CI definitions to mirror are:
  - `.github/workflows/linter.yml`
  - `.github/workflows/unit-tests.yml`

## Preferred workflow for agents

1. Start at repo root.
2. Install JS deps first: `yarn`.
3. Run only the smallest relevant checks for touched files, then expand if needed.
4. For PR-final verification, mirror CI commands as closely as possible.

## Canonical validation commands

### JavaScript / CSS (root)

- Install deps: `yarn`
- Build all workspaces: `yarn workspaces run build`
- Lint JS: `yarn workspaces run lint:js`
- Lint CSS: `yarn workspaces run lint:css`
- JS unit tests (currently blocks): `yarn workspace wordcamp-blocks run test`

### PHP

- Install deps: `composer install`
- Lint script: `composer run lint`
- Test script: `composer run test`

CI-specific PHP steps (for full parity) also include:

- Install SVN package before composer on fresh runners.
- Install WordPress test suite: `bash .docker/bin/install-wp-tests.sh wcorg_test root root 127.0.0.1 latest`
- Run PHPUnit directly: `./public_html/wp-content/mu-plugins/vendor/bin/phpunit -c phpunit.xml.dist`
- For changed-lines PHPCS in CI style: `BASE_REF=<base-branch> php .github/bin/phpcs-branch.php`

## Known setup errors and workarounds

These were encountered during onboarding validation and should be expected in fresh environments:

1. **`composer install` auth failure (`Could not authenticate against github.com`)**
   - Cause: private/VCS dependencies require GitHub auth.
   - Workaround: provide a valid GitHub token for Composer auth (e.g., `COMPOSER_TOKEN` / GitHub credentials), then re-run `composer install`.

2. **`composer run lint` -> `phpcs: not found`**
   - Cause: PHP tooling is installed via Composer and is unavailable when `composer install` fails.
   - Workaround: fix Composer auth/install first, then rerun lint.

3. **`composer run test` fails on missing `/tmp/wp/wordpress-tests-lib/includes/functions.php`**
   - Cause: WordPress PHPUnit test suite not installed yet.
   - Workaround: run `.docker/bin/install-wp-tests.sh ...` (same pattern as CI) before PHPUnit.

4. **Node/Yarn deprecation and optional dependency warnings**
   - Observed during `yarn` and workspace commands; commands still completed successfully.
   - Workaround: treat as non-blocking unless command exits non-zero.

## Running PHP tests in Copilot's environment

> **Copilot runs in a GitHub Actions-style environment — Docker is not required or used for tests.**
> The Docker-based workflows described in `.docker/readme.md` are for local developer machines only.

### Two environments described in `.docker/readme.md`

| Environment | Compose file | Purpose |
|---|---|---|
| Full dev environment | `docker-compose.yaml` | Local browser testing; not relevant to Copilot |
| PHPUnit-only environment | `docker-compose.phpunit.yml` | Isolated local PHP test runs; uses different DB credentials than CI |

### CI-style PHP test sequence (what Copilot should follow)

1. **MySQL** — a MySQL 5.7 instance must be available. In CI this is a service container:
   - database: `wcorg_test`, root password: `root`, host: `127.0.0.1`, port: `3306`
2. **SVN** — required before composer install on bare runners:
   ```bash
   sudo apt-get install -y subversion
   ```
3. **PHP dependencies** — requires a GitHub token (`COMPOSER_TOKEN` env var or configured auth):
   ```bash
   composer install
   ```
4. **WordPress test suite** — installs the test bootstrap into `/tmp/wp/`:
   ```bash
   bash .docker/bin/install-wp-tests.sh wcorg_test root root 127.0.0.1 latest
   ```
5. **PHPUnit**:
   ```bash
   ./public_html/wp-content/mu-plugins/vendor/bin/phpunit -c phpunit.xml.dist
   ```

> ⚠️ The local Docker PHPUnit environment uses different credentials (`database: wordpress_test`, empty root password, host: `phpunit_db`). Do **not** use those credentials in the CI-style sequence above.

## Practical scope guidance

- If changes are limited to one workspace, prefer `yarn workspace <name> run <script>` where available.
- If PHP deps cannot be installed due to auth constraints, complete JS-only/doc-only tasks and explicitly report which PHP validations were blocked.
- Avoid broad unrelated refactors; this repository is large and multi-component.
