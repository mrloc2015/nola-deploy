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

Tested on Magento 2.4.7 with Luma theme, PHP 8.3.

| Scenario | Standard Magento | nola-deploy | Savings |
|----------|-----------------|-------------|---------|
| Full clean deploy | 78s | 60s | 23% faster |
| Vendor change (`composer require`) | 78s | 46s | 41% faster |
| PHP-only change (surgical DI) | 78s | 4.3s | **94% faster** |
| LESS/CSS-only change (partial SCD) | 78s | 3.6s | **95% faster** |
| JS-only change (file copy) | 78s | 1.7s | **98% faster** |
| Template-only change (.phtml) | 78s | 1.8s | **98% faster** |
| HTML template (.html KO) | 78s | 1.5s | **98% faster** |
| Font/image file | 78s | 1.6s | **98% faster** |
| No changes | 78s | 0.1s | **99% faster** |

The more themes, locales, and modules you have, the bigger the improvement.

## How It Works

```
vendor/bin/nola-deploy init     ← auto-detect stores, themes, locales from DB
vendor/bin/nola-deploy deploy   ← smart incremental deploy
```

1. **Change detection** — compares git diff + file hashes against last deploy manifest
2. **Surgical compilation** — PHP change? Regenerate only affected generated files. JS change? Copy to pub/static. Template change? Clear cache. No unnecessary full rebuilds.
3. **Parallel SCD** — deploys themes in parallel, respecting parent→child dependency order
4. **Dependency-aware** — Magento/blank deploys before Magento/luma (parent first)
5. **Vendor tracking** — `composer require` a new module? `composer.lock` change triggers full rebuild automatically

## Quick Start

### 1. Setup

```bash
composer require nola/deploy
vendor/bin/nola-deploy init
```

`init` checks your environment (PHP, DB, extensions, disk space), auto-detects stores/themes/locales from the database, and generates `.nola-deploy.yaml` in your Magento root.

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

Review `.nola-deploy.yaml` and adjust if needed — it's fully commented.

### 2. Deploy (recommended daily command)

```bash
vendor/bin/nola-deploy deploy
```

This is the **only command you need for day-to-day deployments**. It automatically detects what changed and runs only what's necessary — if nothing changed, it finishes in ~1 second.

### When to use other commands

```bash
# First time deploying, or need a clean slate (clears generated/, pub/static/)
vendor/bin/nola-deploy deploy:fresh

# Preview what changed without deploying
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
| `deploy` | **Smart incremental deploy — recommended for all deployments** |
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

| Change Type | setup:upgrade | di:compile | SCD | cache:flush |
|-------------|--------------|-----------|-----|-------------|
| PHP files only | Skip | Run | Skip | Run |
| Theme/CSS/LESS only | Skip | Skip | Run | Run |
| DB schema/patches | Run | Run | Run | Run |
| `composer.lock` changed | Run | Run | Run | Run |
| `module.xml` changed | Run | Run | Run | Run |
| No changes | Skip | Skip | Skip | Run |

### setup:upgrade Detection

nola-deploy automatically runs `setup:upgrade --keep-generated` when it detects changes to:

- `db_schema.xml` / `db_schema_whitelist.json` — declarative schema
- `Setup/Patch/Data/` or `Setup/Patch/Schema/` — data and schema patches
- `Setup/UpgradeSchema.php`, `Setup/UpgradeData.php` — legacy upgrade scripts
- `Setup/InstallSchema.php`, `Setup/InstallData.php` — legacy install scripts
- `Setup/Recurring.php` — recurring setup scripts
- `etc/module.xml` — module version changes
- `composer.lock` — new modules installed via `composer require`

The `--keep-generated` flag ensures previously compiled DI code is preserved, so `di:compile` runs on top of existing generated code rather than starting from scratch.

### Surgical Deployment

nola-deploy goes beyond just skipping steps — it handles each file type at the granular level:

| Change Type | What nola-deploy does | Why |
|---|---|---|
| PHP source (no di.xml) | Delete + regenerate only affected Interceptors/Factories | Full `di:compile` rebuilds 4,000+ files. We only touch the ones that changed. |
| Plugin code (Plugin/*.php) | Skip DI entirely | Plugins are loaded dynamically from metadata at runtime — no regeneration needed. |
| JS file | Copy directly to `pub/static/` + bust browser cache | No need to run full SCD for a single JS file change. |
| .phtml template | Clear `var/view_preprocessed/` + flush block cache | Templates are re-processed on next request. No SCD needed. |
| .html (KnockoutJS) | Copy directly to `pub/static/` | KO templates are static assets — no compilation needed. |
| Font files (.woff2, .ttf) | Copy directly to `pub/static/` | Fonts are static — direct copy + cache bust. |
| Image files (.png, .svg) | Copy directly to `pub/static/` | Images in `view/web/` are static copies. |
| LESS/CSS | Partial SCD: CSS-only flags for affected theme | Skips JS, fonts, images. Only recompiles LESS→CSS for the changed theme. |
| `requirejs-config.js` | Partial SCD: JS merge only | All modules' requirejs configs merge into one file — needs SCD, but skip CSS/images. |
| `etc/view.xml` | Partial SCD: image processing only | Image resize config changed — reprocess images, skip CSS/JS. |
| Translation `.csv` | Partial SCD: JS translations only | Regenerates `js-translation.json`, skip CSS/images. |
| di.xml | Full `di:compile` | DI metadata is monolithic (~80MB). Any di.xml change requires full recompilation. |

### Vendor Changes

When you `composer require` a new module or update an existing one, `composer.lock` changes. nola-deploy detects this and triggers a **full rebuild** — `setup:upgrade` + `di:compile` + SCD for all themes. The `vendor/` directory itself is not tracked (it's gitignored) — `composer.lock` is the reliable signal.

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
