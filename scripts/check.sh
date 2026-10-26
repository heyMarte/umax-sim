#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php_bin="${PHP_BIN:-php}"
while IFS= read -r -d '' file; do "$php_bin" -l "$file" >/dev/null; done < <(find server/src server/bin server/public server/config server/tests -name '*.php' -print0)
for file in unit platforms open_source message_privacy telemetry wechat_keyring device_auth_limit_v055; do
 "$php_bin" "server/tests/$file.php"
done
npm --prefix server test
python3 scripts/check-source.py
python3 scripts/check-docs.py
