# GAds Toolkit updates

Plugin 4.2.0 introduces WordPress dashboard updates. Existing installations must install this bootstrap ZIP once. Subsequent versions use Plugins → Update now, or WordPress's opt-in automatic updates (WordPress 5.5+). WordPress 5.0–5.7 uses the legacy transient integration. Hosting filesystem access and working WP-Cron are still required. Deactivated plugins cannot run their own updater; reactivate GAds Toolkit to check its private feed.

## Infrastructure and data

- Existing `gads-central-service` Worker serves `/updates/*`, routed before static assets.
- Existing `GADS_KV` holds `release:gads-toolkit:stable` and immutable per-version records `release:gads-toolkit:version:<version>`.
- Private R2 Standard bucket `gads-toolkit-releases`, binding `GADS_RELEASES`, holds `gads-toolkit/<version>/<sha256>.zip`.
- GET/HEAD `/updates/gads-toolkit/latest.json`: stable metadata. No published release returns JSON 404.
- GET/HEAD `/updates/gads-toolkit/releases/<version>.json`: per-version metadata.
- GET/HEAD `/updates/gads-toolkit/download/<version>/<sha256>.zip`: public package, streamed from R2.
- No HTTP publication endpoints, no license keys or site identities are sent by the updater. Existing Google Ads license enforcement is unchanged.
- WordPress caches metadata for one hour, failures for five minutes. Dashboard → Updates → Check again clears this cache. KV propagation can add delay.
- SHA-256 is checked before installation. This detects corrupted/substituted downloads relative to the HTTPS manifest; it is not a separate cryptographic release signature.

## First-time setup (completed for this project)

Run from `cloudflare-worker` with Node 22+, npm dependencies and authenticated Wrangler:

```sh
npx wrangler r2 bucket create gads-toolkit-releases
npm run deploy
```

The R2 binding and `/updates/*` asset routing are in `wrangler.toml`. Keep public R2 bucket access disabled; the Worker serves downloads. No D1 or new secret is needed.

## Release a plugin version

1. Change `Version` and `GADS_TOOLKIT_VERSION` in `gads-toolkit.php`. Keep both equal. Use stable `x.y.z` versions.
2. Add the matching entry to `CHANGELOG.md`; verify PHP/WordPress requirements in the plugin headers.
3. Validate and build:

```sh
npm --prefix cloudflare-worker run check
npm --prefix cloudflare-worker run test:updates
php -l includes/module-updater.php
npm --prefix cloudflare-worker run release:plugin
```

4. Review and test the generated root `gads-toolkit-<version>.zip` on a staging WordPress site.
5. Publish:

```sh
npm --prefix cloudflare-worker run release:plugin -- --publish
```

The command rebuilds a deterministic ZIP with Python 3, reads release notes, checks for an already-published version or downgrade, uploads R2, downloads the public package and verifies its SHA-256, writes the per-version record, then updates stable. A local lock prevents overlapping executions in this checkout. Use one release operator; KV does not provide a distributed publishing lock. A local per-version receipt also rejects changed retry packages while KV propagates. Keep this release checkout and its receipts; after switching machines, wait for metadata propagation before releasing. Generated manifests are under `cloudflare-worker/.release/` (ignored).

If a version was already published, changing its contents is rejected. Bump the version instead. An interrupted run can be repeated with the identical package; the stable pointer is written last. Package names include their hash. Keep all announced packages available because WordPress may retain older metadata.

Worker version (`src/version.js`, `package.json`, lockfile) is independent of plugin version. A normal plugin-only release does not require Worker deployment. Deploy the Worker when API code/config changes. Landing assets show the plugin version at their last build; deploy them when their displayed version needs refreshing.

Read back `https://gads.pdl.vn/updates/gads-toolkit/latest.json`, compare its version/hash with `.release/<version>.json`, then use WordPress's Check again. Normal release publication does not enable auto-updates on customer sites.

## Packaging and recovery

The ZIP includes only `gads-toolkit.php`, `includes/*.php` (recursive), runtime assets, README and CHANGELOG. Worker code, development tools, documentation directories, secrets, prototype and landing-page sources are excluded. Extend the explicit build allowlist if new runtime file types/directories are added. Do not put runtime secrets in allowlisted PHP/assets.

Changing the stable pointer to an older version only stops new upgrades; WordPress will not downgrade sites that already upgraded. For a faulty release, ship a corrected higher version or restore a tested backup manually. No database or settings are removed by the updater. Future schema migrations must remain compatible with the release's recovery plan.

## Verification harness

`tests/wordpress-updater.php` is a WP-CLI eval-file harness for a disposable WordPress install, guarded by `GADS_UPDATER_TEST`. Never run it against a customer site. It checks the native update feed, details popup data, cache/error handling, legacy fallback, checksum failure and a real Plugin_Upgrader installation with a persisted-data sentinel. Set up a bootstrap copy with version 4.1.9 and the new updater, then pass the absolute generated manifest path as its first argument. Set `GADS_UPDATER_LIVE=1` to download from the real public endpoint instead of local fixtures.

## Release verification — 2026-09-27

- Published plugin/Worker 4.2.0; Worker deployment `5bca4462-f0e0-4f1e-a2f7-1567fc75bc46`.
- Release ZIP: 163751 bytes, SHA-256 `2b4d073f6f46b3fdb1c35e6a7c8e7f696f9a1631db40db6b079f89b83d2dcd35`.
- Four Node test groups passed: metadata errors, version lookup, package GET/HEAD/304, rejected methods/paths. PHP syntax checks and Worker deployment dry-run passed.
- Disposable WordPress 7.1 + separate MySQL instance: simulated older bootstrap copy upgraded to 4.2.0 with the real Plugin_Upgrader. Dashboard bulk-upgrade path passed using fixture transport; background upgrade path passed using the live Worker/R2 endpoint. Options, blocked-IP rows and active state were preserved; update notice cleared. Corrupt downloads were rejected.
- Old-WordPress transient logic was exercised inside that harness; WordPress 5.x itself was not installed. Browser UI and hosting-specific scheduled cron execution were not tested.
- Public metadata and ZIP were read back and matched the local release. Health reported 4.2.0; root landing and admin returned HTTP 200. Initial rollout responses were briefly mixed; publication stopped safely until the new endpoint was available.
- Disposable MySQL process was shut down after verification. Customer sites were not upgraded or opted into automatic updates.
