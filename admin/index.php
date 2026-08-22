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
  <link rel="stylesheet" href="assets/admin.css?v=5">
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
  <script src="assets/admin.js?v=5"></script>
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
        <a class="button button-ghost" href="../" target="_blank" rel="noopener">Проверить сайт ↗</a>
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
        <button class="nav-item" type="button" data-section-target="history"><span>05</span>История и откат</button>
        <button class="nav-item" type="button" data-section-target="security"><span>06</span>Безопасность</button>
        <button class="nav-item" type="button" data-section-target="help"><span>07</span>Как пользоваться</button>
      </nav>
      <div class="sidebar-note"><strong>Важно:</strong> ввод и загрузка ещё ничего не меняют на сайте. Посетители увидят правки только после кнопки «Опубликовать».</div>
    </aside>

    <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Закрыть меню" tabindex="-1" hidden></button>

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
          <div><h2>Быстрый старт</h2><p>Добавляйте проекты скрытыми, заполняйте карточку, загружайте фотографии и только после проверки включайте показ на сайте.</p></div>
          <div class="quick-actions">
            <button class="button button-secondary new-project" type="button">+ Новый проект</button>
            <button class="button button-secondary" type="button" data-go-section="media">Заменить фотографию</button>
            <button class="button button-secondary" type="button" data-go-section="contacts">Обновить контакты</button>
          </div>
        </div>
        <div class="dashboard-support-grid">
          <article class="panel readiness-panel">
            <div class="panel-title-row"><div><div class="eyebrow">Перед публикацией</div><h2>Проверка заполнения</h2></div><span class="readiness-score" id="readinessScore">—</span></div>
            <div class="readiness-list" id="readinessChecklist"></div>
          </article>
          <article class="panel recovery-panel">
            <div class="eyebrow">Если что-то пошло не так</div>
            <h2>Вернуть прошлую версию</h2>
            <p>Каждая публикация создаёт страховочную копию. Текущая версия также сохранится перед откатом, поэтому восстановление можно отменить.</p>
            <div class="recovery-actions">
              <button class="button button-secondary" type="button" data-go-section="history">Открыть историю</button>
              <button class="button button-ghost download-backup" type="button">Скачать копию контента</button>
            </div>
          </article>
        </div>
      </section>

      <section class="admin-section" id="section-projects" data-section="projects" hidden>
        <div class="section-heading">
          <div><div class="eyebrow">Карточки и галереи</div><h1>Проекты</h1><p>Перетаскивайте карточки или используйте стрелки. Скрытый проект остаётся в панели, но исчезает с сайта.</p></div>
          <div class="heading-actions"><button class="button button-secondary new-project" type="button">+ Новый проект</button><button class="button button-primary save-all" type="button">Опубликовать</button></div>
        </div>
        <div class="projects-toolbar panel" role="search">
          <label class="project-search">Найти проект<input id="projectSearch" type="search" placeholder="Название или категория" autocomplete="off"></label>
          <label>Показать<select id="projectFilter"><option value="all">Все проекты</option><option value="visible">Только на сайте</option><option value="hidden">Только скрытые</option><option value="attention">Нужно проверить</option></select></label>
          <div class="projects-found" id="projectsFound" aria-live="polite"></div>
          <p class="toolbar-note" id="projectFilterNote" hidden>При поиске или фильтре порядок менять нельзя. Очистите фильтр, чтобы снова использовать стрелки и перетаскивание.</p>
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

      <section class="admin-section" id="section-history" data-section="history" hidden>
        <div class="section-heading">
          <div><div class="eyebrow">Страховочные копии</div><h1>История и откат</h1><p>Здесь находятся предыдущие опубликованные состояния сайта. Перед восстановлением панель покажет, что именно изменится.</p></div>
          <div class="heading-actions"><button class="button button-secondary download-backup" type="button">Скачать текущую копию</button><button class="button button-ghost" id="refreshHistory" type="button">Обновить список</button></div>
        </div>
        <div class="history-explainer panel">
          <div class="history-step"><span>1</span><div><strong>Выберите время</strong><small>Ориентируйтесь на дату и номер версии.</small></div></div>
          <div class="history-step"><span>2</span><div><strong>Прочитайте отличия</strong><small>Панель перечислит проекты, фотографии и контакты, которые поменяются.</small></div></div>
          <div class="history-step"><span>3</span><div><strong>Подтвердите откат</strong><small>Версия сразу появится на сайте, а нынешняя останется в истории.</small></div></div>
        </div>
        <div class="history-status" id="historyStatus" role="status">Загружаю историю…</div>
        <div class="history-list" id="historyList"></div>
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

      <section class="admin-section" id="section-help" data-section="help" hidden>
        <div class="section-heading"><div><div class="eyebrow">Короткая памятка</div><h1>Как пользоваться панелью</h1><p>Панель меняет только содержимое сайта: карточки, фотографии и контакты. Дизайн и расположение разделов она не перестраивает.</p></div></div>
        <div class="help-steps">
          <article class="panel help-card"><span class="help-number">01</span><h2>Сделайте правку</h2><p>Откройте нужный раздел. Текст можно печатать сразу; фотография сначала загружается во временно подготовленный файл.</p></article>
          <article class="panel help-card"><span class="help-number">02</span><h2>Проверьте подсказки</h2><p>Надпись «Есть неопубликованные изменения» означает, что посетители пока видят старый вариант. Ошибочные поля панель не даст опубликовать.</p></article>
          <article class="panel help-card"><span class="help-number">03</span><h2>Опубликуйте</h2><p>Нажмите золотую кнопку «Опубликовать». Только после успешного сообщения изменения становятся видны на сайте.</p></article>
          <article class="panel help-card"><span class="help-number">04</span><h2>Посмотрите глазами посетителя</h2><p>Нажмите «Проверить сайт» вверху и обновите открытую страницу. Для нового проекта проверьте карточку и все фотографии галереи.</p></article>
          <article class="panel help-card"><span class="help-number">05</span><h2>Исправьте ошибку</h2><p>До публикации нажмите «Отменить правки». После публикации откройте «История и откат» и верните подходящую версию.</p></article>
        </div>
        <div class="panel glossary-panel">
          <h2>Что означают надписи</h2>
          <dl><div><dt>Скрыт</dt><dd>Проект сохранён в панели, но посетители его не видят.</dd></div><div><dt>Неопубликованные изменения</dt><dd>Правки есть только в этой вкладке браузера и ещё не попали на сайт.</dd></div><div><dt>Версия</dt><dd>Номер успешной публикации. Чем он больше, тем состояние новее.</dd></div><div><dt>Описание фото</dt><dd>Короткое описание того, что изображено; оно помогает поиску и людям, использующим экранное чтение.</dd></div></dl>
        </div>
      </section>
    </main>

    <div class="publish-bar" id="publishBar" hidden>
      <div class="publish-copy"><strong>Есть неопубликованные изменения</strong><small id="publishSummary">Проверьте и опубликуйте их.</small></div>
      <div class="publish-actions"><button class="button button-ghost" id="discardChanges" type="button">Отменить правки</button><button class="button button-primary save-all" type="button">Опубликовать</button></div>
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
          <label>ID / адрес карточки<input name="id" pattern="[a-z0-9][a-z0-9\-]{0,63}" maxlength="64" required><small>Латиница, цифры и дефис. После публикации лучше не менять.</small></label>
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

  <dialog class="restore-dialog" id="restoreDialog" aria-labelledby="restoreDialogTitle">
    <div class="restore-dialog-shell">
      <header class="dialog-header">
        <div><div class="eyebrow">Проверка перед откатом</div><h2 id="restoreDialogTitle">Вернуть выбранную версию?</h2></div>
        <button class="icon-button restore-close" type="button" aria-label="Закрыть">×</button>
      </header>
      <div class="dialog-body restore-dialog-body">
        <div class="restore-version-meta" id="restoreVersionMeta"></div>
        <h3>После восстановления</h3>
        <ul class="restore-change-list" id="restoreChangeList"></ul>
        <div class="safety-note"><strong>Это обратимо.</strong> Нынешнее состояние автоматически сохранится как ещё одна версия истории. Восстановление публикуется на сайте сразу.</div>
      </div>
      <footer class="dialog-footer">
        <button class="button button-ghost restore-close" type="button">Ничего не менять</button>
        <button class="button button-primary" id="confirmRestore" type="button">Восстановить и опубликовать</button>
      </footer>
    </div>
  </dialog>

  <div class="toast-region" id="toastRegion" aria-live="polite" aria-atomic="true"></div>
  <script src="assets/admin.js?v=5"></script>
<?php endif; ?>
</body>
</html>
