<?php
declare(strict_types=1);

define('TV_ROOT', dirname(__DIR__));
define('TV_CONTENT_FILE', TV_ROOT . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'site.json');
define('TV_STORAGE_DIR', TV_ROOT . DIRECTORY_SEPARATOR . 'storage');
define('TV_CONFIG_FILE', TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'admin-config.php');
define('TV_ATTEMPTS_FILE', TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'login-attempts.json');
define('TV_HISTORY_DIR', TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'history');
define('TV_UPLOAD_DIR', TV_ROOT . DIRECTORY_SEPARATOR . 'uploads');
define('TV_MAX_UPLOAD_BYTES', 15 * 1024 * 1024);
define('TV_IDLE_TIMEOUT', 30 * 60);
define('TV_ABSOLUTE_TIMEOUT', 8 * 60 * 60);

final class TvValidationException extends RuntimeException {}
final class TvConflictException extends RuntimeException {}

function tv_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

function tv_security_headers(): void
{
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-src 'none'; form-action 'self'; img-src 'self' data: blob:; style-src 'self'; font-src 'self'; script-src 'self'; connect-src 'self'; manifest-src 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
}

function tv_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_name('TV_ADMIN_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => tv_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $now = time();
    if (!empty($_SESSION['authenticated'])) {
        $idleExpired = isset($_SESSION['last_seen']) && $now - (int) $_SESSION['last_seen'] > TV_IDLE_TIMEOUT;
        $absoluteExpired = isset($_SESSION['authenticated_at']) && $now - (int) $_SESSION['authenticated_at'] > TV_ABSOLUTE_TIMEOUT;
        if ($idleExpired || $absoluteExpired) {
            tv_logout_session();
            session_start();
        } else {
            $_SESSION['last_seen'] = $now;
        }
    }

    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function tv_logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/admin',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function tv_is_authenticated(): bool
{
    if (empty($_SESSION['authenticated']) || empty($_SESSION['credential_fingerprint'])) {
        return false;
    }
    $config = tv_admin_config();
    $hash = (string) ($config['password_hash'] ?? '');
    return $hash !== '' && hash_equals((string) $_SESSION['credential_fingerprint'], hash('sha256', $hash));
}

function tv_require_auth(): void
{
    if (!tv_is_authenticated()) {
        tv_json_response(['ok' => false, 'error' => 'Требуется повторный вход.'], 401);
    }
}

function tv_csrf_token(): string
{
    return (string) ($_SESSION['csrf'] ?? '');
}

function tv_validate_csrf(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(tv_csrf_token(), $token);
}

function tv_require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? null;
    if (!tv_validate_csrf(is_string($token) ? $token : null)) {
        tv_json_response(['ok' => false, 'error' => 'Защитный токен устарел. Обновите страницу.'], 419);
    }
}

function tv_require_same_origin(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }
    $originParts = parse_url($origin);
    $originHost = strtolower((string) ($originParts['host'] ?? ''));
    $requestHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    if ($originHost === '' || $requestHost === '' || !hash_equals($requestHost, $originHost)) {
        tv_json_response(['ok' => false, 'error' => 'Запрос с другого сайта отклонён.'], 403);
    }
}

function tv_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function tv_ensure_storage(): void
{
    foreach ([TV_STORAGE_DIR, TV_HISTORY_DIR, TV_UPLOAD_DIR] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать служебную директорию.');
        }
    }
}

function tv_admin_config(): array
{
    $environmentHash = trim((string) getenv('TV_ADMIN_PASSWORD_HASH'));
    if ($environmentHash !== '') {
        return ['password_hash' => $environmentHash, 'source' => 'environment'];
    }
    if (!is_file(TV_CONFIG_FILE)) {
        return [];
    }
    $config = require TV_CONFIG_FILE;
    return is_array($config) ? $config : [];
}

function tv_has_password(): bool
{
    $config = tv_admin_config();
    return isset($config['password_hash']) && is_string($config['password_hash']) && str_starts_with($config['password_hash'], '$');
}

function tv_verify_password(string $password): bool
{
    $config = tv_admin_config();
    $hash = (string) ($config['password_hash'] ?? '');
    return $hash !== '' && password_verify($password, $hash);
}

function tv_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value, $matches);
    return is_int($count) ? $count : strlen($value);
}

function tv_password_length(string $password): int
{
    return tv_text_length($password);
}

function tv_password_hash(string $password): string
{
    $length = tv_password_length($password);
    if ($length < 15 || $length > 128) {
        throw new TvValidationException('Пароль должен содержать от 15 до 128 символов.');
    }
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    return password_hash($password, $algorithm);
}

function tv_write_admin_password(string $newPassword): void
{
    tv_ensure_storage();
    $existing = tv_admin_config();
    if (($existing['source'] ?? '') === 'environment') {
        throw new TvValidationException('Пароль задан переменной окружения TV_ADMIN_PASSWORD_HASH и меняется в настройках хостинга.');
    }
    $payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
        'password_hash' => tv_password_hash($newPassword),
        'updated_at' => gmdate('c'),
    ], true) . ";\n";
    tv_atomic_write(TV_CONFIG_FILE, $payload, 0600);
}

function tv_client_key(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return hash('sha256', $ip);
}

function tv_attempt_state(): array
{
    if (!is_file(TV_ATTEMPTS_FILE)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents(TV_ATTEMPTS_FILE), true);
    return is_array($decoded) ? $decoded : [];
}

function tv_login_retry_after(): int
{
    $state = tv_attempt_state();
    $record = $state[tv_client_key()] ?? [];
    return max(0, (int) ($record['blocked_until'] ?? 0) - time());
}

function tv_record_login_failure(): int
{
    tv_ensure_storage();
    $lockPath = TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'login-attempts.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Не удалось проверить ограничение входа.');
    }
    try {
        $state = tv_attempt_state();
        $now = time();
        foreach ($state as $key => $record) {
            if ($now - (int) ($record['last_failure'] ?? 0) > 86400) {
                unset($state[$key]);
            }
        }
        $key = tv_client_key();
        $record = $state[$key] ?? ['count' => 0, 'last_failure' => 0, 'blocked_until' => 0];
        if ($now - (int) $record['last_failure'] > 1800) {
            $record['count'] = 0;
        }
        $record['count'] = (int) $record['count'] + 1;
        $record['last_failure'] = $now;
        $delay = $record['count'] >= 5 ? min(900, 5 * (2 ** min(8, $record['count'] - 5))) : 0;
        $record['blocked_until'] = $now + $delay;
        $state[$key] = $record;
        tv_atomic_write(TV_ATTEMPTS_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", 0600);
        return $delay;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function tv_clear_login_failures(): void
{
    if (!is_file(TV_ATTEMPTS_FILE)) {
        return;
    }
    $state = tv_attempt_state();
    unset($state[tv_client_key()]);
    tv_atomic_write(TV_ATTEMPTS_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", 0600);
}

function tv_login(string $password): bool
{
    if (tv_login_retry_after() > 0) {
        return false;
    }
    $valid = tv_verify_password($password);
    if (!$valid) {
        tv_record_login_failure();
        usleep(450000);
        return false;
    }
    tv_clear_login_failures();
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_seen'] = time();
    $config = tv_admin_config();
    $_SESSION['credential_fingerprint'] = hash('sha256', (string) ($config['password_hash'] ?? ''));
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

function tv_read_content(): array
{
    if (!is_file(TV_CONTENT_FILE)) {
        throw new RuntimeException('Файл content/site.json не найден.');
    }
    $raw = file_get_contents(TV_CONTENT_FILE);
    if ($raw === false) {
        throw new RuntimeException('Не удалось прочитать контент сайта.');
    }
    $content = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($content) || (int) ($content['schemaVersion'] ?? 0) !== 1) {
        throw new RuntimeException('Формат контента сайта не поддерживается.');
    }
    return $content;
}

function tv_clean_string(mixed $value, string $field, int $max, bool $required = false): string
{
    if (!is_string($value)) {
        throw new TvValidationException("Поле «{$field}» имеет неверный формат.");
    }
    $value = trim(str_replace("\0", '', $value));
    if ($required && $value === '') {
        throw new TvValidationException("Заполните поле «{$field}».");
    }
    if (!preg_match('//u', $value)) {
        throw new TvValidationException("Поле «{$field}» содержит некорректный текст.");
    }
    $length = tv_text_length($value);
    if ($length > $max) {
        throw new TvValidationException("Поле «{$field}» длиннее {$max} символов.");
    }
    return $value;
}

function tv_clean_id(mixed $value, string $field = 'Идентификатор'): string
{
    $id = strtolower(tv_clean_string($value, $field, 64, true));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id)) {
        throw new TvValidationException("Поле «{$field}» может содержать только латинские буквы, цифры и дефис.");
    }
    return $id;
}

function tv_clean_url(mixed $value, string $field, bool $required = false): string
{
    $url = tv_clean_string($value, $field, 500, $required);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (filter_var($url, FILTER_VALIDATE_URL) === false
        || !is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || trim((string) ($parts['host'] ?? '')) === ''
        || isset($parts['user'])
        || isset($parts['pass'])) {
        throw new TvValidationException("В поле «{$field}» нужна полная ссылка, начинающаяся с https://.");
    }
    return $url;
}

function tv_clean_image_path(mixed $value, string $field, bool $required = true): string
{
    $imagePath = tv_clean_string($value, $field, 300, $required);
    if ($imagePath === '') {
        return '';
    }
    if (str_contains($imagePath, '..') || str_starts_with($imagePath, '/') || str_contains($imagePath, '\\')
        || !preg_match('#^(?:img|assets|uploads)/[A-Za-z0-9._/-]+\.(?:jpe?g|png|webp)$#i', $imagePath)) {
        throw new TvValidationException("Поле «{$field}» содержит недопустимый путь к изображению.");
    }
    return $imagePath;
}

function tv_clean_dimension(mixed $value): int
{
    $dimension = is_numeric($value) ? (int) $value : 0;
    return ($dimension > 0 && $dimension <= 20000) ? $dimension : 0;
}

function tv_normalize_photo(mixed $value, string $field): array
{
    if (!is_array($value)) {
        throw new TvValidationException("Элемент «{$field}» имеет неверный формат.");
    }
    return [
        'src' => tv_clean_image_path($value['src'] ?? '', $field . ' · файл'),
        'thumb' => tv_clean_image_path($value['thumb'] ?? ($value['src'] ?? ''), $field . ' · миниатюра'),
        'alt' => tv_clean_string($value['alt'] ?? '', $field . ' · описание', 300),
        'width' => tv_clean_dimension($value['width'] ?? 0),
        'height' => tv_clean_dimension($value['height'] ?? 0),
    ];
}

function tv_normalize_media_item(mixed $value, string $field): array
{
    if (!is_array($value)) {
        throw new TvValidationException("Медиаслот «{$field}» имеет неверный формат.");
    }
    return [
        'id' => tv_clean_id($value['id'] ?? '', $field . ' · ID'),
        'label' => tv_clean_string($value['label'] ?? '', $field . ' · название', 120, true),
        'src' => tv_clean_image_path($value['src'] ?? '', $field . ' · файл'),
        'alt' => tv_clean_string($value['alt'] ?? '', $field . ' · описание', 300),
        'caption' => tv_clean_string($value['caption'] ?? '', $field . ' · подпись', 200),
        'width' => tv_clean_dimension($value['width'] ?? 0),
        'height' => tv_clean_dimension($value['height'] ?? 0),
    ];
}

function tv_normalize_media(mixed $value): array
{
    if (!is_array($value)) {
        throw new TvValidationException('Раздел фотографий имеет неверный формат.');
    }
    $limits = ['hero' => 8, 'directions' => 8, 'master' => 6, 'workshop' => 16, 'certificates' => 12];
    $result = [];
    foreach ($limits as $group => $limit) {
        $items = $value[$group] ?? [];
        if (!is_array($items) || count($items) > $limit) {
            throw new TvValidationException("Группа фотографий «{$group}» имеет неверный размер.");
        }
        $result[$group] = [];
        foreach ($items as $index => $item) {
            $result[$group][] = tv_normalize_media_item($item, $group . ' ' . ((int) $index + 1));
        }
    }
    return $result;
}

function tv_normalize_contacts(mixed $value): array
{
    if (!is_array($value)) {
        throw new TvValidationException('Контактные данные имеют неверный формат.');
    }
    $socials = $value['socials'] ?? [];
    if (!is_array($socials) || count($socials) > 20) {
        throw new TvValidationException('Список социальных сетей слишком большой.');
    }
    $normalizedSocials = [];
    $seen = [];
    foreach ($socials as $index => $social) {
        if (!is_array($social)) {
            throw new TvValidationException('Запись социальной сети имеет неверный формат.');
        }
        $id = tv_clean_id($social['id'] ?? '', 'Соцсеть · ID');
        if (isset($seen[$id])) {
            throw new TvValidationException("Соцсеть с ID «{$id}» указана дважды.");
        }
        $seen[$id] = true;
        $normalizedSocials[] = [
            'id' => $id,
            'label' => tv_clean_string($social['label'] ?? '', 'Название соцсети', 80, true),
            'url' => tv_clean_url($social['url'] ?? '', 'Ссылка соцсети', true),
            'visible' => (bool) ($social['visible'] ?? false),
        ];
    }
    $phoneE164 = tv_clean_string($value['phoneE164'] ?? '', 'Телефон E.164', 20, true);
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $phoneE164)) {
        throw new TvValidationException('Телефон E.164 должен начинаться с «+» и содержать от 8 до 15 цифр без пробелов.');
    }
    return [
        'city' => tv_clean_string($value['city'] ?? '', 'Город', 100, true),
        'serviceArea' => tv_clean_string($value['serviceArea'] ?? '', 'География работы', 180, true),
        'phoneDisplay' => tv_clean_string($value['phoneDisplay'] ?? '', 'Телефон', 40, true),
        'phoneE164' => $phoneE164,
        'telegramLabel' => tv_clean_string($value['telegramLabel'] ?? '', 'Telegram', 80, true),
        'telegramUrl' => tv_clean_url($value['telegramUrl'] ?? '', 'Ссылка Telegram', true),
        'whatsappUrl' => tv_clean_url($value['whatsappUrl'] ?? '', 'Ссылка WhatsApp', true),
        'socials' => $normalizedSocials,
    ];
}

function tv_normalize_project(mixed $value, int $order): array
{
    if (!is_array($value)) {
        throw new TvValidationException('Карточка проекта имеет неверный формат.');
    }
    $id = tv_clean_id($value['id'] ?? ($value['slug'] ?? ''), 'ID проекта');
    $body = $value['body'] ?? [];
    $specs = $value['specs'] ?? [];
    $photos = $value['photos'] ?? [];
    if (!is_array($body) || count($body) > 15 || !is_array($specs) || count($specs) > 15 || !is_array($photos) || count($photos) > 40) {
        throw new TvValidationException("Проект «{$id}» содержит слишком много элементов.");
    }
    $normalizedPhotos = [];
    foreach ($photos as $index => $photo) {
        $normalizedPhotos[] = tv_normalize_photo($photo, "Проект {$id} · фото " . ((int) $index + 1));
    }
    $visible = (bool) ($value['visible'] ?? false);
    if ($visible && !$normalizedPhotos) {
        throw new TvValidationException("У опубликованного проекта «{$id}» должна быть хотя бы одна фотография.");
    }
    $photoPaths = array_column($normalizedPhotos, 'src');
    $cover = tv_clean_image_path($value['cover'] ?? ($photoPaths[0] ?? ''), "Проект {$id} · обложка", $visible);
    if ($cover !== '' && !in_array($cover, $photoPaths, true)) {
        throw new TvValidationException("Обложка проекта «{$id}» должна входить в его галерею.");
    }
    $normalizedBody = [];
    foreach ($body as $index => $paragraph) {
        $text = tv_clean_string($paragraph, "Проект {$id} · абзац " . ((int) $index + 1), 2500);
        if ($text !== '') {
            $normalizedBody[] = $text;
        }
    }
    $normalizedSpecs = [];
    foreach ($specs as $index => $spec) {
        if (!is_array($spec)) {
            throw new TvValidationException("Характеристика проекта «{$id}» имеет неверный формат.");
        }
        $label = tv_clean_string($spec['label'] ?? '', "Проект {$id} · характеристика", 100);
        $specValue = tv_clean_string($spec['value'] ?? '', "Проект {$id} · значение", 400);
        if ($label !== '' || $specValue !== '') {
            $normalizedSpecs[] = ['label' => $label, 'value' => $specValue];
        }
    }
    return [
        'id' => $id,
        'slug' => $id,
        'visible' => $visible,
        'order' => $order,
        'type' => tv_clean_string($value['type'] ?? '', "Проект {$id} · категория", 160, true),
        'modalType' => tv_clean_string($value['modalType'] ?? ($value['type'] ?? ''), "Проект {$id} · категория в окне", 160, true),
        'title' => tv_clean_string($value['title'] ?? '', "Проект {$id} · название", 180, true),
        'accent' => tv_clean_string($value['accent'] ?? '', "Проект {$id} · акцент", 120),
        'cardLead' => tv_clean_string($value['cardLead'] ?? '', "Проект {$id} · подпись карточки", 300, true),
        'lead' => tv_clean_string($value['lead'] ?? '', "Проект {$id} · вступление", 600, true),
        'body' => $normalizedBody,
        'specs' => $normalizedSpecs,
        'cover' => $cover,
        'coverAlt' => tv_clean_string($value['coverAlt'] ?? '', "Проект {$id} · alt обложки", 300),
        'photos' => $normalizedPhotos,
    ];
}

function tv_normalize_content(mixed $value): array
{
    if (!is_array($value) || !is_array($value['site'] ?? null) || !is_array($value['projects'] ?? null)) {
        throw new TvValidationException('Передан неполный набор данных сайта.');
    }
    if (count($value['projects']) > 200) {
        throw new TvValidationException('На сайте не может быть больше 200 проектов.');
    }
    $projects = [];
    $seen = [];
    foreach ($value['projects'] as $index => $project) {
        $normalized = tv_normalize_project($project, (int) $index + 1);
        if (isset($seen[$normalized['id']])) {
            throw new TvValidationException("ID проекта «{$normalized['id']}» используется дважды.");
        }
        $seen[$normalized['id']] = true;
        $projects[] = $normalized;
    }
    return [
        'schemaVersion' => 1,
        'revision' => 0,
        'updatedAt' => '',
        'site' => [
            'contacts' => tv_normalize_contacts($value['site']['contacts'] ?? null),
            'media' => tv_normalize_media($value['site']['media'] ?? null),
        ],
        'projects' => $projects,
    ];
}

function tv_atomic_write(string $path, string $contents, int $permissions = 0640): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать директорию для записи.');
    }
    $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать временный файл.');
    }
    @chmod($temporary, $permissions);
    if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось заменить файл.');
        }
        @chmod($path, $permissions);
        @unlink($temporary);
        return;
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось завершить атомарную запись.');
    }
}

function tv_prune_history(int $keep = 100): void
{
    $files = glob(TV_HISTORY_DIR . DIRECTORY_SEPARATOR . 'site-r*.json');
    if (!is_array($files) || count($files) <= $keep) {
        return;
    }
    sort($files, SORT_STRING);
    foreach (array_slice($files, 0, count($files) - $keep) as $oldFile) {
        if (is_file($oldFile) && !@unlink($oldFile)) {
            error_log('TV admin could not prune history file: ' . basename($oldFile));
        }
    }
}

function tv_save_content(mixed $candidate, int $expectedRevision): array
{
    tv_ensure_storage();
    $lockPath = TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'content.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Не удалось заблокировать контент для сохранения.');
    }
    try {
        $current = tv_read_content();
        $currentRevision = (int) ($current['revision'] ?? 0);
        if ($expectedRevision !== $currentRevision) {
            throw new TvConflictException('Контент уже изменён в другой вкладке. Обновите страницу и повторите правку.');
        }
        $normalized = tv_normalize_content($candidate);
        $normalized['revision'] = $currentRevision + 1;
        $normalized['updatedAt'] = gmdate('c');

        if (!is_dir(TV_HISTORY_DIR) && !mkdir(TV_HISTORY_DIR, 0750, true) && !is_dir(TV_HISTORY_DIR)) {
            throw new RuntimeException('Не удалось создать историю версий.');
        }
        $historyName = sprintf('site-r%06d-%s.json', $currentRevision, gmdate('Ymd-His'));
        tv_atomic_write(
            TV_HISTORY_DIR . DIRECTORY_SEPARATOR . $historyName,
            json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
        );
        tv_atomic_write(
            TV_CONTENT_FILE,
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
        );
        tv_prune_history();
        return $normalized;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function tv_read_json_body(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 3 * 1024 * 1024) {
        throw new TvValidationException('Запрос слишком большой.');
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        throw new TvValidationException('Пустой запрос.');
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new TvValidationException('Ожидался JSON-объект.');
    }
    return $decoded;
}

function tv_image_extension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => throw new TvValidationException('Разрешены только JPEG, PNG и WebP.'),
    };
}

function tv_scaled_image(GdImage $source, int $maxSide): GdImage
{
    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, $maxSide / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
    if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
        throw new RuntimeException('Не удалось изменить размер изображения.');
    }
    return $target;
}

function tv_handle_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new TvValidationException('Файл не загрузился. Проверьте лимит хостинга и повторите попытку.');
    }
    $temporary = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > TV_MAX_UPLOAD_BYTES || !is_uploaded_file($temporary)) {
        throw new TvValidationException('Файл должен быть изображением размером не более 15 МБ.');
    }
    if (!class_exists('finfo')) {
        throw new RuntimeException('На хостинге не включено расширение PHP Fileinfo.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($temporary);
    $extension = tv_image_extension($mime);
    $imageInfo = @getimagesize($temporary);
    if (!is_array($imageInfo) || (int) $imageInfo[0] < 1 || (int) $imageInfo[1] < 1) {
        throw new TvValidationException('Файл не распознан как корректное изображение.');
    }
    $expectedTypes = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
    if (($expectedTypes[$mime] ?? 0) !== (int) ($imageInfo[2] ?? 0)) {
        throw new TvValidationException('Содержимое файла не соответствует его типу.');
    }
    $width = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];
    $pixels = $width * $height;
    if ($width > 12000 || $height > 12000 || $pixels > 60000000) {
        throw new TvValidationException('Изображение слишком большое по разрешению. Максимум — 60 мегапикселей.');
    }

    $relativeDirectory = 'uploads/' . gmdate('Y/m');
    $directory = TV_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать папку для загрузки.');
    }
    $baseName = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(10));
    $canReencode = extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagewebp') && $pixels <= 20000000;

    if ($canReencode) {
        $binary = file_get_contents($temporary);
        $source = $binary !== false ? @imagecreatefromstring($binary) : false;
        if (!$source instanceof GdImage) {
            throw new TvValidationException('Изображение не удалось безопасно декодировать.');
        }
        $full = tv_scaled_image($source, 2560);
        $thumb = tv_scaled_image($source, 560);
        $fullPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.webp';
        $thumbPath = $directory . DIRECTORY_SEPARATOR . $baseName . '-thumb.webp';
        $saved = imagewebp($full, $fullPath, 88) && imagewebp($thumb, $thumbPath, 82);
        $width = imagesx($full);
        $height = imagesy($full);
        if (!$saved) {
            @unlink($fullPath);
            @unlink($thumbPath);
            throw new RuntimeException('Не удалось сохранить оптимизированное изображение.');
        }
        @chmod($fullPath, 0644);
        @chmod($thumbPath, 0644);
        return [
            'src' => $relativeDirectory . '/' . basename($fullPath),
            'thumb' => $relativeDirectory . '/' . basename($thumbPath),
            'alt' => '',
            'width' => $width,
            'height' => $height,
        ];
    }

    $destination = $directory . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;
    if (!move_uploaded_file($temporary, $destination)) {
        throw new RuntimeException('Не удалось переместить загруженный файл.');
    }
    @chmod($destination, 0644);
    return [
        'src' => $relativeDirectory . '/' . basename($destination),
        'thumb' => $relativeDirectory . '/' . basename($destination),
        'alt' => '',
        'width' => $width,
        'height' => $height,
    ];
}
