# AI Agent Instructions for wordcamp.org

## Project Overview

WordCamp.org is a WordPress multisite network that powers WordCamp event websites. It runs as a collection of WordPress plugins and themes on top of WordPress core.

- **Main branch:** `production`
- **Language:** PHP (WordPress), JavaScript, CSS
- **Package managers:** Composer (PHP), Yarn (JS)
- **Test framework:** PHPUnit (integration tests preferred over unit tests)

## Repository Structure

```
public_html/
  mu/                          # WordPress core (git submodule, not committed)
  wp-content/
    mu-plugins/                # Must-use plugins (network-wide)
    plugins/                   # Standard plugins
      wcpt/                    # WordCamp Post Type (central tracker)
      wordcamp-organizer-reminders/  # Automated email reminders
      wc-post-types/           # Session, speaker, sponsor post types
      ...
    themes/                    # WordCamp themes
.docker/                       # Docker development environment
.github/workflows/             # CI: linting + PHPUnit tests
```

## Development Environment

The project uses Docker for local development. Full setup instructions are in `.docker/readme.md`.

### Prerequisites

- Docker installed and running
- WordPress core cloned into `public_html/mu` (check out latest version branch, e.g. `6.9`)
- PHP dependencies installed: `composer install` (from project root)
- JS dependencies: `nvm use 20 && yarn && yarn workspaces run build`
- SSL certs generated in `.docker/` (see `.docker/readme.md` step 3)

### Quick Start

```bash
docker compose up -d
docker compose logs --tail=5 wordcamp.test
# Wait for: "NOTICE: ready to handle connections"
```

### Key URLs (local)

- WordCamp Central admin: `https://central.wordcamp.test/wp-admin/`
- Sample WordCamp site: `https://2014.seattle.wordcamp.test`
- MailCatcher (captures outgoing emails): `http://localhost:1080`
- Login: username `admin`, password `password`

### Hosts file entries required

```
127.0.0.1 wordcamp.test central.wordcamp.test seattle.wordcamp.test shinynew.wordcamp.test events.wordpress.test
```

### Stopping

```bash
docker compose stop
```

Use `stop` (not `down`) to avoid re-provisioning 3rd-party plugins on next start.

## Testing

### CI

PHPUnit tests run automatically on PRs via GitHub Actions (`.github/workflows/unit-tests.yml`). Tests run against PHP 8.1 and 8.5. PHPCS linting also runs.

### Running PHPUnit Locally (Docker)

```bash
docker compose -f docker-compose.phpunit.yml up -d

# First time only:
docker compose -f docker-compose.phpunit.yml exec phpunit_wp bash
/var/scripts/install-wp-tests.sh wordpress_test root '' phpunit_db latest true

# Run tests:
docker compose -f docker-compose.phpunit.yml exec phpunit_wp phpunit

# Stop:
docker compose -f docker-compose.phpunit.yml stop
```

### Test Guidelines

- Use both unit tests and integration tests where appropriate.
- Do not duplicate tests — only test things that make a difference to the application.
- Test files live alongside their plugin code in a `tests/` directory.

## Pull Request Requirements

PRs should include:

- **Summary:** Bullet points describing what changed and why.
- **Fixes/issue reference:** Link to the GitHub issue if applicable (e.g. `Fixes #1234`).
- **Changes table** (for larger PRs): A table mapping files to what changed in each.
- **Screenshots** (for UI changes): Descriptions or images showing the visual changes.
- **Test plan:** A checklist of manual testing steps a reviewer can follow to verify the changes.

Keep the title short (under 70 characters). Use the body for detail.

## Coding Standards

- Follow WordPress Coding Standards (PHPCS enforced in CI).
- Inline comments must end in full-stops, exclamation marks, or question marks.
- Use `// phpcs:ignore` comments for intentional violations (e.g. `$WCOR_Mailer` variable naming).

## Shell Access (Docker)

```bash
docker compose exec wordcamp.test bash    # PHP/nginx container
docker compose exec wordcamp.db bash      # MySQL container
```

## Database

Data persists in `.docker/database/`. To reset:

```bash
docker compose exec wordcamp.test bash
bash /var/scripts/database.sh reset
```
