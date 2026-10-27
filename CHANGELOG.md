# Changelog

## 0.6.2 — 2026-10-27

Backend only; Android remains 0.6.0. Administrators can change linked shop platforms, keep multiple shops per platform, transfer shops without platform conflicts, delete linked shops, and purge referenced trash with automatic association cleanup. Account ownership is reassigned on administrator purge, and current/final administrator accounts may be disabled or deleted. Country changes preserve historical ledger currencies and recalculate current balances. See docs/UPDATE-0.6.2.md.

## 0.6.1 — 2026-10-27

Backend-only fix; Android remains0.6.0. Shop names may repeat, including names in trash; IDs, existing links, access scopes and old message snapshots stay distinct. Duplicate shop choices show a stable ID and available phone. Shop conflict messages are specific, and browser module caches are refreshed. See docs/UPDATE-0.6.1.md.

## 0.6.0 — 2026-10-27

First prepared open-source version, based on backend0.5.11 and Android0.5.13.

- Combined PHP server and complete Android source under server/ and android/.
- Removed the developer's default backend origin and personal WeChat template initialization.
- Added a configurable platform catalog shared by API validation, filtering and UI; shop/platform association remains optional.
- Retained historical platform/message identity, team permissions, paused collection and immutable queued attribution.
- Added install, phone onboarding, maintenance, WeChat, development, contribution and security documentation; added clean packaging and privacy checks.
- MIT license for original code, with retained third-party notices.

Android minSdk23, targetSdk35, versionCode21. Number normalization currently coversCN/PH. Device/ROM-specific online installation failures remain a known issue; use browser download and overlay installation when necessary, without uninstalling.
