<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

tv_security_headers();
tv_start_session();

function tv_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = '';
$notice = '';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

if ($method === 'POST' && $action === 'logout' && tv_is_authenticated()) {
    if (!tv_validate_csrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        $error = 'Защитный токен устарел. Обновите страницу.';
    } else {
        tv_logout_session();
        header('Location: ./', true, 303);
        exit;
    }
}

if ($method === 'POST' && $action === 'login' && !tv_is_authenticated()) {
    $csrf = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null;
    $retryAfter = tv_login_retry_after();
    if (!tv_validate_csrf($csrf)) {
        $error = 'Страница входа устарела. Обновите её и повторите попытку.';
    } elseif (!tv_has_password()) {
        $error = 'Пароль администратора ещё не настроен. Выполните шаги из ADMIN_GUIDE.md.';
    } elseif ($retryAfter > 0) {
        $error = 'Слишком много попыток. Повторите вход через ' . $retryAfter . ' сек.';
    } else {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (tv_login($password)) {
            header('Location: ./', true, 303);
            exit;
        }
        $retryAfter = tv_login_retry_after();
        $error = $retryAfter > 0
            ? 'Неверный пароль. Следующая попытка будет доступна через ' . $retryAfter . ' сек.'
            : 'Неверный пароль.';
    }
}

$authenticated = tv_is_authenticated();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="color-scheme" content="dark">
  <meta name="csrf-token" content="<?= tv_h(tv_csrf_token()) ?>">
  <title><?= $authenticated ? 'Управление сайтом' : 'Вход' ?> · Твоё Время</title>
  <link rel="icon" type="image/svg+xml" href="../assets/icons/favicon-mark.svg?v=2">
  <link rel="stylesheet" href="assets/admin.css?v=3">
</head>
<body class="<?= $authenticated ? 'admin-app-page' : 'login-page' ?>">
<?php if (!$authenticated): ?>
  <main class="login-shell">
    <section class="login-card" aria-labelledby="loginTitle">
      <a class="login-logo" href="../" aria-label="Вернуться на сайт">
        <img src="../assets/logo.webp" width="420" height="152" alt="Твоё Время — Мастерская антикварных часов">
      </a>
      <div class="eyebrow">Закрытый раздел</div>
      <h1 id="loginTitle">Управление сайтом</h1>
      <p class="login-intro">Введите пароль владельца, чтобы редактировать проекты, фотографии и контакты.</p>
      <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= tv_h($error) ?></div>
      <?php endif; ?>
      <?php if (!tv_has_password()): ?>
        <div class="alert alert-warning" role="status">Конфигурация пароля отсутствует. Панель закрыта до завершения настройки.</div>
      <?php endif; ?>
      <form method="post" class="login-form">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= tv_h(tv_csrf_token()) ?>">
        <label for="password">Пароль</label>
        <div class="password-row">
          <input id="password" name="password" type="password" minlength="1" maxlength="128" autocomplete="current-password" required autofocus <?= tv_has_password() ? '' : 'disabled' ?>>
          <button class="icon-button password-toggle" type="button" aria-label="Показать пароль" aria-pressed="false">Показать</button>
        </div>
        <button class="button button-primary button-full" type="submit" <?= tv_has_password() ? '' : 'disabled' ?>>Войти</button>
      </form>
      <a class="back-link" href="../">← Вернуться на сайт</a>
    </section>
  </main>
  <script src="assets/admin.js?v=3"></script>
<?php else: ?>
  <div class="app-shell" id="adminApp">
    <header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Открыть меню" aria-controls="adminSidebar" aria-expanded="false">☰</button>
      <a class="topbar-brand" href="#dashboard" data-nav="dashboard">
        <img src="../assets/logo.webp" width="210" height="76" alt="Твоё Время">
        <span>Панель управления</span>
      </a>
      <div class="topbar-actions">
        <span class="save-indicator" id="saveIndicator" data-state="saved">Все изменения сохранены</span>
        <a class="button button-ghost" href="../" target="_blank" rel="noopener">Открыть сайт ↗</a>
        <form method="post" id="logoutForm">
          <input type="hidden" name="action" value="logout">
          <input type="hidden" name="csrf" value="<?= tv_h(tv_csrf_token()) ?>">
          <button class="button button-ghost" type="submit">Выйти</button>
        </form>
      </div>
    </header>

    <aside class="sidebar" id="adminSidebar">
      <nav aria-label="Разделы панели">
        <button class="nav-item active" type="button" data-section-target="dashboard"><span>01</span>Обзор</button>
        <button class="nav-item" type="button" data-section-target="projects"><span>02</span>Проекты</button>
        <button class="nav-item" type="button" data-section-target="media"><span>03</span>Фотографии сайта</button>
        <button class="nav-item" type="button" data-section-target="contacts"><span>04</span>Контакты и соцсети</button>
        <button class="nav-item" type="button" data-section-target="security"><span>05</span>Безопасность</button>
      </nav>
      <div class="sidebar-note">Изменения становятся видны на сайте сразу после публикации.</div>
    </aside>

    <main class="admin-main" aria-live="polite">
      <div class="loading-panel" id="loadingPanel">
        <span class="spinner" aria-hidden="true"></span>
        <p>Загружаю содержимое сайта…</p>
      </div>

      <section class="admin-section active" id="section-dashboard" data-section="dashboard" hidden>
        <div class="section-heading">
          <div><div class="eyebrow">Состояние сайта</div><h1>Обзор</h1></div>
          <button class="button button-primary save-all" type="button">Опубликовать изменения</button>
        </div>
        <div class="metric-grid">
          <article class="metric-card"><span>Проектов</span><strong id="metricProjects">—</strong><small id="metricHidden">—</small></article>
          <article class="metric-card"><span>Фотографий проектов</span><strong id="metricPhotos">—</strong><small>в галереях</small></article>
          <article class="metric-card"><span>Фотографий сайта</span><strong id="metricMedia">—</strong><small>в основных разделах</small></article>
          <article class="metric-card"><span>Версия контента</span><strong id="metricRevision">—</strong><small id="metricUpdated">—</small></article>
        </div>
        <div class="panel two-column-panel">
          <div><h2>Быстрый старт</h2><p>Добавляйте проекты как черновики, загружайте фотографии, проверяйте карточку и только потом включайте видимость.</p></div>
          <div class="quick-actions">
            <button class="button button-secondary new-project" type="button">+ Новый проект</button>
            <button class="button button-secondary" type="button" data-go-section="media">Заменить фотографию</button>
            <button class="button button-secondary" type="button" data-go-section="contacts">Обновить контакты</button>
          </div>
        </div>
      </section>

      <section class="admin-section" id="section-projects" data-section="projects" hidden>
        <div class="section-heading">
          <div><div class="eyebrow">Карточки и галереи</div><h1>Проекты</h1><p>Перетаскивайте карточки или используйте стрелки. Скрытый проект остаётся в панели, но исчезает с сайта.</p></div>
          <div class="heading-actions"><button class="button button-secondary new-project" type="button">+ Новый проект</button><button class="button button-primary save-all" type="button">Опубликовать</button></div>
        </div>
        <div class="projects-list" id="projectsList"></div>
      </section>

      <section class="admin-section" id="section-media" data-section="media" hidden>
        <div class="section-heading">
          <div><div class="eyebrow">Основные разделы</div><h1>Фотографии сайта</h1><p>Первый экран, направления, блок мастера, мастерская и сертификаты. Новая фотография загружается сразу, но появляется на сайте только после публикации.</p></div>
          <button class="button button-primary save-all" type="button">Опубликовать</button>
        </div>
        <div id="mediaGroups" class="media-groups"></div>
      </section>

      <section class="admin-section" id="section-contacts" data-section="contacts" hidden>
        <div class="section-heading">
          <div><div class="eyebrow">Прямая связь</div><h1>Контакты и соцсети</h1><p>Ссылки обновляются одновременно в меню, контактном блоке, подвале и структурированных данных сайта.</p></div>
          <button class="button button-primary save-all" type="button">Опубликовать</button>
        </div>
        <form class="panel form-panel" id="contactsForm">
          <div class="form-grid two-columns">
            <label>Город<input name="city" maxlength="100" required></label>
            <label>География работы<input name="serviceArea" maxlength="180" required></label>
            <label>Телефон для показа<input name="phoneDisplay" maxlength="40" required></label>
            <label>Телефон в формате +7…<input name="phoneE164" maxlength="20" required></label>
            <label>Подпись Telegram<input name="telegramLabel" maxlength="80" required></label>
            <label>Ссылка Telegram<input name="telegramUrl" type="url" maxlength="500" required></label>
            <label class="full-column">Ссылка WhatsApp<input name="whatsappUrl" type="url" maxlength="500" required></label>
          </div>
          <div class="subheading-row"><div><h2>Социальные сети</h2><p>Порядок в этом списке совпадает с порядком на сайте.</p></div><button class="button button-secondary" id="addSocial" type="button">+ Добавить ссылку</button></div>
          <div id="socialsList" class="socials-editor"></div>
        </form>
      </section>

      <section class="admin-section" id="section-security" data-section="security" hidden>
        <div class="section-heading"><div><div class="eyebrow">Доступ владельца</div><h1>Безопасность</h1><p>Используйте уникальную длинную парольную фразу. После смены пароля другие открытые сессии завершатся.</p></div></div>
        <form class="panel security-form" id="passwordForm">
          <label>Текущий пароль<input name="currentPassword" type="password" autocomplete="current-password" maxlength="128" required></label>
          <label>Новый пароль<input name="newPassword" type="password" autocomplete="new-password" minlength="15" maxlength="128" required><small>Минимум 15 символов; разрешены пробелы и кириллица.</small></label>
          <label>Повторите новый пароль<input name="confirmPassword" type="password" autocomplete="new-password" minlength="15" maxlength="128" required></label>
          <button class="button button-primary" type="submit">Изменить пароль</button>
        </form>
      </section>
    </main>

    <div class="publish-bar" id="publishBar" hidden>
      <span>Есть неопубликованные изменения</span>
      <button class="button button-primary save-all" type="button">Опубликовать</button>
    </div>
  </div>

  <dialog class="project-dialog" id="projectDialog" aria-labelledby="projectDialogTitle">
    <form method="dialog" class="dialog-shell" id="projectForm">
      <header class="dialog-header">
        <div><div class="eyebrow">Карточка проекта</div><h2 id="projectDialogTitle">Редактирование проекта</h2></div>
        <button class="icon-button dialog-close" value="cancel" formnovalidate aria-label="Закрыть">×</button>
      </header>
      <div class="dialog-body">
        <input type="hidden" name="originalId">
        <div class="form-grid two-columns">
          <label>ID / адрес карточки<input name="id" pattern="[a-z0-9][a-z0-9-]{0,63}" maxlength="64" required><small>Латиница, цифры и дефис. После публикации лучше не менять.</small></label>
          <label class="switch-label"><input name="visible" type="checkbox"><span>Показывать проект на сайте</span></label>
          <label>Основная часть названия<input name="title" maxlength="180" required></label>
          <label>Часть названия курсивом<input name="accent" maxlength="120"></label>
          <label>Категория на карточке<input name="type" maxlength="160" required></label>
          <label>Категория в галерее<input name="modalType" maxlength="160" required></label>
          <label class="full-column">Короткая подпись карточки<input name="cardLead" maxlength="300" required></label>
          <label class="full-column">Вступление в галерее<textarea name="lead" rows="3" maxlength="600" required></textarea></label>
          <label class="full-column">История проекта<textarea name="body" rows="9" maxlength="30000" placeholder="Один абзац.&#10;&#10;Следующий абзац."></textarea><small>Разделяйте абзацы пустой строкой.</small></label>
          <label class="full-column">Характеристики<textarea name="specs" rows="6" maxlength="10000" placeholder="Год | 1905&#10;Механизм | Оригинальный"></textarea><small>Одна строка — одна пара «название | значение».</small></label>
          <label class="full-column">Описание обложки для доступности<input name="coverAlt" maxlength="300"></label>
        </div>

        <div class="project-photos-heading">
          <div><h3>Фотографии проекта</h3><p>Первая отмеченная обложка показывается в сетке. Остальные идут в галерее в указанном порядке.</p></div>
          <label class="button button-secondary file-button">Загрузить фотографии<input id="projectPhotoUpload" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden></label>
        </div>
        <div class="upload-progress" id="projectUploadProgress" hidden></div>
        <div class="project-photos-editor" id="projectPhotosEditor"></div>
      </div>
      <footer class="dialog-footer">
        <button class="button button-ghost" value="cancel" formnovalidate>Отмена</button>
        <button class="button button-primary" id="saveProject" value="default">Сохранить и опубликовать</button>
      </footer>
    </form>
  </dialog>

  <div class="toast-region" id="toastRegion" aria-live="polite" aria-atomic="true"></div>
  <script src="assets/admin.js?v=3"></script>
<?php endif; ?>
</body>
</html>
