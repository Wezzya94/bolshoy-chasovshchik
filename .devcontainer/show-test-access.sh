#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(git rev-parse --show-toplevel)"
repo_name="$(basename "$repo_root")"
preview_root="/workspaces/.tv-preview/$repo_name"
password_file="$preview_root/credentials/admin-password.txt"
config_file="$preview_root/site/storage/admin-config.php"

if [[ -n "${CODESPACE_NAME:-}" && -n "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-}" ]]; then
  site_url="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
else
  site_url="http://localhost:8080"
fi

printf '\n%s\n' "Безопасный тест сайта готов:"
printf '  Сайт:    %s\n' "$site_url/"
printf '  Панель:  %s\n' "$site_url/admin/"

if [[ -f "$password_file" && -f "$config_file" ]]; then
  password="$(<"$password_file")"
  if TV_PREVIEW_PASSWORD="$password" php -r '
    $config = require $argv[1];
    $hash = is_array($config) ? (string) ($config["password_hash"] ?? "") : "";
    exit($hash !== "" && password_verify((string) getenv("TV_PREVIEW_PASSWORD"), $hash) ? 0 : 1);
  ' "$config_file"; then
    printf '  Пароль:  %s\n' "$password"
  else
    printf '%s\n' '  Пароль был изменён через панель — используйте новый.'
  fi
  unset password TV_PREVIEW_PASSWORD
else
  printf '%s\n' '  Тестовый пароль ещё не создан; перезапустите Codespace.'
fi

printf '%s\n\n' 'Порт оставляйте Private. Тестовые изменения изолированы от Git и боевого сайта.'
