# nola-deploy

High-performance deployment CLI for Magento 2. Smart change detection, parallel static content deployment, dependency-aware theme compilation, and zero-downtime capabilities.

## Why

Deploying Magento 2 is notoriously painful. Every existing option has significant trade-offs:

**Custom `deploy.sh` scripts** (most common) — run everything every time, full maintenance mode for 60-90 seconds, no rollback, no error recovery. Works until it doesn't.

**[Deployer](https://deployer.org/)** — powerful PHP deployment tool, but requires writing `deploy.php` + `hosts.yml`, manually hardcoding themes and locales (no auto-detection), and **runs the full pipeline every deploy** regardless of what changed. Zero-downtime needs artifact mode with a separate build host. Multiple competing community recipes ([jalogut](https://github.com/jalogut/magento2-deployer-plus), [swiftotter](https://github.com/swiftotter/deployer-magento2-recipes)) signal gaps people keep working around.

**[ece-tools](https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/dev-tools/ece-tools/package-overview)** (Adobe Commerce Cloud) — the most complete Magento deployment automation, but **completely unusable outside Adobe Cloud**. Hard dependency on Adobe's managed infrastructure. 4 config files, proprietary build containers. Useful only as a reference.

**[Capistrano](https://github.com/davidalger/capistrano-magento2)** — real atomic symlink deployments, but requires Ruby on your deploy machine. No change detection. Full rebuild every time.

**The gap every tool misses: change detection.** You changed one CSS file? Every tool above still runs `di:compile` (30-40s) + full SCD for all themes (20-30s). That's the 70+ seconds you wait for nothing.

**nola-deploy** fills this gap:

- **One command install** — `composer require`, no Ruby, no separate servers, no YAML manifests
- **Auto-detects everything** — queries your database for stores, themes, locales (Magento scope fallback)
- **Skips what didn't change** — theme-only change? Skip `di:compile`. PHP-only? Skip SCD.
- **Zero DevOps overhead** — no deploy recipes to write, no host configs, no build servers
- **Works with or without git** — hash-based fallback for teams that edit files directly

## Benchmark

Tested on Magento 2.4.7 with Luma theme, PHP 8.3, macOS.

| Scenario | Standard Magento | nola-deploy | Savings |
|----------|-----------------|-------------|---------|
| Full clean deploy | 71s | 59s | 17% faster |
| Repeat full deploy | 71s | 46s | 35% faster |
| Theme-only change | 71s | 3.6s | **95% faster** |
| No changes | 71s | 1.0s | **99% faster** |

The more themes, locales, and modules you have, the bigger the improvement.

## How It Works

```
vendor/bin/nola-deploy init     ← auto-detect stores, themes, locales from DB
vendor/bin/nola-deploy deploy   ← smart incremental deploy
```

1. **Change detection** — compares git diff + file hashes against last deploy manifest
2. **Smart skipping** — only theme changes? Skip `di:compile`. Only PHP changes? Skip SCD.
3. **Parallel SCD** — deploys themes in parallel, respecting parent→child dependency order
4. **Dependency-aware** — Magento/blank deploys before Magento/luma (parent first)
5. **Vendor tracking** — `composer require` a new module? `composer.lock` change triggers full rebuild automatically

## Installation

```bash
composer require nola/deploy
```

This installs the CLI at `vendor/bin/nola-deploy`.

## Quick Start

### 1. Initialize

```bash
vendor/bin/nola-deploy init
```

This will:
- Check your environment (PHP version, DB connection, extensions, disk space)
- Query the database for store → theme → locale mapping
- Generate `.nola-deploy.yaml` in your Magento root

```
▸ Checking environment
    [OK] PHP 8.3.22
    [OK] bin/magento
    [OK] app/etc/env.php
    [OK] app/etc/config.php
    [OK] PHP extensions (6 required)
    [OK] Database connection
    [OK] Disk space (271.2 GB free)
    [OK] Composer vendor

▸ Detecting store configuration
  Found 1 store view(s) + admin
    default: Magento/luma [en_US]
    admin: Magento/backend [en_US]

  ✓ Config written to: .nola-deploy.yaml
```

### 2. Review the config

Open `.nola-deploy.yaml` — it's fully commented:

```yaml
# Each store view maps to a theme + locales. Detected from your database.
stores:
  default:
    theme: Magento/luma
    locales:
      - en_US
  admin:
    theme: Magento/backend
    locales:
      - en_US

static_content:
  strategy: quick
  parallel_jobs: 4

cache:
  flush_all: true

maintenance:
  page: nola-deploy-maintenance.html

post_deploy: []
# post_deploy:
#   - bin/magento indexer:reindex
```

### 3. Deploy

```bash
# Smart incremental deploy (detects changes, skips what's unnecessary)
vendor/bin/nola-deploy deploy

# First time or want clean slate
vendor/bin/nola-deploy deploy:fresh

# See what changed without deploying
vendor/bin/nola-deploy deploy:diff
```

If you haven't run `init` yet, deploy commands will remind you:

```
⚠ No .nola-deploy.yaml found.

  Run this first to set up your deployment config:

    vendor/bin/nola-deploy init
```

## Commands

| Command | Description |
|---------|-------------|
| `init` | Check environment, auto-detect config, generate `.nola-deploy.yaml` |
| `deploy` | Smart incremental deploy (default command) |
| `deploy:fresh` | Clean everything and deploy from scratch |
| `deploy:diff` | Show what changed and what steps would run |
| `status` | Show environment info, themes, locales, last deploy |
| `benchmark` | Compare baseline vs optimized deploy speed |

### Common Options

```bash
# Force full rebuild (ignore change detection)
vendor/bin/nola-deploy deploy --force

# Enable maintenance mode with custom page during deploy
vendor/bin/nola-deploy deploy -m

# Preview without executing
vendor/bin/nola-deploy deploy --dry-run

# Skip setup:upgrade (when OpenSearch/Elasticsearch is not running locally)
vendor/bin/nola-deploy deploy --skip-upgrade

# Override parallel workers
vendor/bin/nola-deploy deploy -j 8

# Deploy specific themes only
vendor/bin/nola-deploy deploy --themes=Vendor/mytheme

# Deploy specific locales only
vendor/bin/nola-deploy deploy --locales=en_US,fr_FR
```

## Configuration

### `.nola-deploy.yaml`

The config file lives in your Magento root. Generate it with `init`, then customize as needed.

#### Store Mapping

The most important section. Defines what themes and locales to deploy per store view. Auto-detected from your database with Magento scope fallback (store → website → default).

```yaml
stores:
  default:
    theme: Magento/luma
    locales:
      - en_US
  french_store:
    theme: Vendor/custom-theme
    locales:
      - fr_FR
      - fr_BE
  admin:
    theme: Magento/backend
    locales:
      - en_US
```

#### Cache Control

```yaml
# Flush everything (default)
cache:
  flush_all: true

# Or flush specific types only
cache:
  flush_all: false
  types:
    - config
    - layout
    - block_html
    - full_page
```

#### Custom Maintenance Page

```yaml
maintenance:
  page: nola-deploy-maintenance.html
```

Create a single HTML file at that path (relative to Magento root). When you deploy with `-m`:
1. Your HTML becomes the maintenance page automatically
2. Magento maintenance mode is enabled
3. Deployment runs
4. Maintenance mode is disabled, original page restored

If the file doesn't exist, a clean built-in template is used.

#### Post-Deploy Commands

```yaml
post_deploy:
  - bin/magento indexer:reindex
  - curl -s https://hooks.slack.com/... -d '{"text":"Deploy complete"}'
```

Shell commands that run from Magento root after deployment completes. Each command runs independently — one failure doesn't block the rest.

#### Static Content Strategy

```yaml
static_content:
  strategy: quick      # quick | standard | compact
  parallel_jobs: 4     # number of parallel SCD workers
  use_node_less: true  # use Node.js LESS compiler (faster)
```

#### DI Compilation

```yaml
di_compile:
  enabled: true
  cache: true        # cache compiled DI for incremental builds
  gc_disable: true   # disable PHP GC during compile (~30% faster)
```

## Change Detection

nola-deploy tracks what changed since the last deploy and only runs what's needed.

### Git-based (preferred)

When running in a git repository:
- Diffs committed changes since last deploy (`git diff <last-commit>..HEAD`)
- Includes uncommitted modifications (staged + unstaged)
- Includes untracked new files in `app/code`, `app/design`, `app/etc`
- Detects `composer.lock` changes (new modules installed via `composer require`)

### Hash-based (fallback)

When git is not available (direct file edits, no repo):
- SHA-256 hashes of: `composer.lock`, `config.php`, `vendor/composer/installed.json`
- Directory hashes for `app/code` and `app/design`
- Compared against stored hashes from last deploy

### What Gets Skipped

| Change Type | di:compile | SCD | cache:flush |
|-------------|-----------|-----|-------------|
| PHP files only | Run | Skip | Run |
| Theme/CSS/LESS only | Skip | Run | Run |
| Both PHP + theme | Run | Run | Run |
| `composer.lock` changed | Full rebuild | Full rebuild | Run |
| No changes | Skip | Skip | Run |

### Vendor Changes

When you `composer require` a new module or update an existing one, `composer.lock` changes. nola-deploy detects this and triggers a **full rebuild** (di:compile + SCD for all themes). The `vendor/` directory itself is not tracked (it's gitignored) — `composer.lock` is the reliable signal.

For the hash-based fallback (no git), `vendor/composer/installed.json` is also checked.

## File Structure

```
magento-root/
├── .nola-deploy.yaml              ← your config (generated by init)
├── nola-deploy-maintenance.html   ← custom maintenance page (optional)
├── var/nola-deploy/
│   └── manifest.json              ← deploy history + hashes (auto-managed)
└── vendor/nola/deploy/            ← the package (via composer)
    ├── bin/nola-deploy
    ├── config/nola-deploy.yaml.dist
    ├── src/
    └── templates/maintenance.html
```

## Requirements

- PHP 8.1+
- Magento 2.4.x
- Required PHP extensions: `pdo_mysql`, `intl`, `mbstring`, `json`, `dom`, `simplexml`

## Development

```bash
git clone git@github.com:mrloc2015/nola-deploy.git
cd nola-deploy
composer install
vendor/bin/phpunit
```

## License

MIT
