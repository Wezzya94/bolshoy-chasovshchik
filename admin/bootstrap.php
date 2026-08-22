<?php
declare(strict_types=1);

define('TV_ROOT', dirname(__DIR__));
define('TV_CONTENT_FILE', TV_ROOT . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'site.json');
define('TV_STORAGE_DIR', TV_ROOT . DIRECTORY_SEPARATOR . 'storage');
define('TV_CONFIG_FILE', TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'admin-config.php');
define('TV_ATTEMPTS_FILE', TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'login-attempts.json');
define('TV_HISTORY_DIR', TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'history');
define('TV_DRAFTS_DIR', TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'drafts');
define('TV_PREVIEWS_DIR', TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'previews');
define('TV_HISTORY_RECOVERY_HOURS', 72);
define('TV_HISTORY_MIN_KEEP', 100);
define('TV_HISTORY_HARD_LIMIT', 200);
define('TV_UPLOAD_DIR', TV_ROOT . DIRECTORY_SEPARATOR . 'uploads');
define('TV_MAX_UPLOAD_BYTES', 9 * 1024 * 1024);
define('TV_MAX_UPLOAD_SET_BYTES', 18 * 1024 * 1024);
define('TV_IDLE_TIMEOUT', 30 * 60);
define('TV_ABSOLUTE_TIMEOUT', 8 * 60 * 60);

final class TvValidationException extends RuntimeException {}
final class TvConflictException extends RuntimeException {}

function tv_php_size_to_bytes(mixed $value): int
{
    $text = is_string($value) ? strtolower(trim($value)) : '';
    if ($text === '' || $text === '-1') {
        return 0;
    }
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgt]?)b?$/', $text, $matches) !== 1) {
        return 0;
    }
    $number = (float) $matches[1];
    $power = match ($matches[2]) {
        'k' => 1,
        'm' => 2,
        'g' => 3,
        't' => 4,
        default => 0,
    };
    return max(0, (int) floor($number * (1024 ** $power)));
}

function tv_effective_prepared_file_limit(): int
{
    $phpLimit = tv_php_size_to_bytes(ini_get('upload_max_filesize'));
    return $phpLimit > 0 ? min(TV_MAX_UPLOAD_BYTES, $phpLimit) : TV_MAX_UPLOAD_BYTES;
}

function tv_effective_upload_set_limit(): int
{
    $postLimit = tv_php_size_to_bytes(ini_get('post_max_size'));
    if ($postLimit < 1) {
        return TV_MAX_UPLOAD_SET_BYTES;
    }
    $safePostLimit = max(512 * 1024, (int) floor($postLimit * 0.88));
    return min(TV_MAX_UPLOAD_SET_BYTES, $safePostLimit);
}

function tv_is_https(): bool
{
    $directHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    if ($directHttps) {
        return true;
    }

    return (string) getenv('TV_EXTERNAL_HTTPS') === '1';
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

function tv_preview_security_headers(): void
{
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-src 'none'; form-action 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'self' 'unsafe-inline'; connect-src 'self'; manifest-src 'self'");
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
    $requestScheme = tv_is_https() ? 'https' : 'http';
    $requestParts = parse_url($requestScheme . '://' . trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $originScheme = strtolower((string) ($originParts['scheme'] ?? ''));
    $originHost = strtolower((string) ($originParts['host'] ?? ''));
    $requestHost = strtolower((string) ($requestParts['host'] ?? ''));
    $originPort = (int) ($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80));
    $requestPort = (int) ($requestParts['port'] ?? ($requestScheme === 'https' ? 443 : 80));
    if ($originScheme === '' || $originHost === '' || $requestHost === ''
        || !hash_equals($requestScheme, $originScheme)
        || !hash_equals($requestHost, $originHost)
        || $originPort !== $requestPort) {
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
    foreach ([TV_STORAGE_DIR, TV_HISTORY_DIR, TV_DRAFTS_DIR, TV_PREVIEWS_DIR, TV_UPLOAD_DIR] as $directory) {
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

function tv_clean_crop_number(mixed $value, float $minimum, float $maximum, float $fallback): float
{
    if (!is_numeric($value)) {
        return $fallback;
    }
    $number = (float) $value;
    if (!is_finite($number)) {
        return $fallback;
    }
    return max($minimum, min($maximum, $number));
}

function tv_normalize_crop(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    $rotation = (int) ($value['rotation'] ?? 0);
    $rotation = (($rotation % 360) + 360) % 360;
    if (!in_array($rotation, [0, 90, 180, 270], true)) {
        $rotation = 0;
    }
    return [
        'zoom' => tv_clean_crop_number($value['zoom'] ?? 1, 1, 8, 1),
        'offsetX' => tv_clean_crop_number($value['offsetX'] ?? 0, -4, 4, 0),
        'offsetY' => tv_clean_crop_number($value['offsetY'] ?? 0, -4, 4, 0),
        'rotation' => $rotation,
    ];
}

function tv_normalize_variant(mixed $value, string $field): array
{
    if (!is_array($value)) {
        throw new TvValidationException("Вариант «{$field}» имеет неверный формат.");
    }
    return [
        'src' => tv_clean_image_path($value['src'] ?? '', $field . ' · файл'),
        'width' => tv_clean_dimension($value['width'] ?? 0),
        'height' => tv_clean_dimension($value['height'] ?? 0),
    ];
}

function tv_normalize_variants(mixed $value, string $field): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value) || count($value) > 10) {
        throw new TvValidationException("Набор размеров «{$field}» имеет неверный формат.");
    }
    $variants = [];
    $seen = [];
    foreach ($value as $index => $variant) {
        $normalized = tv_normalize_variant($variant, $field . ' ' . ((int) $index + 1));
        if (isset($seen[$normalized['src']])) {
            continue;
        }
        $seen[$normalized['src']] = true;
        $variants[] = $normalized;
    }
    usort($variants, static fn(array $left, array $right): int => $left['width'] <=> $right['width']);
    return $variants;
}

function tv_normalize_rendition(mixed $value, string $field): ?array
{
    if ($value === null || $value === '' || $value === []) {
        return null;
    }
    if (!is_array($value)) {
        throw new TvValidationException("Кадр «{$field}» имеет неверный формат.");
    }
    $result = [
        'src' => tv_clean_image_path($value['src'] ?? '', $field . ' · файл'),
        'width' => tv_clean_dimension($value['width'] ?? 0),
        'height' => tv_clean_dimension($value['height'] ?? 0),
        'variants' => tv_normalize_variants($value['variants'] ?? [], $field . ' · размеры'),
    ];
    $crop = tv_normalize_crop($value['crop'] ?? null);
    if ($crop !== null) {
        $result['crop'] = $crop;
    }
    return $result;
}

function tv_normalize_image_extras(array $value, string $field): array
{
    $extras = [];
    $profile = isset($value['cropProfile'])
        ? strtolower(tv_clean_string($value['cropProfile'], $field . ' · профиль кадра', 40))
        : '';
    if ($profile !== '') {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $profile) !== 1) {
            throw new TvValidationException("Профиль кадра «{$field}» имеет неверный формат.");
        }
        $extras['cropProfile'] = $profile;
    }
    $variants = tv_normalize_variants($value['variants'] ?? [], $field . ' · размеры');
    if ($variants !== []) {
        $extras['variants'] = $variants;
    }
    foreach (['master', 'card', 'mobile'] as $key) {
        $rendition = tv_normalize_rendition($value[$key] ?? null, $field . ' · ' . $key);
        if ($rendition !== null) {
            $extras[$key] = $rendition;
        }
    }
    $crop = tv_normalize_crop($value['crop'] ?? null);
    if ($crop !== null) {
        $extras['crop'] = $crop;
    }
    return $extras;
}

function tv_normalize_photo(mixed $value, string $field): array
{
    if (!is_array($value)) {
        throw new TvValidationException("Элемент «{$field}» имеет неверный формат.");
    }
    $photo = [
        'src' => tv_clean_image_path($value['src'] ?? '', $field . ' · файл'),
        'thumb' => tv_clean_image_path($value['thumb'] ?? ($value['src'] ?? ''), $field . ' · миниатюра'),
        'alt' => tv_clean_string($value['alt'] ?? '', $field . ' · описание', 300),
        'width' => tv_clean_dimension($value['width'] ?? 0),
        'height' => tv_clean_dimension($value['height'] ?? 0),
    ];
    return array_merge($photo, tv_normalize_image_extras($value, $field));
}

function tv_normalize_media_item(mixed $value, string $field): array
{
    if (!is_array($value)) {
        throw new TvValidationException("Медиаслот «{$field}» имеет неверный формат.");
    }
    $item = [
        'id' => tv_clean_id($value['id'] ?? '', $field . ' · ID'),
        'label' => tv_clean_string($value['label'] ?? '', $field . ' · название', 120, true),
        'src' => tv_clean_image_path($value['src'] ?? '', $field . ' · файл'),
        'alt' => tv_clean_string($value['alt'] ?? '', $field . ' · описание', 300),
        'caption' => tv_clean_string($value['caption'] ?? '', $field . ' · подпись', 200),
        'width' => tv_clean_dimension($value['width'] ?? 0),
        'height' => tv_clean_dimension($value['height'] ?? 0),
    ];
    return array_merge($item, tv_normalize_image_extras($value, $field));
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

function tv_normalized_working_content(mixed $candidate): array
{
    $normalized = tv_normalize_content($candidate);
    $revision = is_array($candidate) && is_numeric($candidate['revision'] ?? null)
        ? max(0, (int) $candidate['revision'])
        : 0;
    $updatedAt = is_array($candidate) && is_string($candidate['updatedAt'] ?? null)
        ? trim((string) $candidate['updatedAt'])
        : '';
    $normalized['revision'] = $revision;
    $normalized['updatedAt'] = $updatedAt;
    return $normalized;
}

function tv_clean_draft_id(mixed $value): string
{
    $id = is_string($value) ? strtolower(trim($value)) : '';
    if ($id === '' || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
        throw new TvValidationException('Черновик не найден или его адрес повреждён.');
    }
    return $id;
}

function tv_draft_path(string $id): string
{
    return TV_DRAFTS_DIR . DIRECTORY_SEPARATOR . 'draft-' . tv_clean_draft_id($id) . '.json';
}

function tv_read_draft(string $id): array
{
    $path = tv_draft_path($id);
    if (!is_file($path)) {
        throw new TvValidationException('Этот черновик уже удалён или не существует.');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Не удалось прочитать черновик.');
    }
    $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($record) || (int) ($record['schemaVersion'] ?? 0) !== 1 || !is_array($record['content'] ?? null)) {
        throw new RuntimeException('Файл черновика повреждён.');
    }
    $record['id'] = tv_clean_draft_id($record['id'] ?? $id);
    $record['name'] = tv_clean_string($record['name'] ?? '', 'Название черновика', 120, true);
    $record['content'] = tv_normalized_working_content($record['content']);
    return $record;
}

function tv_draft_metadata(array $record): array
{
    return [
        'id' => (string) $record['id'],
        'name' => (string) $record['name'],
        'createdAt' => (string) ($record['createdAt'] ?? ''),
        'updatedAt' => (string) ($record['updatedAt'] ?? ''),
        'baseRevision' => (int) ($record['baseRevision'] ?? ($record['content']['revision'] ?? 0)),
        'stats' => tv_content_stats($record['content']),
    ];
}

function tv_list_drafts(): array
{
    tv_ensure_storage();
    $files = glob(TV_DRAFTS_DIR . DIRECTORY_SEPARATOR . 'draft-*.json') ?: [];
    $drafts = [];
    foreach ($files as $file) {
        try {
            $id = preg_replace('/^draft-|\.json$/', '', basename($file));
            $drafts[] = tv_draft_metadata(tv_read_draft((string) $id));
        } catch (Throwable $error) {
            error_log('TV admin skipped draft ' . basename($file) . ': ' . $error->getMessage());
        }
    }
    usort($drafts, static fn(array $left, array $right): int => strcmp((string) $right['updatedAt'], (string) $left['updatedAt']));
    return array_slice($drafts, 0, 50);
}

function tv_save_draft(mixed $candidate, mixed $nameValue, mixed $idValue = null): array
{
    tv_ensure_storage();
    $name = tv_clean_string($nameValue, 'Название черновика', 120, true);
    $content = tv_normalized_working_content($candidate);
    $now = gmdate('c');
    $id = is_string($idValue) && trim($idValue) !== '' ? tv_clean_draft_id($idValue) : bin2hex(random_bytes(16));
    $path = tv_draft_path($id);
    $createdAt = $now;
    if (is_file($path)) {
        $existing = tv_read_draft($id);
        $createdAt = (string) ($existing['createdAt'] ?? $now);
    } elseif (count(tv_list_drafts()) >= 50) {
        throw new TvValidationException('Сохранено уже 50 черновиков. Удалите ненужный и повторите сохранение.');
    }
    $record = [
        'schemaVersion' => 1,
        'id' => $id,
        'name' => $name,
        'createdAt' => $createdAt,
        'updatedAt' => $now,
        'baseRevision' => (int) ($content['revision'] ?? 0),
        'content' => $content,
    ];
    tv_atomic_write(
        $path,
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
    );
    return $record;
}

function tv_delete_draft(string $id): void
{
    $path = tv_draft_path($id);
    if (!is_file($path)) {
        throw new TvValidationException('Этот черновик уже удалён.');
    }
    if (!unlink($path)) {
        throw new RuntimeException('Не удалось удалить черновик.');
    }
}

function tv_cleanup_previews(): void
{
    $files = glob(TV_PREVIEWS_DIR . DIRECTORY_SEPARATOR . 'preview-*.json') ?: [];
    $cutoff = time() - 2 * 60 * 60;
    foreach ($files as $file) {
        $modified = @filemtime($file);
        if (is_int($modified) && $modified < $cutoff) {
            @unlink($file);
        }
    }
}

function tv_prepare_preview(mixed $candidate): string
{
    tv_ensure_storage();
    tv_cleanup_previews();
    $token = bin2hex(random_bytes(24));
    $record = [
        'schemaVersion' => 1,
        'createdAt' => gmdate('c'),
        'content' => tv_normalized_working_content($candidate),
    ];
    tv_atomic_write(
        TV_PREVIEWS_DIR . DIRECTORY_SEPARATOR . 'preview-' . $token . '.json',
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
    $_SESSION['preview_token'] = $token;
    return $token;
}

function tv_read_preview(string $token): array
{
    $token = strtolower(trim($token));
    $sessionToken = is_string($_SESSION['preview_token'] ?? null) ? (string) $_SESSION['preview_token'] : '';
    if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1 || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        throw new TvValidationException('Ссылка предпросмотра устарела. Создайте её заново в панели.');
    }
    $path = TV_PREVIEWS_DIR . DIRECTORY_SEPARATOR . 'preview-' . $token . '.json';
    if (!is_file($path) || (int) @filemtime($path) < time() - 2 * 60 * 60) {
        throw new TvValidationException('Предпросмотр истёк. Откройте его заново из панели.');
    }
    $raw = file_get_contents($path);
    $record = $raw !== false ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($record) || !is_array($record['content'] ?? null)) {
        throw new RuntimeException('Файл предпросмотра повреждён.');
    }
    return tv_normalized_working_content($record['content']);
}

function tv_history_files(): array
{
    $files = glob(TV_HISTORY_DIR . DIRECTORY_SEPARATOR . 'site-r*.json');
    if (!is_array($files)) {
        return [];
    }

    $files = array_values(array_filter($files, static function (string $file): bool {
        return is_file($file) && preg_match('/^site-r[0-9]{6,12}-[0-9]{8}-[0-9]{6}\.json$/', basename($file)) === 1;
    }));
    usort($files, static function (string $left, string $right): int {
        $timeComparison = ((int) filemtime($left)) <=> ((int) filemtime($right));
        return $timeComparison !== 0 ? $timeComparison : strcmp(basename($left), basename($right));
    });
    return $files;
}

function tv_history_snapshot_name(array $content): string
{
    return sprintf('site-r%06d-%s.json', (int) ($content['revision'] ?? 0), gmdate('Ymd-His'));
}

function tv_write_history_snapshot(array $content): string
{
    if (!is_dir(TV_HISTORY_DIR) && !mkdir(TV_HISTORY_DIR, 0750, true) && !is_dir(TV_HISTORY_DIR)) {
        throw new RuntimeException('Не удалось создать историю версий.');
    }
    $name = tv_history_snapshot_name($content);
    tv_atomic_write(
        TV_HISTORY_DIR . DIRECTORY_SEPARATOR . $name,
        json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
    );
    return pathinfo($name, PATHINFO_FILENAME);
}

function tv_prune_history(): void
{
    $files = tv_history_files();
    if (!$files) {
        return;
    }

    while (count($files) > TV_HISTORY_HARD_LIMIT) {
        $oldFile = array_shift($files);
        if (is_string($oldFile) && is_file($oldFile) && !@unlink($oldFile)) {
            error_log('TV admin could not prune history file: ' . basename($oldFile));
        }
    }

    $cutoff = time() - (TV_HISTORY_RECOVERY_HOURS * 3600);
    while (count($files) > TV_HISTORY_MIN_KEEP) {
        $oldFile = $files[0] ?? null;
        if (!is_string($oldFile) || (int) filemtime($oldFile) >= $cutoff) {
            break;
        }
        array_shift($files);
        if (is_file($oldFile) && !@unlink($oldFile)) {
            error_log('TV admin could not prune history file: ' . basename($oldFile));
        }
    }
}

function tv_history_path(string $historyId): string
{
    $historyId = trim($historyId);
    if ($historyId === '' || basename($historyId) !== $historyId
        || preg_match('/^site-r[0-9]{6,12}-[0-9]{8}-[0-9]{6}$/', $historyId) !== 1) {
        throw new TvValidationException('Выбрана некорректная версия истории.');
    }
    $path = TV_HISTORY_DIR . DIRECTORY_SEPARATOR . $historyId . '.json';
    if (!is_file($path)) {
        throw new TvValidationException('Эта версия больше не найдена. Обновите список истории.');
    }
    return $path;
}

function tv_read_history_snapshot(string $historyId): array
{
    $path = tv_history_path($historyId);
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Не удалось прочитать выбранную версию.');
    }
    $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot) || (int) ($snapshot['schemaVersion'] ?? 0) !== 1) {
        throw new TvValidationException('Выбранная версия повреждена или имеет неподдерживаемый формат.');
    }
    return $snapshot;
}

function tv_history_captured_at(string $file): string
{
    $name = basename($file);
    if (preg_match('/^site-r[0-9]{6,12}-([0-9]{8}-[0-9]{6})\.json$/', $name, $matches) === 1) {
        $date = DateTimeImmutable::createFromFormat('!Ymd-His', $matches[1], new DateTimeZone('UTC'));
        if ($date instanceof DateTimeImmutable) {
            return $date->format(DateTimeInterface::ATOM);
        }
    }
    return gmdate(DateTimeInterface::ATOM, (int) filemtime($file));
}

function tv_content_stats(array $content): array
{
    $projects = is_array($content['projects'] ?? null) ? $content['projects'] : [];
    $media = is_array($content['site']['media'] ?? null) ? $content['site']['media'] : [];
    $projectPhotos = 0;
    $hiddenProjects = 0;
    foreach ($projects as $project) {
        if (!is_array($project)) {
            continue;
        }
        $projectPhotos += is_array($project['photos'] ?? null) ? count($project['photos']) : 0;
        if (empty($project['visible'])) {
            $hiddenProjects++;
        }
    }
    $sitePhotos = 0;
    foreach ($media as $items) {
        $sitePhotos += is_array($items) ? count($items) : 0;
    }
    return [
        'projects' => count($projects),
        'hiddenProjects' => $hiddenProjects,
        'projectPhotos' => $projectPhotos,
        'sitePhotos' => $sitePhotos,
    ];
}

function tv_project_label(array $project): string
{
    $title = trim((string) ($project['title'] ?? '') . ' ' . (string) ($project['accent'] ?? ''));
    return $title !== '' ? $title : (string) ($project['id'] ?? 'Проект');
}

function tv_content_change_summary(array $current, array $target): array
{
    $currentProjects = [];
    foreach (($current['projects'] ?? []) as $project) {
        if (is_array($project) && isset($project['id'])) {
            $currentProjects[(string) $project['id']] = $project;
        }
    }
    $targetProjects = [];
    foreach (($target['projects'] ?? []) as $project) {
        if (is_array($project) && isset($project['id'])) {
            $targetProjects[(string) $project['id']] = $project;
        }
    }

    $returnedIds = array_values(array_diff(array_keys($targetProjects), array_keys($currentProjects)));
    $removedIds = array_values(array_diff(array_keys($currentProjects), array_keys($targetProjects)));
    $changedIds = [];
    foreach (array_intersect(array_keys($currentProjects), array_keys($targetProjects)) as $id) {
        $currentProject = $currentProjects[$id];
        $targetProject = $targetProjects[$id];
        unset($currentProject['order'], $targetProject['order']);
        if ($currentProject !== $targetProject) {
            $changedIds[] = $id;
        }
    }
    $currentOrder = array_values(array_map(static fn(array $project): string => (string) ($project['id'] ?? ''), $current['projects'] ?? []));
    $targetOrder = array_values(array_map(static fn(array $project): string => (string) ($project['id'] ?? ''), $target['projects'] ?? []));
    $orderChanged = $currentOrder !== $targetOrder;
    $contactsChanged = ($current['site']['contacts'] ?? null) !== ($target['site']['contacts'] ?? null);
    $mediaChanged = ($current['site']['media'] ?? null) !== ($target['site']['media'] ?? null);

    $details = [];
    if ($returnedIds) {
        $labels = array_map(static fn(string $id): string => tv_project_label($targetProjects[$id]), array_slice($returnedIds, 0, 3));
        $suffix = count($returnedIds) > 3 ? ' и ещё ' . (count($returnedIds) - 3) : '';
        $details[] = 'Вернутся проекты: ' . implode(', ', $labels) . $suffix . '.';
    }
    if ($removedIds) {
        $labels = array_map(static fn(string $id): string => tv_project_label($currentProjects[$id]), array_slice($removedIds, 0, 3));
        $suffix = count($removedIds) > 3 ? ' и ещё ' . (count($removedIds) - 3) : '';
        $details[] = 'Из текущей версии уйдут проекты: ' . implode(', ', $labels) . $suffix . '.';
    }
    if ($changedIds) {
        $details[] = 'Изменятся карточки проектов: ' . count($changedIds) . '.';
    }
    if ($orderChanged) {
        $details[] = 'Изменится порядок проектов.';
    }
    if ($mediaChanged) {
        $details[] = 'Изменятся фотографии основных разделов.';
    }
    if ($contactsChanged) {
        $details[] = 'Изменятся контакты или ссылки на соцсети.';
    }
    if (!$details) {
        $details[] = 'Содержимое этой версии совпадает с текущим.';
    }

    return [
        'projectsReturned' => count($returnedIds),
        'projectsRemoved' => count($removedIds),
        'projectsChanged' => count($changedIds),
        'orderChanged' => $orderChanged,
        'mediaChanged' => $mediaChanged,
        'contactsChanged' => $contactsChanged,
        'hasChanges' => (bool) ($returnedIds || $removedIds || $changedIds || $orderChanged || $mediaChanged || $contactsChanged),
        'details' => $details,
    ];
}

function tv_list_history(int $limit = TV_HISTORY_MIN_KEEP): array
{
    $current = tv_read_content();
    $files = array_reverse(tv_history_files());
    $entries = [];
    foreach (array_slice($files, 0, max(1, min($limit, TV_HISTORY_HARD_LIMIT))) as $file) {
        try {
            $historyId = pathinfo($file, PATHINFO_FILENAME);
            $snapshot = tv_read_history_snapshot($historyId);
            $capturedAt = tv_history_captured_at($file);
            $capturedTimestamp = strtotime($capturedAt) ?: 0;
            $entries[] = [
                'id' => $historyId,
                'revision' => (int) ($snapshot['revision'] ?? 0),
                'capturedAt' => $capturedAt,
                'withinRecoveryWindow' => $capturedTimestamp >= time() - (TV_HISTORY_RECOVERY_HOURS * 3600),
                'stats' => tv_content_stats($snapshot),
                'changes' => tv_content_change_summary($current, $snapshot),
            ];
        } catch (Throwable $error) {
            error_log('TV admin skipped history file ' . basename($file) . ': ' . $error->getMessage());
        }
    }
    return $entries;
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

        tv_write_history_snapshot($current);
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

function tv_restore_content(string $historyId, int $expectedRevision): array
{
    tv_ensure_storage();
    $lockPath = TV_STORAGE_DIR . DIRECTORY_SEPARATOR . 'content.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Не удалось заблокировать контент для восстановления.');
    }
    try {
        $current = tv_read_content();
        $currentRevision = (int) ($current['revision'] ?? 0);
        if ($expectedRevision !== $currentRevision) {
            throw new TvConflictException('Контент уже изменён в другой вкладке. Обновите историю и повторите восстановление.');
        }

        $snapshot = tv_read_history_snapshot($historyId);
        $normalizedCurrent = tv_normalize_content($current);
        $normalized = tv_normalize_content($snapshot);
        if ($normalizedCurrent === $normalized) {
            throw new TvValidationException('Эта версия уже совпадает с текущим содержимым сайта.');
        }

        $normalized['revision'] = $currentRevision + 1;
        $normalized['updatedAt'] = gmdate('c');
        tv_write_history_snapshot($current);
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

function tv_image_mime_from_info(array $imageInfo): string
{
    return match ((int) ($imageInfo[2] ?? 0)) {
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
        default => throw new TvValidationException('Разрешены только JPEG, PNG и WebP.'),
    };
}

function tv_verified_image_info(string $temporary): array
{
    $imageInfo = @getimagesize($temporary);
    if (!is_array($imageInfo) || (int) ($imageInfo[0] ?? 0) < 1 || (int) ($imageInfo[1] ?? 0) < 1) {
        throw new TvValidationException('Файл не распознан как корректное изображение.');
    }
    $mime = tv_image_mime_from_info($imageInfo);
    if (class_exists('finfo')) {
        $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if ($detected !== '' && $detected !== 'application/octet-stream' && $detected !== $mime) {
            throw new TvValidationException('Содержимое файла не соответствует его типу.');
        }
    }
    return ['info' => $imageInfo, 'mime' => $mime];
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
    if ($size < 1 || $size > tv_effective_prepared_file_limit() || !is_uploaded_file($temporary)) {
        throw new TvValidationException('Файл должен быть изображением и укладываться в лимит сервера.');
    }
    $verified = tv_verified_image_info($temporary);
    $imageInfo = $verified['info'];
    $mime = $verified['mime'];
    $extension = tv_image_extension($mime);
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

function tv_upload_file_info(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new TvValidationException('Один из подготовленных размеров не загрузился. Повторите попытку.');
    }
    $temporary = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > tv_effective_prepared_file_limit() || !is_uploaded_file($temporary)) {
        throw new TvValidationException('Подготовленное изображение превышает лимит сервера или не загрузилось.');
    }
    $verified = tv_verified_image_info($temporary);
    $imageInfo = $verified['info'];
    $mime = $verified['mime'];
    $extension = tv_image_extension($mime);
    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 5000 || $height > 5000 || $width * $height > 24000000) {
        throw new TvValidationException('Подготовленное изображение имеет недопустимое разрешение.');
    }
    return [
        'temporary' => $temporary,
        'size' => $size,
        'mime' => $mime,
        'extension' => $extension,
        'width' => $width,
        'height' => $height,
    ];
}

function tv_manifest_crop(mixed $value): ?array
{
    return tv_normalize_crop($value);
}

function tv_handle_upload_set(array $files, mixed $manifestValue): array
{
    if (!is_array($manifestValue)) {
        throw new TvValidationException('Не получен план подготовки фотографии.');
    }
    $entries = $manifestValue['files'] ?? null;
    if (!is_array($entries) || count($entries) < 2 || count($entries) > 16) {
        throw new TvValidationException('Набор размеров фотографии неполный или слишком большой.');
    }
    $profile = strtolower(tv_clean_string($manifestValue['profile'] ?? '', 'Профиль фотографии', 40, true));
    if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $profile) !== 1) {
        throw new TvValidationException('Профиль фотографии имеет неверный формат.');
    }
    $allowedRoles = ['master' => true, 'default' => true, 'card' => true, 'mobile' => true];
    $validated = [];
    $seenFields = [];
    $totalBytes = 0;
    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) {
            throw new TvValidationException('Описание размера фотографии повреждено.');
        }
        $field = is_string($entry['field'] ?? null) ? (string) $entry['field'] : '';
        $role = is_string($entry['role'] ?? null) ? strtolower((string) $entry['role']) : '';
        if (preg_match('/^asset_[a-z0-9_]{1,40}$/', $field) !== 1 || !isset($allowedRoles[$role]) || isset($seenFields[$field])) {
            throw new TvValidationException('Описание файлов фотографии содержит недопустимые значения.');
        }
        if (!isset($files[$field]) || !is_array($files[$field])) {
            throw new TvValidationException('Не все подготовленные размеры дошли до сервера.');
        }
        $info = tv_upload_file_info($files[$field]);
        $expectedWidth = tv_clean_dimension($entry['width'] ?? 0);
        $expectedHeight = tv_clean_dimension($entry['height'] ?? 0);
        if ($expectedWidth !== $info['width'] || $expectedHeight !== $info['height']) {
            throw new TvValidationException('Размер подготовленного изображения не совпадает с планом загрузки.');
        }
        $totalBytes += (int) $info['size'];
        if ($totalBytes > tv_effective_upload_set_limit()) {
            throw new TvValidationException('Все версии фотографии вместе превышают лимит сервера. Уменьшите исходник и повторите.');
        }
        $seenFields[$field] = true;
        $validated[] = [
            'field' => $field,
            'role' => $role,
            'width' => $info['width'],
            'height' => $info['height'],
            'temporary' => $info['temporary'],
            'extension' => $info['extension'],
            'index' => (int) $index,
        ];
    }

    $roleCounts = array_count_values(array_column($validated, 'role'));
    if (($roleCounts['master'] ?? 0) !== 1 || ($roleCounts['default'] ?? 0) < 1) {
        throw new TvValidationException('Нужны мастер-файл и хотя бы один размер для сайта.');
    }

    $relativeDirectory = 'uploads/' . gmdate('Y/m');
    $directory = TV_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать папку для загрузки.');
    }
    $baseName = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(10));
    $storedPaths = [];
    $grouped = ['master' => [], 'default' => [], 'card' => [], 'mobile' => []];
    try {
        foreach ($validated as $entry) {
            $suffix = $entry['role'] . '-' . str_pad((string) $entry['width'], 4, '0', STR_PAD_LEFT) . '-' . $entry['index'];
            $filename = $baseName . '-' . $suffix . '.' . $entry['extension'];
            $destination = $directory . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file($entry['temporary'], $destination)) {
                throw new RuntimeException('Не удалось сохранить один из размеров фотографии.');
            }
            @chmod($destination, 0644);
            $storedPaths[] = $destination;
            $grouped[$entry['role']][] = [
                'src' => $relativeDirectory . '/' . $filename,
                'width' => $entry['width'],
                'height' => $entry['height'],
            ];
        }
    } catch (Throwable $error) {
        foreach ($storedPaths as $path) {
            @unlink($path);
        }
        throw $error;
    }
    foreach ($grouped as &$variants) {
        usort($variants, static fn(array $left, array $right): int => $left['width'] <=> $right['width']);
    }
    unset($variants);

    $defaultSmall = $grouped['default'][0];
    $defaultLarge = $grouped['default'][count($grouped['default']) - 1];
    $master = $grouped['master'][0];
    $photo = [
        'src' => $defaultLarge['src'],
        'thumb' => $defaultSmall['src'],
        'alt' => '',
        'width' => $defaultLarge['width'],
        'height' => $defaultLarge['height'],
        'cropProfile' => $profile,
        'master' => [
            'src' => $master['src'],
            'width' => $master['width'],
            'height' => $master['height'],
            'variants' => [],
        ],
        'variants' => $grouped['default'],
    ];
    $crops = is_array($manifestValue['crops'] ?? null) ? $manifestValue['crops'] : [];
    $defaultCrop = tv_manifest_crop($crops['default'] ?? null);
    if ($defaultCrop !== null) {
        $photo['crop'] = $defaultCrop;
    }
    foreach (['card', 'mobile'] as $role) {
        if ($grouped[$role] === []) {
            continue;
        }
        $large = $grouped[$role][count($grouped[$role]) - 1];
        $rendition = [
            'src' => $large['src'],
            'width' => $large['width'],
            'height' => $large['height'],
            'variants' => $grouped[$role],
        ];
        $crop = tv_manifest_crop($crops[$role] ?? null);
        if ($crop !== null) {
            $rendition['crop'] = $crop;
        }
        $photo[$role] = $rendition;
    }
    return $photo;
}
