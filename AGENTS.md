# WordCamp.org Repository Guide

## Overview

WordPress Multisite application powering WordCamp.org. The codebase lives under `public_html/` as a standard WordPress installation with custom mu-plugins, plugins, and themes.

## Branch & PR Conventions

- Default branch: `production`
- PR branch format: `{fix|create|add|remove}/claude/{ISSUE_NUMBER}-{SHORT_DESCRIPTOR}`

## Node.js & JavaScript

- **Node 20** (see `.nvmrc`)
- **Yarn 1 workspaces** (9 workspaces, see root `package.json`)
- **`@wordpress/scripts` 26.18.0** across all workspaces (provides webpack, eslint, jest, stylelint)
- Line length: 115 characters (enforced by eslint, stylelint, and prettier)
- Text domain: `wordcamporg`
- Short array syntax (`[]`) is used throughout, not `array()`

### Workspaces

| Workspace | Path | Has Tests |
|-----------|------|-----------|
| wordcamp-blocks | `public_html/wp-content/mu-plugins/blocks` | Yes (Jest) |
| virtual-embeds | `public_html/wp-content/mu-plugins/virtual-embeds` | No |
| multi-event-sponsors | `public_html/wp-content/plugins/multi-event-sponsors` | No |
| wc-post-types | `public_html/wp-content/plugins/wc-post-types` | No |
| wcpt | `public_html/wp-content/plugins/wcpt` | No |
| wordcamp-forms-to-drafts | `public_html/wp-content/plugins/wordcamp-forms-to-drafts` | No |
| wordcamp-speaker-feedback | `public_html/wp-content/plugins/wordcamp-speaker-feedback` | No |
| wporg-events-2023 | `public_html/wp-content/themes/wporg-events-2023` | No |
| wporg-flagship-landing | `public_html/wp-content/themes/wporg-flagship-landing` | No |

### Common Commands

```sh
yarn                           # Install all workspace dependencies
yarn workspaces run build      # Build all workspaces
yarn workspaces run lint:js    # Lint JS in all workspaces
yarn workspaces run lint:css   # Lint CSS in all workspaces
yarn workspace wordcamp-blocks run test  # Run Jest tests (only workspace with JS tests)
```

### Jest Configuration (wordcamp-blocks)

- Config: `public_html/wp-content/mu-plugins/blocks/jest.config.js`
- Extends `@wordpress/scripts/config/jest-unit.config.js`
- Uses `react-test-renderer` for snapshot tests (not enzyme)
- `uuid` is mapped to its CJS entry via `moduleNameMapper` to avoid ESM resolution issues in jsdom
- Snapshots are in `__snapshots__/` directories adjacent to test files

### Linting Configuration

- ESLint: root `.eslintrc.js` extends `@wordpress/eslint-plugin/recommended-with-formatting`
- StyleLint: root `.stylelintrc` extends `@wordpress/stylelint-config/scss`
- Prettier: root `.prettierrc.js` extends `@wordpress/scripts/config/.prettierrc.js`
- Each workspace references the root configs via relative paths (e.g. `"extends": "../../../../.eslintrc.js"`)

### ESLint Specifics

Key rule overrides in `.eslintrc.js` to be aware of:
- `id-length`: minimum 3 chars (exceptions: `__`, `_n`, `_x`, `id`, `a`, `b`, `i`, `$`)
- `camelcase`: off (REST API properties use snake_case)
- `sort-imports`: member sorting enforced, declaration sorting ignored (to allow External/WordPress/Internal grouping)
- `object-shorthand`: `consistent-as-needed`
- `react/no-multi-comp`: one component per file (stateless exceptions allowed)

## PHP

- **PHP 8.1+** minimum — modern PHP syntax is preferred (named arguments, match expressions, null-safe operator, readonly properties, etc.)
- **Composer** with vendor dir at `public_html/wp-content/mu-plugins/vendor`
- **PHPUnit 9** with WordPress test suite integration (multisite enabled)
- **WPCS** (WordPress Coding Standards) via phpcs

### Common Commands

```sh
composer install               # Install PHP dependencies
composer lint                  # Run phpcs
composer format                # Run phpcbf auto-fixer
composer test                  # Run PHPUnit
composer test -- --filter=test_function_name       # Run a single test
composer test -- --testsuite="CampTix"             # Run a specific test suite
composer test:watch            # Watch mode (requires tty)
composer phpcs-changed         # Lint only changed lines vs production (what CI runs)
```

### PHPUnit Test Suites

Defined in `phpunit.xml.dist`, bootstrapped by `phpunit-bootstrap.php`:
- WordCamp MU Plugins, CampTix, Organizer Reminders, Budgets Dashboard
- WordCamp Post Types (`wc-post-types`), WordCamp Post Type (`wcpt`)
- WordCamp Remote CSS, WordCamp Speaker Feedback

Test files must be prefixed with `test-` (e.g. `test-something.php`). The bootstrap sets up a multisite environment with specific blog/network IDs defined as constants (`WORDCAMP_NETWORK_ID`, `WORDCAMP_ROOT_BLOG_ID`, etc.).

### CI Linting (PHP)

The linter workflow runs `phpcs-changed` via `.github/bin/phpcs-branch.php`, which only reports violations on changed lines relative to `production`. New files are linted in full. This means existing code may have violations that won't trigger CI failures unless you modify those lines.

### PHPCS Specifics

Key rule exclusions in `phpcs.xml.dist` to be aware of:
- Short ternary (`?:`) is allowed
- `trigger_error()` and `print_r()` are allowed
- Precision alignment is allowed (for readable code alignment)
- Short array syntax (`[]`) is preferred over `array()`
- No prefix requirements for globals (impractical for this codebase)

## CI (GitHub Actions)

Two workflows in `.github/workflows/`:

1. **linter.yml** - Builds all JS workspaces, runs JS lint, CSS lint, and PHP lint
2. **unit-tests.yml** - Runs JS tests (wordcamp-blocks) and PHP tests across PHP 8.1, 8.4, 8.5 with MySQL 5.7

Both trigger on PRs touching `public_html/**` or `.github/workflows/**`.

## Docker

Local development uses Docker Compose (`.docker/`):
- `wordcamp.test` - PHP-FPM + Nginx (ports 80, 443, 1080 for MailCatcher)
- `wordcamp.db` - MySQL 5.7 (port 3307)

## .gitignore Notes

Most third-party plugins are gitignored. Only custom WordCamp plugins and select third-party ones (camptix, edit-flow, liveblog, etc.) are tracked. The `node_modules/`, `vendor/`, `build/` directories, and `package-lock.json` are all ignored. `yarn.lock` IS tracked.

## Lessons Learned

- When upgrading `@wordpress/scripts`, all workspaces should use the same major version to avoid Yarn hoisting conflicts (e.g. Jest 27 vs 29 components at different `node_modules/` levels).
- The `@wordpress/eslint-plugin` is bundled inside `@wordpress/scripts` - listing it separately in workspace `devDependencies` is redundant and can pull in older transitive deps with incompatible engine requirements.
- Newer versions of `uuid` ship ESM-only via the `browser` field in jsdom environments. Map it to CJS in `jest.config.js` `moduleNameMapper` if tests fail with `SyntaxError: Unexpected token 'export'`.
- The `--entry` CLI flag for `wp-scripts build` was removed in `@wordpress/scripts` 26.x. Use positional arguments instead (e.g. `wp-scripts build ./src/file.js`).
- Upgrading `eslint-plugin-jsdoc` (via `@wordpress/scripts`) may surface new lint errors in existing code, particularly `jsdoc/require-returns-check` for functions with bare `return;` statements.
