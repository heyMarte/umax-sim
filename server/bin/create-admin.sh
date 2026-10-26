#!/usr/bin/env bash
set -euo pipefail
php_bin="${1:-php}"
cd "$(dirname "$0")/.."
read -r -p '管理员账户: ' SIM_ADMIN_USER
read -r -s -p '密码（6至72字节）: ' SIM_ADMIN_PASSWORD
printf '\n'
read -r -s -p '确认密码: ' sim_confirm_password
printf '\n'
if [[ "$SIM_ADMIN_PASSWORD" != "$sim_confirm_password" ]]; then
  echo '两次密码不一致'; exit 1
fi
export SIM_ADMIN_USER SIM_ADMIN_PASSWORD
trap 'unset SIM_ADMIN_USER SIM_ADMIN_PASSWORD sim_confirm_password' EXIT
"$php_bin" bin/console.php create-admin
