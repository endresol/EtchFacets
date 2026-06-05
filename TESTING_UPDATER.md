# Testing the EtchFacets Plugin Updater

End-to-end walkthrough for testing the updater on a real WP site. The plugin
is currently at `0.1.0` (`etchfacets.php` line 7 and 18) and PUC is wired to
the `main` branch with release assets enabled (`etchfacets.php` lines 33–41).

## 1. Install the "old" version on a test site

You need WP to think it has an older copy installed so it has something to
update.

- Easiest: build a zip of the current `0.1.0` using the rsync recipe in
  [DEPLOY.md §2 Step 2](DEPLOY.md) (set `VERSION=0.1.0`).
- Upload it via **Plugins → Add New → Upload Plugin** and **Activate**.
- Confirm it's running: **Plugins** screen shows `EtchFacets 0.1.0`.

Tip: a local stack like LocalWP, wp-now, or `wp-env` works fine.

## 2. Cut a higher version as a Release

On a branch or directly on `main`:

1. Bump both spots in `etchfacets.php` to e.g. `0.1.1` (plugin header +
   `ETCHFACETS_VERSION`), and the `Stable tag:` in `readme.txt`.
2. Commit, tag, push, and publish a Release:

   ```bash
   git add -A && git commit -m "Release 0.1.1"
   git tag v0.1.1
   git push origin main --tags
   gh release create v0.1.1 --title "0.1.1" --notes "Updater test"
   ```

3. Wait for `.github/workflows/release.yml` to finish. Confirm the Release
   page now shows an attached `etchfacets.zip`. **No asset = no update
   offered.**

## 3. Force WP to check for updates (skip the 12h cache)

Pick whichever is convenient:

- **Plugins screen:** click the **"Check for updates"** link PUC adds next to
  "Visit plugin site".
- **Dashboard → Updates:** click **Check Again**.
- **WP-CLI:**

  ```bash
  wp transient delete update_plugins
  wp plugin update --all --dry-run
  ```

If you're poking at it repeatedly, WP-CLI is fastest.

## 4. Verify the update is offered

You should see:

- A row on **Dashboard → Updates** for EtchFacets `0.1.0 → 0.1.1`.
- A yellow banner on **Plugins** with
  **"View version 0.1.1 details | Update now"**.
- Clicking **View version details** opens a modal with the readme/changelog
  from the tag — that proves PUC parsed `readme.txt` correctly.

## 5. Run the actual update

Click **Update now**. WordPress will:

1. Download `etchfacets.zip` from the GitHub Release asset.
2. Unpack it (must contain a single top-level `etchfacets/` folder — see
   `DEPLOY.md` lines 109–110).
3. Replace the plugin in place and reactivate it.

Confirm the Plugins screen now reads `0.1.1` and the site still works.

## 6. What to check if something goes wrong

- **No update appears.** Re-check the Release has `etchfacets.zip` attached.
  Hit "Check for updates" again. Tail `wp-content/debug.log` for PUC messages
  (enable `WP_DEBUG` + `WP_DEBUG_LOG`).
- **"No valid plugins were found"** on update. The zip is flat instead of
  having a top-level `etchfacets/` folder — inspect with
  `unzip -l etchfacets.zip | head` (see `DEPLOY.md` lines 161–163).
- **Update keeps being offered after updating.** You forgot to bump the
  `Version:` header in `etchfacets.php`; PUC compares header vs. tag
  (see `DEPLOY.md` lines 167–169).
- **GitHub rate-limit warnings.** Harmless on a public repo; will retry on
  next cron.

## 7. Optional: quick smoke test without making a real release

If you don't want to pollute the Releases history, you can:

- Create a **pre-release** (check the "Set as a pre-release" box). PUC
  ignores pre-releases by default, so to test that path you'd flip it back
  to a normal release.
- Or temporarily lower the installed version (edit `Version:` in the test
  site's `etchfacets.php` to `0.0.9`) and let PUC offer the existing `0.1.0`
  release as an "update". This tests the download/install path without
  needing a new tag.

When you're done, delete the throwaway `v0.1.1` tag/release if you don't
actually want to ship it:

```bash
gh release delete v0.1.1 --yes
git push --delete origin v0.1.1
git tag -d v0.1.1
```
