# Deploying EtchFacets

EtchFacets uses [plugin-update-checker (PUC)](https://github.com/YahnisElsts/plugin-update-checker)
pointed at the GitHub repo
[`endresol/EtchFacets`](https://github.com/endresol/EtchFacets). PUC checks the
repo's **GitHub Releases** for new tagged versions and downloads the zip
attached to the release. WordPress then shows the update under
**Dashboard → Updates** and on the **Plugins** screen exactly like a
wp.org plugin.

There are two ways to publish a new version:

1. **Automatic** (recommended) — push a tag, create a GitHub Release, the
   workflow at [.github/workflows/release.yml](.github/workflows/release.yml)
   builds and attaches a clean `etchfacets.zip` for you.
2. **Manual** — build the zip locally and upload it to the Release yourself.

---

## 1. Automatic deploy (recommended)

### Step 1 — bump the version in **two** places

Both must match. Edit [`etchfacets.php`](etchfacets.php):

```php
 * Version: 0.2.0          // <-- plugin header
...
define( 'ETCHFACETS_VERSION', '0.2.0' );  // <-- constant
```

Also update `Stable tag:` in [`readme.txt`](readme.txt) and add a
`== Changelog ==` entry.

### Step 2 — commit, tag, push

```bash
git add -A
git commit -m "Release 0.2.0"
git tag v0.2.0
git push origin main --tags
```

> Tag format: `v0.2.0` or `0.2.0` — both are accepted by PUC.

### Step 3 — create a GitHub Release from the tag

Either via the web UI (Releases → "Draft a new release" → pick tag `v0.2.0` →
Publish), or via the CLI:

```bash
gh release create v0.2.0 --title "0.2.0" --notes "Bug fixes and improvements."
```

The release workflow runs on `release: published`, builds a clean zip
(excluding `.git`, `.github`, `._*`, plan/reference docs, etc.) and attaches it
to the Release as `etchfacets.zip`.

### Step 4 — verify on a test site

1. Install the previous version of the plugin somewhere.
2. Visit **Dashboard → Updates** and click *Check Again*, or run
   `wp cron event run --due-now`.
3. The new version should be offered. Click **Update now**.

PUC also adds a **"Check for updates"** link on the Plugins page
(next to "Visit plugin site") that triggers an immediate check.

---

## 2. Manual deploy (fallback)

Use this if the GitHub Action is broken or unavailable.

### Step 1 — bump the version

Same as Step 1 above (edit `etchfacets.php` header + constant + `readme.txt`).

### Step 2 — build a clean zip locally

From the repo root:

```bash
SLUG=etchfacets
VERSION=0.2.0
STAGING=$(mktemp -d)/$SLUG
mkdir -p "$STAGING"

rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='.DS_Store' \
  --exclude='._*' \
  --exclude='*.zip' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='IMPLEMENTATION_PLAN.md' \
  --exclude='PLUGIN_REFERENCE.md' \
  --exclude='DEPLOY.md' \
  ./ "$STAGING/"

(cd "$(dirname "$STAGING")" && zip -rq "$SLUG.zip" "$SLUG")
mv "$(dirname "$STAGING")/$SLUG.zip" "./$SLUG-$VERSION.zip"
echo "Built: $SLUG-$VERSION.zip"
```

> The zip MUST contain a single top-level folder named `etchfacets/`.
> WordPress refuses zips that have files at the root.

### Step 3 — sanity-check the zip

```bash
unzip -l etchfacets-0.2.0.zip | head -30
```

Should show entries like `etchfacets/etchfacets.php`,
`etchfacets/includes/...`, `etchfacets/assets/...`,
`etchfacets/includes/plugin-update-checker/...`. There should be **no** `._*`,
`.DS_Store`, `.git/`, or `IMPLEMENTATION_PLAN.md` entries.

### Step 4 — commit, tag, push

```bash
git add -A
git commit -m "Release 0.2.0"
git tag v0.2.0
git push origin main --tags
```

### Step 5 — create the Release and upload the zip

Web UI: Releases → *Draft a new release* → pick tag `v0.2.0` → drag the zip
into the "Attach binaries" box → Publish.

Or via CLI:

```bash
gh release create v0.2.0 \
  --title "0.2.0" \
  --notes "Bug fixes and improvements." \
  ./etchfacets-0.2.0.zip
```

> The asset filename doesn't matter to PUC — `enableReleaseAssets()` picks the
> first attached asset by default.

### Step 6 — verify on a test site

Same as the automatic flow's Step 4.

---

## Troubleshooting

- **Update not appearing.** WP caches update checks for ~12h. Force it:
  - Click *"Check for updates"* on the Plugins screen, or
  - `wp transient delete update_plugins` then `wp plugin update --all --dry-run`, or
  - Visit **Dashboard → Updates** and click *Check Again*.
- **"The package could not be installed. No valid plugins were found."**
  The zip has files at the root instead of in a top-level `etchfacets/` folder.
  Rebuild using the rsync recipe above.
- **GitHub API rate-limit warnings in `error_log`.** The repo is public so
  unauthenticated rate limits apply. Usually harmless; retries on the next
  cron tick.
- **Version skew.** PUC compares the GitHub release/tag version against the
  `Version:` header in the installed `etchfacets.php`. If you forget to bump
  the header, PUC will keep offering the same update forever.

---

## Updating the PUC library itself

When a new PUC version is released:

```bash
curl -sSL -o /tmp/puc.zip https://github.com/YahnisElsts/plugin-update-checker/archive/refs/tags/v5.7.zip
rm -rf includes/plugin-update-checker
unzip -q /tmp/puc.zip -d /tmp/puc-extract
mkdir -p includes/plugin-update-checker
cp -R /tmp/puc-extract/plugin-update-checker-5.7/. includes/plugin-update-checker/
rm -rf /tmp/puc.zip /tmp/puc-extract
```

Then commit, bump the plugin version, and cut a new release.
