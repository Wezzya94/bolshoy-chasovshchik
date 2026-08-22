<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

tv_preview_security_headers();
tv_start_session();

if (!tv_is_authenticated()) {
    header('Location: ./', true, 303);
    exit;
}

try {
    $token = is_string($_GET['token'] ?? null) ? (string) $_GET['token'] : '';
    $content = tv_read_preview($token);
    $templatePath = TV_ROOT . DIRECTORY_SEPARATOR . 'index.html';
    $html = file_get_contents($templatePath);
    if ($html === false) {
        throw new RuntimeException('Главная страница сайта не найдена.');
    }
    $json = json_encode(
        $content,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
    $headInjection = "\n<base href=\"../\">\n<meta name=\"robots\" content=\"noindex,nofollow,noarchive\">\n"
        . '<script>window.TV_PREVIEW_CONTENT=' . $json . ";</script>\n";
    $html = preg_replace('/<head>/i', '<head>' . $headInjection, $html, 1) ?? $html;
    $banner = '<aside id="tvDraftPreviewBanner" style="position:fixed;z-index:2147483647;left:50%;top:12px;transform:translateX(-50%);display:flex;align-items:center;gap:12px;max-width:calc(100vw - 24px);padding:10px 14px;border:1px solid rgba(236,212,157,.72);background:rgba(12,10,8,.96);box-shadow:0 12px 40px rgba(0,0,0,.45);color:#f0e7d5;font:600 13px/1.35 system-ui,sans-serif;backdrop-filter:blur(14px)"><span style="color:#ecd49d">ПРЕДПРОСМОТР ЧЕРНОВИКА</span><span style="font-weight:400;color:#b8afa1">Посетители пока видят старую версию.</span><a href="admin/" style="color:#17130d;background:#d2aa61;padding:7px 10px;text-decoration:none;white-space:nowrap">Вернуться в панель</a></aside>';
    $html = preg_replace('/(<body[^>]*>)/i', '$1' . $banner, $html, 1) ?? $html;
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
} catch (Throwable $error) {
    http_response_code(410);
    $message = $error instanceof TvValidationException ? $error->getMessage() : 'Не удалось подготовить предпросмотр.';
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Предпросмотр недоступен</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0a09;color:#f0e7d5;font:16px/1.5 system-ui;padding:24px">'
        . '<main style="max-width:560px;border:1px solid #6e5b3d;padding:32px"><h1 style="font-size:30px">Предпросмотр недоступен</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p><a href="./" style="color:#ecd49d">Вернуться в панель и открыть предпросмотр заново</a></main></body></html>';
}
