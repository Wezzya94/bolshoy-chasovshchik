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
        if ($action === 'content') {
            tv_json_response([
                'ok' => true,
                'csrf' => tv_csrf_token(),
                'content' => tv_read_content(),
                'capabilities' => [
                    'gd' => extension_loaded('gd') && function_exists('imagewebp'),
                    'maxUploadBytes' => TV_MAX_UPLOAD_BYTES,
                    'maxPreparedFileBytes' => tv_effective_prepared_file_limit(),
                    'maxUploadSetBytes' => tv_effective_upload_set_limit(),
                    'phpVersion' => PHP_VERSION,
                    'historyHours' => TV_HISTORY_RECOVERY_HOURS,
                    'historyMinimumVersions' => TV_HISTORY_MIN_KEEP,
                ],
            ]);
        }
        if ($action === 'history') {
            tv_json_response([
                'ok' => true,
                'history' => tv_list_history(),
                'policy' => [
                    'recoveryHours' => TV_HISTORY_RECOVERY_HOURS,
                    'minimumVersions' => TV_HISTORY_MIN_KEEP,
                ],
            ]);
        }
        if ($action === 'drafts') {
            tv_json_response([
                'ok' => true,
                'drafts' => tv_list_drafts(),
            ]);
        }
        if ($action === 'draft') {
            $draft = tv_read_draft(is_string($_GET['id'] ?? null) ? (string) $_GET['id'] : '');
            tv_json_response([
                'ok' => true,
                'draft' => $draft,
            ]);
        }
        tv_json_response(['ok' => false, 'error' => 'Неизвестное действие.'], 404);
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

    if ($action === 'upload-set') {
        $manifestRaw = is_string($_POST['manifest'] ?? null) ? (string) $_POST['manifest'] : '';
        if ($manifestRaw === '') {
            throw new TvValidationException('Не получены параметры подготовки фотографии.');
        }
        $manifest = json_decode($manifestRaw, true, 128, JSON_THROW_ON_ERROR);
        $photo = tv_handle_upload_set($_FILES, $manifest);
        tv_json_response([
            'ok' => true,
            'photo' => $photo,
            'message' => 'Фотография обрезана, подготовлена для телефона и компьютера и загружена.',
        ]);
    }

    if ($action === 'save-draft') {
        $request = tv_read_json_body();
        $draft = tv_save_draft($request['content'] ?? null, $request['name'] ?? '', $request['id'] ?? null);
        tv_json_response([
            'ok' => true,
            'draft' => tv_draft_metadata($draft),
            'drafts' => tv_list_drafts(),
            'message' => 'Черновик сохранён отдельно. Опубликованный сайт не изменился, история публикаций не создавалась.',
        ]);
    }

    if ($action === 'delete-draft') {
        $request = tv_read_json_body();
        $id = is_string($request['id'] ?? null) ? (string) $request['id'] : '';
        tv_delete_draft($id);
        tv_json_response([
            'ok' => true,
            'drafts' => tv_list_drafts(),
            'message' => 'Черновик удалён. Опубликованный сайт не менялся.',
        ]);
    }

    if ($action === 'prepare-preview') {
        $request = tv_read_json_body();
        $token = tv_prepare_preview($request['content'] ?? null);
        tv_json_response([
            'ok' => true,
            'url' => 'preview.php?token=' . rawurlencode($token),
            'message' => 'Предпросмотр подготовлен. Эти правки не опубликованы.',
        ]);
    }

    if ($action === 'restore') {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($contentType, 'application/json')) {
            tv_json_response(['ok' => false, 'error' => 'Для восстановления требуется application/json.'], 415);
        }
        $request = tv_read_json_body();
        $historyId = is_string($request['historyId'] ?? null) ? $request['historyId'] : '';
        $restored = tv_restore_content($historyId, (int) ($request['revision'] ?? -1));
        tv_json_response([
            'ok' => true,
            'content' => $restored,
            'history' => tv_list_history(),
            'message' => 'Выбранная версия восстановлена и опубликована. Предыдущее состояние сохранено в истории.',
        ]);
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
