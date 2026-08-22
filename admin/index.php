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
  <link rel="stylesheet" href="assets/admin.css?v=10">
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
  <script src="assets/admin.js?v=10"></script>
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
        <span class="working-version" id="workingVersion" hidden></span>
        <button class="button button-secondary preview-current" type="button">Предпросмотр правок ↗</button>
        <a class="button button-ghost published-site-link" href="../" target="_blank" rel="noopener">Открыть сайт ↗</a>
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
        <button class="nav-item" type="button" data-section-target="drafts"><span>05</span>Черновики и предпросмотр</button>
        <button class="nav-item" type="button" data-section-target="history"><span>06</span>История и откат</button>
        <button class="nav-item" type="button" data-section-target="security"><span>07</span>Безопасность</button>
        <button class="nav-item" type="button" data-section-target="help"><span>08</span>Как пользоваться</button>
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
            <button class="button button-secondary" type="button" data-go-section="media">Добавить или заменить фото</button>
            <button class="button button-secondary" type="button" data-go-section="contacts">Обновить контакты</button>
            <button class="button button-secondary save-draft" type="button">Сохранить черновик</button>
            <button class="button button-ghost preview-current" type="button">Предпросмотр правок ↗</button>
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
          <div><div class="eyebrow">Основные разделы</div><h1>Фотографии сайта</h1><p>На первом экране, в мастерской и сертификатах можно добавлять новые фотографии, менять их порядок и удалять. Для каждого нового файла панель откроет редактор и сама создаст нужные размеры.</p></div>
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

      <section class="admin-section" id="section-drafts" data-section="drafts" hidden>
        <div class="section-heading">
          <div><div class="eyebrow">Рабочие версии, которые никто не видит</div><h1>Черновики и предпросмотр</h1><p>Черновик хранится на сервере отдельно, но не меняет опубликованный сайт и не попадает в историю публикаций. Предпросмотр показывает текущие правки в настоящем дизайне сайта.</p></div>
          <div class="heading-actions"><button class="button button-secondary save-draft" type="button">Сохранить текущий черновик</button><button class="button button-primary preview-current" type="button">Открыть предпросмотр ↗</button></div>
        </div>
        <div class="draft-workflow panel">
          <div class="draft-workflow-step"><span>1</span><div><strong>Внесите правки</strong><small>Текст и новые фото пока видны только в этой вкладке.</small></div></div>
          <div class="draft-workflow-step"><span>2</span><div><strong>Сохраните под понятным именем</strong><small>Например: «Новый проект Jaeger — на согласование».</small></div></div>
          <div class="draft-workflow-step"><span>3</span><div><strong>Откройте предпросмотр</strong><small>Проверьте компьютер и телефон. Плашка сверху напомнит, что это черновик.</small></div></div>
          <div class="draft-workflow-step"><span>4</span><div><strong>Опубликуйте только после проверки</strong><small>Лишь золотая кнопка «Опубликовать» меняет сайт для посетителей.</small></div></div>
        </div>
        <div class="draft-status" id="draftStatus" role="status">Загружаю список черновиков…</div>
        <div class="drafts-list" id="draftsList"></div>
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
        <div class="section-heading"><div><div class="eyebrow">Полная инструкция</div><h1>Как пользоваться панелью</h1><p>Здесь пошагово описаны все разделы. Откройте нужный пункт и выполняйте действия сверху вниз.</p></div></div>
        <div class="panel golden-rule"><strong>Главное правило</strong><p>Печатание текста, загрузка фотографии, сохранение проекта и сохранение черновика не меняют сайт для посетителей. Сайт меняется только после успешного сообщения «Изменения опубликованы».</p></div>
        <div class="help-guides">
          <details class="panel help-guide" open><summary><span>01</span>Безопасный порядок любой работы</summary><div><ol><li>Откройте нужный раздел и внесите изменения.</li><li>Если работа большая — нажмите «Сохранить черновик» и дайте ему понятное имя.</li><li>Нажмите «Предпросмотр правок». Откроется настоящий сайт с заметной плашкой «Предпросмотр черновика».</li><li>Проверьте главную страницу на компьютере и телефоне, карточку проекта и все фотографии.</li><li>Вернитесь в панель. Если всё верно — нажмите золотую кнопку «Опубликовать».</li><li>Дождитесь сообщения об успешной публикации. Не закрывайте вкладку во время загрузки или публикации.</li></ol></div></details>
          <details class="panel help-guide"><summary><span>02</span>Создание и редактирование проекта</summary><div><ol><li>Откройте «Проекты» → «Новый проект» или «Изменить» у существующего.</li><li>ID вводится латиницей: например <code>jaeger-repeater</code>. У существующего проекта без необходимости его не меняйте.</li><li>«Показывать проект на сайте» можно включить только когда проект полностью готов.</li><li>Заполните название, категорию, короткую подпись для карточки и подробный текст для галереи.</li><li>Характеристики вводите по одной на строке: <code>Год | конец XIX века</code>.</li><li>Добавьте фотографии. Звезда выбирает обложку, стрелки меняют порядок, крестик убирает снимок из проекта.</li><li>Нажмите «Сохранить проект в правках». После этого проект ещё не опубликован.</li></ol><p><strong>Подсказки на карточке конкретны:</strong> «Нет фотографий», «Нет описания обложки» или «Нет описания: N фото». Они не блокируют черновик, но их лучше исправить до публикации.</p></div></details>
          <details class="panel help-guide"><summary><span>03</span>Обрезка и загрузка фотографий</summary><div><ol><li>Выберите JPEG, PNG или WebP до 9 МБ. Для нескольких снимков редактор откроется по очереди.</li><li>В окне кадрирования удерживайте изображение мышью или пальцем и двигайте его внутри рамки.</li><li>Ползунок «Увеличение» приближает изображение. Кнопки поворота разворачивают его на 90°.</li><li>Обязательно откройте обе вкладки: «Компьютер» и «Телефон». Это два разных кадра.</li><li>Для «Направлений» компьютерный кадр очень широкий — убедитесь, что главный предмет полностью попал в узкую рамку.</li><li>Нажмите «Подготовить и загрузить». Панель автоматически создаст несколько размеров WebP и выберет подходящий размер для экрана посетителя.</li><li>Не закрывайте окно, пока постоянная панель загрузки не сообщит «Готово».</li></ol><p>Исходный мастер-файл сохраняется в оптимизированном виде, поэтому снимок можно открыть кнопкой «Изменить кадр» и подготовить заново без поиска оригинала.</p></div></details>
          <details class="panel help-guide"><summary><span>04</span>Фотографии основных разделов</summary><div><p>В разделе «Фотографии сайта» группы подписаны так же, как на странице. На первом экране, в мастерской и сертификатах есть кнопка «Добавить фотографии». У каждого снимка доступны стрелки порядка и кнопка удаления. В направлениях и блоке мастера сохраняется заданное число мест — там фотографии только заменяются.</p><ol><li>Нажмите «Добавить фотографии» или «Заменить и обрезать».</li><li>Настройте каждый предложенный кадр и дождитесь сообщения «Готово».</li><li>Заполните понятное описание и, если она показывается на сайте, подпись.</li><li>Стрелками расставьте фотографии в нужном порядке.</li><li>Откройте предпросмотр и проверьте слайдер, сетку или все сертификаты.</li></ol><ul><li><strong>Первый экран:</strong> максимум 8 фотографий; оставьте свободное место вокруг лица, рук и часов.</li><li><strong>Направления:</strong> особенно тщательно проверьте узкий компьютерный кадр.</li><li><strong>Мастерская:</strong> максимум 16 фотографий; важный предмет держите ближе к центру.</li><li><strong>Сертификаты:</strong> до 100 документов; каждый сохраняется целиком без обрезки, допустим поворот.</li></ul></div></details>
          <details class="panel help-guide"><summary><span>05</span>Черновики и предпросмотр</summary><div><ul><li><strong>Черновик</strong> — отдельная сохранённая рабочая версия всего сайта. Она не видна посетителям и не создаёт запись в истории.</li><li><strong>Открыть черновик</strong> — заменить текущие правки содержимым выбранного черновика. Опубликованный сайт при этом не меняется.</li><li><strong>Предпросмотр</strong> — временная закрытая ссылка внутри вашей авторизованной сессии. Она не предназначена для отправки клиенту.</li><li><strong>Опубликовать</strong> — единственное действие, которое меняет публичный сайт и создаёт страховочную копию.</li></ul><p>Не называйте все версии «Черновик». Указывайте смысл и дату: «Контакты и Jaeger — 22 августа».</p></div></details>
          <details class="panel help-guide"><summary><span>06</span>Контакты и социальные сети</summary><div><ol><li>Телефон для показа можно написать привычно, например <code>+7 900 000-00-00</code>.</li><li>Технический телефон E.164 пишется без пробелов и скобок: <code>+79000000000</code>.</li><li>Все ссылки должны быть полными и начинаться с <code>https://</code>.</li><li>Флажок «Виден» включает ссылку на сайте. Стрелки меняют порядок.</li><li>После изменения проверьте кнопки Telegram и WhatsApp в предпросмотре.</li></ol></div></details>
          <details class="panel help-guide"><summary><span>07</span>Публикация, история и откат</summary><div><p>Перед публикацией панель проверяет обязательные поля. Во время публикации кнопки блокируются; дождитесь результата. Каждая успешная публикация сначала сохраняет прежнее состояние в «Истории и откате».</p><p><strong>Надпись «72 часа» — это срок гарантированного хранения, а не ожидание перед редактированием.</strong> Восстанавливать доступную версию можно сразу. Если в текущей вкладке есть неопубликованные правки, панель отдельно предупредит, что при восстановлении они будут отменены.</p><p>Чтобы исправить уже опубликованную ошибку: откройте историю, выберите время, внимательно прочитайте список отличий и подтвердите восстановление. Восстановление публикуется сразу, но нынешняя версия тоже останется в истории — действие обратимо.</p></div></details>
          <details class="panel help-guide"><summary><span>08</span>Если кажется, что что-то зависло</summary><div><ol><li>Посмотрите постоянную панель операции внизу экрана: там указан текущий шаг и количество файлов.</li><li>При медленном интернете подготовка большой фотографии может занять несколько минут. Пока проценты или шаги меняются — всё работает.</li><li>Не нажимайте кнопку загрузки повторно и не закрывайте вкладку.</li><li>Если появилась красная ошибка, прочитайте её полностью, исправьте причину и повторите только этот файл.</li><li>Если сессия завершилась, войдите снова и откройте последний сохранённый черновик.</li></ol></div></details>
        </div>
        <div class="panel glossary-panel">
          <h2>Что означают надписи</h2>
          <dl><div><dt>Скрыт</dt><dd>Проект хранится в панели, но не выводится на публичной странице.</dd></div><div><dt>Неопубликованные изменения</dt><dd>Правки есть в текущей вкладке, однако посетители видят прежний сайт.</dd></div><div><dt>Сохранённый черновик</dt><dd>Рабочая версия записана на сервер и переживёт закрытие браузера, но не опубликована.</dd></div><div><dt>Предпросмотр</dt><dd>Точный вид текущих правок в дизайне сайта; сверху есть предупреждающая плашка.</dd></div><div><dt>Версия</dt><dd>Номер успешной публикации. Черновики этот номер не увеличивают.</dd></div><div><dt>Описание фото</dt><dd>Коротко и буквально: что изображено. Помогает поиску и людям с экранным чтением.</dd></div></dl>
        </div>
      </section>
    </main>

    <div class="publish-bar" id="publishBar" hidden>
      <div class="publish-copy"><strong>Есть неопубликованные изменения</strong><small id="publishSummary">Проверьте и опубликуйте их.</small></div>
      <div class="publish-actions"><button class="button button-ghost" id="discardChanges" type="button">Отменить правки</button><button class="button button-ghost save-draft" type="button">Сохранить черновик</button><button class="button button-secondary preview-current" type="button">Предпросмотр ↗</button><button class="button button-primary save-all" type="button">Опубликовать</button></div>
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
          <label class="button button-secondary file-button">Добавить и обрезать фотографии<input id="projectPhotoUpload" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden></label>
        </div>
        <div class="upload-progress" id="projectUploadProgress" hidden></div>
        <div class="project-photos-editor" id="projectPhotosEditor"></div>
      </div>
      <footer class="dialog-footer">
        <button class="button button-ghost" value="cancel" formnovalidate>Отмена</button>
        <button class="button button-primary" id="saveProject" value="default">Сохранить проект в правках</button>
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
        <div class="safety-note warning" id="restoreDirtyWarning" hidden><strong>Есть неопубликованные правки.</strong> При восстановлении выбранной версии они будут отменены. Опубликованная сейчас версия останется в истории.</div>
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

  <dialog class="draft-dialog" id="draftDialog" aria-labelledby="draftDialogTitle">
    <form method="dialog" class="draft-dialog-shell" id="draftForm">
      <header class="dialog-header">
        <div><div class="eyebrow">Отдельная рабочая версия</div><h2 id="draftDialogTitle">Сохранить черновик</h2></div>
        <button class="icon-button draft-dialog-close" value="cancel" formnovalidate aria-label="Закрыть">×</button>
      </header>
      <div class="dialog-body">
        <label class="draft-name-label">Понятное название черновика<input id="draftName" name="draftName" maxlength="120" placeholder="Например: Jaeger — версия на согласование" required><small>По названию должно быть понятно, что внутри. Черновик не меняет сайт и не создаёт запись в истории.</small></label>
        <label class="draft-update-choice" id="draftUpdateChoice" hidden><input id="updateCurrentDraft" type="checkbox" checked><span>Обновить открытый черновик, а не создавать ещё одну копию</span></label>
        <div class="safety-note"><strong>Безопасно:</strong> после сохранения посетители продолжат видеть опубликованную версию.</div>
      </div>
      <footer class="dialog-footer">
        <button class="button button-ghost" value="cancel" formnovalidate>Отмена</button>
        <button class="button button-primary" value="save">Сохранить черновик</button>
      </footer>
    </form>
  </dialog>

  <dialog class="crop-dialog" id="cropDialog" aria-labelledby="cropDialogTitle">
    <div class="crop-dialog-shell">
      <header class="dialog-header crop-dialog-header">
        <div><div class="eyebrow">Подготовка фотографии</div><h2 id="cropDialogTitle">Настройте кадр</h2><p id="cropFileName"></p></div>
        <button class="icon-button crop-cancel" type="button" aria-label="Отменить загрузку">×</button>
      </header>
      <div class="crop-dialog-body">
        <div class="crop-explanation" id="cropExplanation"></div>
        <div class="crop-target-tabs" id="cropTargetTabs" role="tablist" aria-label="Варианты кадра"></div>
        <div class="crop-workspace">
          <div class="crop-stage-wrap" id="cropStageWrap">
            <canvas id="cropCanvas" width="1200" height="800" aria-label="Предпросмотр обрезки фотографии"></canvas>
            <div class="crop-grid" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
            <div class="crop-drag-hint" id="cropDragHint">Двигайте фотографию мышью или пальцем</div>
          </div>
          <aside class="crop-controls">
            <div class="crop-current-target"><span>Сейчас настраивается</span><strong id="cropTargetTitle">Компьютер</strong><small id="cropTargetRatio"></small></div>
            <label class="crop-zoom-label">Увеличение <output id="cropZoomOutput">100%</output><input id="cropZoom" type="range" min="100" max="400" step="1" value="100"></label>
            <div class="crop-control-buttons">
              <button class="button button-ghost" id="cropRotateLeft" type="button" aria-label="Повернуть влево на 90 градусов">↶ Повернуть</button>
              <button class="button button-ghost" id="cropRotateRight" type="button" aria-label="Повернуть вправо на 90 градусов">Повернуть ↷</button>
              <button class="button button-ghost" id="cropReset" type="button">Вернуть по центру</button>
            </div>
            <div class="crop-format-note" id="cropFormatNote"></div>
          </aside>
        </div>
      </div>
      <footer class="dialog-footer crop-dialog-footer">
        <button class="button button-ghost crop-cancel" type="button">Отменить этот файл</button>
        <div class="crop-footer-copy"><strong id="cropFooterTitle">Проверьте оба кадра</strong><small id="cropFooterNote">Панель затем сама сделает все нужные размеры.</small></div>
        <button class="button button-primary" id="cropConfirm" type="button">Подготовить и загрузить</button>
      </footer>
    </div>
  </dialog>

  <section class="operation-panel" id="operationPanel" role="status" aria-live="polite" hidden>
    <div class="operation-copy"><span class="operation-state" id="operationState">Подготовка</span><strong id="operationTitle">Обрабатываю фотографию…</strong><small id="operationDetail">Не закрывайте эту вкладку.</small></div>
    <progress id="operationProgress" max="100" value="0"></progress>
    <button class="icon-button operation-close" id="operationClose" type="button" aria-label="Закрыть сообщение" hidden>×</button>
  </section>

  <div class="toast-region" id="toastRegion" aria-live="polite" aria-atomic="true"></div>
  <script src="assets/admin.js?v=10"></script>
<?php endif; ?>
</body>
</html>
