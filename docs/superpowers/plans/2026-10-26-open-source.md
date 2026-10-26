# Umax SIM Open Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish a clean, self-hosted MIT-licensed Umax SIM source repository with tested installation and complete documentation on GitHub and Gitee when account access permits.

**Architecture:** Preserve the existing PHP/MySQL backend and native Android collector in one independent repository. Centralize configurable platform labels, remove original site defaults, and retain all existing account, message attribution and queue boundaries.

**Tech Stack:** PHP8.2, MySQL/MariaDB, native JS, Kotlin2.0.21, AGP8.7.3, Gradle8.9, JDK17, Android API23–35.

**Spec:** ../specs/2026-10-26-open-source-design.md (user approved 2026-10-26, MIT approved).

## Global Constraints

- Name Umax SIM, repository umax-sim, source roots server/ and android/.
- Each deployer uses their own HTTPS origin, database, phones and optional WeChat account.
- Phone-first; optional platform/shop; no SMS verification requirement.
- Preserve old saved connections, paused state, immutable queued message attribution and RBAC.
- CN/PH number normalization only; no claims of universal device compatibility.
- Source Git history must never include config.php/keyring, var, real data, signing keys, APK/build outputs or original site defaults.
- Backend/App version0.6.0; Android versionCode21, same package name. Existing deployment source remains unchanged.
- MIT for original code; retain third-party notices. Gitee requires user browser login.

## Review Focus

- Fresh install without optional WeChat config must work and remain disabled.
- Custom platform labels must survive CRUD/filter/render and must never alter old message attribution or enable XSS.
- Invalid platform config must fail closed; platform additions must not silently erase old known keys.
- Android absent/invalid origin must never send to a developer default; saved origin remains usable.
- Source and deployment archive allowlists must exclude private files, including staged old updates and screenshots.

### Task 1: Independent clean source baseline and privacy contract

**Files:** README.md, LICENSE, .gitignore, server/{src,bin,database,public,config/config.example.php,tests,package.json}, android/, scripts/check-source.py.
**Interfaces:** Produces source tree roots used by every later task and a source privacy checker accepting repository root as CLI argument.
- [ ] Create an independent repository on prepare-open-source branch with only plan/spec/docs and ignores committed initially; baseline source files remain unstaged until sanitized.
- [ ] Copy application source through explicit directory/file allowlists; exclude all runtime/private/build content and old screenshots/history docs.
- [ ] Write privacy regression scanner and verify current copies fail original-origin/template checks before changing defaults.
- [ ] Run existing JS55 tests, Android59 unit tests and PHP syntax/pure tests to record baseline separately from new failures.

### Task 2: Site-owned connection defaults and configurable platform catalog

**Files:** server/src/platform_catalog.php, platforms.php, sim_shops.php, rbac.php, wechat.php, version.php; server/public/static/{ui-data.js,app.js}; server/config/config.example.php; server/tests/open_source.php, configurable-platforms.test.js; android TelemetryCollector.kt/MainActivity.kt/DeviceClient.kt/TelemetryTest.kt/build.gradle.kts.
**Interfaces:** platform_catalog(): array<string,array{label:string,keywords:array}>, platform_keys(): array, platform_options(): array; JS configurePlatforms(options) mutates shared platformOptions array; /api/me includes public platform_catalog options; configuredUrl(saved:String?):String returns validated saved origin or empty string.
- [ ] Add PHP tests proving custom platform create/filter/inference/label escaping and invalid catalog rejection; Android tests proving absent/invalid origin returns empty but saved valid origin preserved. Observe failures.
- [ ] Implement a merged default+custom platform catalog from private settings()['platform_catalog']; validate slug/label/keywords and bounded count. Retain original three keys for historic data; use explicit keywords for inference, generic labels cannot invent SMS source.
- [ ] Replace PHP fixed platform membership/max3 limits with catalog keys; extend shared JS catalog configuration and expose public options in authenticated /api/me. Keep dynamic labels text-only and generic glyph fallback.
- [ ] Initialize WeChat with blank template/origin, disabled; add no default external endpoint. Remove Android developer origin and gate network posts with valid saved HTTPS configuration.
- [ ] Bump0.6.0/code21; run PHP catalog tests, JS suite, Android unit tests and privacy scanner.
- [ ] Commit only source files after scanner proves no original origin/template/private paths remain.

### Task 3: Reproducible deployment/docs/release tooling

**Files:** docs/*.md, CONTRIBUTING.md, SECURITY.md, CHANGELOG.md, THIRD_PARTY_NOTICES.md, scripts/{check.sh,package.py,integration-smoke.py}, .github/workflows/checks.yml, server/tests/open_source_install.php.
**Interfaces:** check.sh runs portable no-DB suite; package.py writes explicit source/deployment/update archives to dist/ and checks excluded paths; integration-smoke.py uses isolated config and localhost-only test server.
- [ ] Turn approved draft docs into factual install/upgrade instructions, exact directory layout, platform config example, build signing and known compatibility boundaries; include MIT and third-party license text.
- [ ] Build explicit allowlist packages; verify archive entries reject forbidden state and match manifest; source ZIP cannot be mistaken for online release ZIP.
- [ ] Validate clean install twice/migrate idempotence, create-admin and health in isolated local MariaDB, then HTTP login, no-shop SIM creation, custom platform/shop linking and permission/message-privacy smoke; use fake data and no real outbound messages.
- [ ] Check relative docs links, PHP syntax/pure suite, JS suite, Android unit/build/lint; provide lightweight CI for PHP/JS/source checks and optional Android build.
- [ ] Commit docs/tooling and record actual results; do not claim real Baota remote installation.

### Task 4: Review and publishing

**Files:** repository metadata, .github templates, release artifacts; no runtime secrets.
**Interfaces:** Publish identical reviewed commit to GitHub marte95/umax-sim and authenticated Gitee owner/umax-sim; all public refs point to clean source history.
- [ ] Obtain fresh whole-repository review against spec, source privacy, config defaults, platform escaping, docs and archive contents. Fix important findings with failing regression then passing suite.
- [ ] Run final staged-content privacy check and inspect git status/diff/log before creating public repositories.
- [ ] Create public repositories only after local code/docs are concrete and reviewed; user has authorized publication. Push same commit/tag and set descriptions.
- [ ] Verify remote README and tag/commit. If Gitee authentication still missing, keep local artifacts and GitHub result, report exact remaining user action without falsely claiming dual publication.
