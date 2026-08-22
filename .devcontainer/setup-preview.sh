#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ ! -f /.dockerenv && "${CODESPACES:-}" != "true" ]]; then
  echo "Этот сценарий разрешено запускать только внутри Dev Container/Codespaces." >&2
  exit 1
fi

repo_root="$(git rev-parse --show-toplevel)"
repo_name="$(basename "$repo_root")"
preview_base="/workspaces/.tv-preview"
preview_root="$preview_base/$repo_name"
runtime_root="$preview_root/site"
source_snapshot="$preview_root/source"
credentials_dir="$preview_root/credentials"
password_file="$credentials_dir/admin-password.txt"
config_file="$runtime_root/storage/admin-config.php"

mkdir -p "$runtime_root" "$credentials_dir"
chmod 0711 "$preview_base"
sudo chgrp www-data "$preview_root"
chmod 2750 "$preview_root"
chmod 0700 "$credentials_dir"

# Снимок строится только из текущего Git-коммита. Неотслеживаемые файлы и
# локальные секреты из рабочей папки физически не могут попасть на веб-сервер.
rm -rf -- "$source_snapshot"
mkdir -p "$source_snapshot"
git -C "$repo_root" archive --format=tar HEAD | tar -xf - -C "$source_snapshot"

# Код обновляется из снимка ветки, а изменяемый контент и загрузки остаются в
# изолированной копии за пределами Git-репозитория.
rsync -a --delete \
  --exclude='/.git/' \
  --exclude='/.devcontainer/' \
  --exclude='/backups/' \
  --exclude='/node_modules/' \
  --exclude='/screenshots/' \
  --exclude='/content/' \
  --exclude='/storage/' \
  --exclude='/uploads/' \
  --exclude='/index-v3.html' \
  --exclude='/index-v4.html' \
  --exclude='/ADMIN_GUIDE.md' \
  "$source_snapshot/" "$runtime_root/"

mkdir -p "$runtime_root/content" "$runtime_root/storage/history" "$runtime_root/uploads"

if [[ ! -f "$runtime_root/content/site.json" ]]; then
  install -m 0660 "$source_snapshot/content/site.json" "$runtime_root/content/site.json"
fi
install -m 0640 "$source_snapshot/content/.htaccess" "$runtime_root/content/.htaccess"
install -m 0640 "$source_snapshot/storage/.htaccess" "$runtime_root/storage/.htaccess"
install -m 0640 "$source_snapshot/uploads/.htaccess" "$runtime_root/uploads/.htaccess"
rm -rf -- "$source_snapshot"

if [[ ! -f "$config_file" || ! -f "$password_file" ]]; then
  generated_password="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(24)), "+/", "-_"), "=");')"
  TV_GENERATED_ADMIN_PASSWORD="$generated_password" php -r '
    $password = (string) getenv("TV_GENERATED_ADMIN_PASSWORD");
    $algorithm = defined("PASSWORD_ARGON2ID") ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
        "password_hash" => password_hash($password, $algorithm),
        "updated_at" => gmdate("c"),
    ], true) . ";\n";
    if (file_put_contents($argv[1], $payload, LOCK_EX) === false) {
        fwrite(STDERR, "Не удалось создать тестовую конфигурацию пароля.\n");
        exit(1);
    }
  ' "$config_file"
  printf '%s\n' "$generated_password" > "$password_file"
  unset generated_password TV_GENERATED_ADMIN_PASSWORD
fi

sudo chgrp -R www-data "$runtime_root"
sudo find "$runtime_root" -type d -exec chmod g+rx {} +
sudo find "$runtime_root" -type f -exec chmod g+r {} +
sudo chmod 2770 "$runtime_root/content" "$runtime_root/storage" "$runtime_root/storage/history" "$runtime_root/uploads"
sudo find "$runtime_root/content" "$runtime_root/storage" "$runtime_root/uploads" -type f -exec chmod 0660 {} +
sudo chmod 0640 "$runtime_root/content/.htaccess" "$runtime_root/storage/.htaccess" "$runtime_root/uploads/.htaccess" "$config_file"
chmod 0600 "$password_file"

current_document_root="$(readlink -f /var/www/html 2>/dev/null || true)"
if [[ "$current_document_root" != "$runtime_root" ]]; then
  if [[ -e /var/www/html || -L /var/www/html ]]; then
    sudo rm -rf -- /var/www/html
  fi
  sudo ln -s -- "$runtime_root" /var/www/html
fi

printf '%s\n' "Тестовая копия подготовлена отдельно от Git: $runtime_root"
