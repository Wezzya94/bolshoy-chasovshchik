#!/usr/bin/env bash
set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
bash "$script_dir/setup-preview.sh"

required_extensions=(fileinfo gd json mbstring session)
for extension in "${required_extensions[@]}"; do
  if ! php -r "exit(extension_loaded('$extension') ? 0 : 1);"; then
    echo "Не загружено обязательное расширение PHP: $extension" >&2
    exit 1
  fi
done

sudo apache2ctl configtest
if pgrep -x apache2 >/dev/null 2>&1; then
  sudo apache2ctl -k graceful
else
  sudo apache2ctl start
fi

base_url="http://127.0.0.1:8080"
proxy_header='X-Forwarded-Proto: https'
ready=false
for _ in {1..30}; do
  if curl --silent --show-error --fail --header "$proxy_header" "$base_url/content/site.json" >/dev/null; then
    ready=true
    break
  fi
  sleep 1
done

if [[ "$ready" != "true" ]]; then
  echo "Apache не запустил тестовый сайт на порту 8080." >&2
  exit 1
fi

protected_status="$(curl --silent --output /dev/null --write-out '%{http_code}' --header "$proxy_header" "$base_url/storage/admin-config.php")"
api_status="$(curl --silent --output /dev/null --write-out '%{http_code}' --header "$proxy_header" "$base_url/admin/api.php?action=content")"
cookie_headers="$(curl --silent --dump-header - --output /dev/null --header "$proxy_header" "$base_url/admin/")"

if [[ "$protected_status" != "403" && "$protected_status" != "404" ]]; then
  echo "Проверка защиты storage завершилась неожиданным HTTP $protected_status." >&2
  exit 1
fi
if [[ "$api_status" != "401" ]]; then
  echo "Анонимный API должен возвращать HTTP 401, получен HTTP $api_status." >&2
  exit 1
fi
if ! grep -Eiq '^Set-Cookie: .*;[[:space:]]*secure([;[:space:]]|$)' <<< "$cookie_headers"; then
  echo "Сессионная cookie не получила флаг Secure за HTTPS-прокси." >&2
  exit 1
fi

echo "Проверки PHP, Apache, закрытого storage, авторизации и Secure-cookie пройдены."
bash "$script_dir/show-test-access.sh"
