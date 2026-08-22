<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

tv_security_headers();
tv_start_session();

$action = strtolower(trim((string) ($_GET['action'] ?? 'content')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        tv_require_auth();
        if ($action !== 'content') {
            tv_json_response(['ok' => false, 'error' => 'Неизвестное действие.'], 404);
        }
        tv_json_response([
            'ok' => true,
            'csrf' => tv_csrf_token(),
            'content' => tv_read_content(),
            'capabilities' => [
                'gd' => extension_loaded('gd') && function_exists('imagewebp'),
                'maxUploadBytes' => TV_MAX_UPLOAD_BYTES,
                'phpVersion' => PHP_VERSION,
            ],
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        tv_json_response(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
    }

    tv_require_auth();
    tv_require_same_origin();
    tv_require_csrf();

    if ($action === 'save') {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($contentType, 'application/json')) {
            tv_json_response(['ok' => false, 'error' => 'Для сохранения требуется application/json.'], 415);
        }
        $request = tv_read_json_body();
        $saved = tv_save_content($request['content'] ?? null, (int) ($request['revision'] ?? -1));
        tv_json_response(['ok' => true, 'content' => $saved, 'message' => 'Изменения опубликованы.']);
    }

    if ($action === 'upload') {
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            throw new TvValidationException('Выберите изображение для загрузки.');
        }
        $photo = tv_handle_upload($_FILES['image']);
        tv_json_response(['ok' => true, 'photo' => $photo, 'message' => 'Фотография загружена.']);
    }

    if ($action === 'change-password') {
        $request = tv_read_json_body();
        $currentPassword = is_string($request['currentPassword'] ?? null) ? $request['currentPassword'] : '';
        $newPassword = is_string($request['newPassword'] ?? null) ? $request['newPassword'] : '';
        if (!tv_verify_password($currentPassword)) {
            throw new TvValidationException('Текущий пароль указан неверно.');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            throw new TvValidationException('Новый пароль должен отличаться от текущего.');
        }
        tv_write_admin_password($newPassword);
        session_regenerate_id(true);
        $config = tv_admin_config();
        $_SESSION['credential_fingerprint'] = hash('sha256', (string) ($config['password_hash'] ?? ''));
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        tv_json_response(['ok' => true, 'csrf' => tv_csrf_token(), 'message' => 'Пароль изменён. Остальные сессии завершены.']);
    }

    if ($action === 'logout') {
        tv_logout_session();
        tv_json_response(['ok' => true, 'message' => 'Сессия завершена.']);
    }

    tv_json_response(['ok' => false, 'error' => 'Неизвестное действие.'], 404);
} catch (TvConflictException $error) {
    tv_json_response(['ok' => false, 'error' => $error->getMessage(), 'conflict' => true], 409);
} catch (TvValidationException $error) {
    tv_json_response(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (JsonException $error) {
    tv_json_response(['ok' => false, 'error' => 'Получен некорректный JSON.'], 400);
} catch (Throwable $error) {
    error_log('TV admin API error: ' . $error->getMessage());
    tv_json_response(['ok' => false, 'error' => 'Внутренняя ошибка. Проверьте права на папки content, storage и uploads.'], 500);
}
