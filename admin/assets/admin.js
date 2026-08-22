(function () {
  "use strict";

  var passwordToggle = document.querySelector(".password-toggle");
  if (passwordToggle) {
    passwordToggle.addEventListener("click", function () {
      var password = document.getElementById("password");
      if (!password) return;
      var show = password.type === "password";
      password.type = show ? "text" : "password";
      passwordToggle.textContent = show ? "Скрыть" : "Показать";
      passwordToggle.setAttribute("aria-label", show ? "Скрыть пароль" : "Показать пароль");
      passwordToggle.setAttribute("aria-pressed", String(show));
      password.focus();
    });
  }

  var app = document.getElementById("adminApp");
  if (!app) return;

  var state = {
    content: null,
    baselineContent: null,
    capabilities: null,
    history: [],
    historyLoaded: false,
    historyLoading: false,
    historyError: "",
    selectedHistory: null,
    drafts: [],
    draftsLoaded: false,
    draftsLoading: false,
    draftsError: "",
    activeDraftId: "",
    activeDraftName: "",
    csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || "",
    dirty: false,
    saving: false,
    currentDraft: null,
    currentDraftIndex: -1,
    draggedProjectId: "",
    changeVersion: 0,
    projectQuery: "",
    projectFilter: "all",
    cropSession: null,
    operationActive: false
  };

  var mediaGroupSettings = {
    hero: {
      title: "Первый экран",
      dynamic: true,
      fallbackLimit: 8,
      addLabel: "+ Добавить фотографии",
      note: "Слайдер на первом экране. Можно добавлять, удалять и менять порядок."
    },
    directions: {
      title: "Направления работы",
      dynamic: false,
      fallbackLimit: 8,
      note: "Количество позиций связано с готовыми направлениями — здесь фотографии только заменяются."
    },
    master: {
      title: "О мастере",
      dynamic: false,
      fallbackLimit: 6,
      note: "Главное и дополнительное фото занимают заданные места — здесь фотографии только заменяются."
    },
    workshop: {
      title: "Мастерская",
      dynamic: true,
      fallbackLimit: 16,
      addLabel: "+ Добавить фотографии",
      note: "Галерея мастерской. Новые снимки автоматически продолжают сетку сайта."
    },
    certificates: {
      title: "Сертификаты",
      dynamic: true,
      fallbackLimit: 100,
      addLabel: "+ Добавить сертификаты",
      note: "Документы выводятся в карусели целиком и в том же порядке, что здесь."
    }
  };

  var groupTitles = {};
  Object.keys(mediaGroupSettings).forEach(function (key) {
    groupTitles[key] = mediaGroupSettings[key].title;
  });

  var cropProfiles = {
    project: {
      id: "project",
      explanation: "Галерея сохранится целиком. Здесь настраиваются только обложки: отдельная для компьютера и отдельная для телефона.",
      defaultSizes: [560, 960, 1600, 2400],
      targets: [
        { key: "card", label: "Компьютер", ratio: 3 / 2, ratioLabel: "3:2 — карточка проекта", sizes: [480, 960, 1440], note: "Главный предмет должен хорошо читаться в горизонтальной карточке." },
        { key: "mobile", label: "Телефон", ratio: 4 / 3, ratioLabel: "4:3 — карточка на телефоне", sizes: [480, 800, 1080], note: "На телефоне кадр выше. Проверьте края корпуса и ремешка." }
      ]
    },
    hero: {
      id: "hero",
      explanation: "Первый экран занимает большую площадь. Оставьте немного воздуха вокруг рук, лица и часов.",
      targets: [
        { key: "default", label: "Компьютер", ratio: 4 / 3, ratioLabel: "4:3 — большой экран", sizes: [640, 960, 1440, 1920], note: "Текст сайта накладывается поверх кадра — важный объект держите ближе к центру." },
        { key: "mobile", label: "Телефон", ratio: 4 / 5, ratioLabel: "4:5 — вертикальный экран", sizes: [480, 800, 1080], note: "Вертикальный кадр должен выглядеть законченным сам по себе." }
      ]
    },
    directions: {
      id: "directions",
      explanation: "Это самый требовательный блок: на компьютере фотография очень узкая. Настройте предмет точно внутри широкой полосы, затем отдельно проверьте телефон.",
      targets: [
        { key: "default", label: "Компьютер — узкий кадр", ratio: 4 / 1, ratioLabel: "4:1 — широкая полоса", sizes: [640, 960, 1440], note: "Особенно важно: часы, инструмент или ремень не должны обрезаться случайно сверху и снизу." },
        { key: "mobile", label: "Телефон", ratio: 16 / 9, ratioLabel: "16:9 — карточка на телефоне", sizes: [480, 800, 1080], note: "На телефоне помещается больше высоты — настройте композицию заново." }
      ]
    },
    "master-main": {
      id: "master-main",
      explanation: "Главный портрет мастера. Не прижимайте лицо и руки к краям.",
      targets: [
        { key: "default", label: "Компьютер", ratio: 4 / 3, ratioLabel: "4:3 — блок о мастере", sizes: [640, 960, 1440, 1920], note: "Оставьте безопасные поля вокруг лица, рук и часов." },
        { key: "mobile", label: "Телефон", ratio: 4 / 5, ratioLabel: "4:5 — вертикальный кадр", sizes: [480, 800, 1080], note: "Проверьте, что лицо и предмет работы остаются в кадре." }
      ]
    },
    "master-inset": {
      id: "master-inset",
      explanation: "Дополнительная фотография в блоке мастера.",
      targets: [
        { key: "default", label: "Компьютер", ratio: 4 / 3, ratioLabel: "4:3 — дополнительный кадр", sizes: [480, 800, 1200], note: "Главная деталь должна находиться ближе к центру." },
        { key: "mobile", label: "Телефон", ratio: 4 / 3, ratioLabel: "4:3 — телефон", sizes: [480, 800, 1080], note: "Проверьте края предмета на узком экране." }
      ]
    },
    workshop: {
      id: "workshop",
      explanation: "Сетка мастерской меняет форму ячеек. Сделайте широкий компьютерный кадр и более высокий телефонный.",
      targets: [
        { key: "default", label: "Компьютер", ratio: 16 / 9, ratioLabel: "16:9 — сетка мастерской", sizes: [640, 960, 1440], note: "Важный объект лучше держать в центральной части." },
        { key: "mobile", label: "Телефон", ratio: 4 / 3, ratioLabel: "4:3 — мобильная сетка", sizes: [480, 800, 1080], note: "Проверьте, что подпись не закрывает главный объект снизу." }
      ]
    },
    certificates: {
      id: "certificates",
      explanation: "Сертификат сохраняется целиком — без обрезки. Здесь можно только проверить ориентацию и при необходимости повернуть документ.",
      defaultSizes: [480, 960, 1600, 2400],
      targets: [
        { key: "default", label: "Документ целиком", ratio: null, ratioLabel: "Без обрезки", sizes: [480, 960, 1600, 2400], mode: "contain", note: "Все края, подписи и печати должны оставаться видны." }
      ]
    }
  };

  var dom = {
    loading: document.getElementById("loadingPanel"),
    projects: document.getElementById("projectsList"),
    media: document.getElementById("mediaGroups"),
    contacts: document.getElementById("contactsForm"),
    socials: document.getElementById("socialsList"),
    passwordForm: document.getElementById("passwordForm"),
    publishBar: document.getElementById("publishBar"),
    saveIndicator: document.getElementById("saveIndicator"),
    toastRegion: document.getElementById("toastRegion"),
    sidebar: document.getElementById("adminSidebar"),
    sidebarToggle: document.querySelector(".sidebar-toggle"),
    sidebarBackdrop: document.getElementById("sidebarBackdrop"),
    projectDialog: document.getElementById("projectDialog"),
    projectForm: document.getElementById("projectForm"),
    projectDialogTitle: document.getElementById("projectDialogTitle"),
    projectPhotos: document.getElementById("projectPhotosEditor"),
    projectPhotoUpload: document.getElementById("projectPhotoUpload"),
    projectUploadProgress: document.getElementById("projectUploadProgress"),
    projectSearch: document.getElementById("projectSearch"),
    projectFilter: document.getElementById("projectFilter"),
    projectsFound: document.getElementById("projectsFound"),
    projectFilterNote: document.getElementById("projectFilterNote"),
    readinessScore: document.getElementById("readinessScore"),
    readinessChecklist: document.getElementById("readinessChecklist"),
    publishSummary: document.getElementById("publishSummary"),
    discardChanges: document.getElementById("discardChanges"),
    historyList: document.getElementById("historyList"),
    historyStatus: document.getElementById("historyStatus"),
    refreshHistory: document.getElementById("refreshHistory"),
    restoreDialog: document.getElementById("restoreDialog"),
    restoreVersionMeta: document.getElementById("restoreVersionMeta"),
    restoreChangeList: document.getElementById("restoreChangeList"),
    confirmRestore: document.getElementById("confirmRestore")
    ,restoreDirtyWarning: document.getElementById("restoreDirtyWarning")
    ,draftsList: document.getElementById("draftsList")
    ,draftStatus: document.getElementById("draftStatus")
    ,draftDialog: document.getElementById("draftDialog")
    ,draftForm: document.getElementById("draftForm")
    ,draftName: document.getElementById("draftName")
    ,draftUpdateChoice: document.getElementById("draftUpdateChoice")
    ,updateCurrentDraft: document.getElementById("updateCurrentDraft")
    ,workingVersion: document.getElementById("workingVersion")
    ,cropDialog: document.getElementById("cropDialog")
    ,cropCanvas: document.getElementById("cropCanvas")
    ,cropStageWrap: document.getElementById("cropStageWrap")
    ,cropTargetTabs: document.getElementById("cropTargetTabs")
    ,cropExplanation: document.getElementById("cropExplanation")
    ,cropFooterTitle: document.getElementById("cropFooterTitle")
    ,cropFooterNote: document.getElementById("cropFooterNote")
    ,cropFileName: document.getElementById("cropFileName")
    ,cropTargetTitle: document.getElementById("cropTargetTitle")
    ,cropTargetRatio: document.getElementById("cropTargetRatio")
    ,cropZoom: document.getElementById("cropZoom")
    ,cropZoomOutput: document.getElementById("cropZoomOutput")
    ,cropFormatNote: document.getElementById("cropFormatNote")
    ,cropConfirm: document.getElementById("cropConfirm")
    ,operationPanel: document.getElementById("operationPanel")
    ,operationState: document.getElementById("operationState")
    ,operationTitle: document.getElementById("operationTitle")
    ,operationDetail: document.getElementById("operationDetail")
    ,operationProgress: document.getElementById("operationProgress")
    ,operationClose: document.getElementById("operationClose")
  };

  function createElement(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function createButton(text, className, action, label) {
    var button = createElement("button", className, text);
    button.type = "button";
    if (action) button.dataset.action = action;
    if (label) button.setAttribute("aria-label", label);
    return button;
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function rootImageUrl(path) {
    var clean = String(path || "").replace(/^\/+/, "");
    return clean ? "../" + clean : "";
  }

  function formatDate(value) {
    if (!value) return "ещё не публиковалось";
    var date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString("ru-RU", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit"
    });
  }

  function formatBytes(bytes) {
    var value = Number(bytes) || 0;
    if (value < 1024 * 1024) return Math.round(value / 1024) + " КБ";
    return (value / (1024 * 1024)).toFixed(1).replace(".", ",") + " МБ";
  }

  function sameData(left, right) {
    return JSON.stringify(left === undefined ? null : left) === JSON.stringify(right === undefined ? null : right);
  }

  function changedSectionLabels() {
    if (!state.content || !state.baselineContent) return [];
    var labels = [];
    if (!sameData(state.content.projects, state.baselineContent.projects)) labels.push("проекты");
    if (!sameData(state.content.site.media, state.baselineContent.site.media)) labels.push("фотографии сайта");
    if (!sameData(state.content.site.contacts, state.baselineContent.site.contacts)) labels.push("контакты и соцсети");
    return labels;
  }

  function updatePublishSummary() {
    if (!dom.publishSummary) return;
    var labels = changedSectionLabels();
    dom.publishSummary.textContent = labels.length
      ? "Изменены: " + labels.join(", ") + "."
      : "Проверьте и опубликуйте правки.";
  }

  function projectAttentionLabels(project) {
    var photos = Array.isArray(project.photos) ? project.photos : [];
    if (!photos.length) return ["Нет фотографий"];
    var labels = [];
    if (!String(project.coverAlt || "").trim()) labels.push("Нет описания обложки");
    var missing = photos.filter(function (photo) { return !String(photo.alt || "").trim(); }).length;
    if (missing) labels.push("Нет описания: " + missing + " фото");
    return labels;
  }

  function projectNeedsAttention(project) {
    return projectAttentionLabels(project).length > 0;
  }

  function renderReadiness() {
    if (!dom.readinessChecklist || !state.content) return;
    dom.readinessChecklist.replaceChildren();
    var projects = state.content.projects || [];
    var visibleWithoutPhotos = projects.filter(function (project) {
      return project.visible && (!Array.isArray(project.photos) || !project.photos.length);
    }).length;
    var missingProjectAlt = projects.reduce(function (total, project) {
      var photoMissing = (project.photos || []).filter(function (photo) { return !String(photo.alt || "").trim(); }).length;
      return total + photoMissing + (!String(project.coverAlt || "").trim() && project.cover ? 1 : 0);
    }, 0);
    var missingMediaAlt = Object.keys(state.content.site.media || {}).reduce(function (total, group) {
      return total + (state.content.site.media[group] || []).filter(function (item) { return !String(item.alt || "").trim(); }).length;
    }, 0);
    var contactsValid = dom.contacts ? dom.contacts.checkValidity() : true;
    var checks = [
      {
        ok: visibleWithoutPhotos === 0,
        label: visibleWithoutPhotos ? "У видимых проектов нет фотографий: " + visibleWithoutPhotos : "У всех видимых проектов есть фотографии",
        note: visibleWithoutPhotos ? "Такие проекты нельзя публиковать — добавьте хотя бы один снимок." : "Карточки не останутся без обложки."
      },
      {
        ok: missingProjectAlt + missingMediaAlt === 0,
        label: missingProjectAlt + missingMediaAlt ? "Не заполнены описания фотографий: " + (missingProjectAlt + missingMediaAlt) : "Описания фотографий заполнены",
        note: missingProjectAlt + missingMediaAlt ? "Сайт будет работать, но описания полезны для доступности и поиска." : "Фотографии понятны поиску и экранному чтению.",
        optional: true
      },
      {
        ok: contactsValid,
        label: contactsValid ? "Контакты имеют правильный формат" : "В контактах есть незаполненное или неверное поле",
        note: contactsValid ? "Телефон и ссылки прошли проверку браузера." : "Откройте раздел контактов и исправьте подсвеченные поля."
      }
    ];
    checks.forEach(function (check) {
      var row = createElement("div", "readiness-item " + (check.ok ? "is-ready" : (check.optional ? "is-advice" : "is-warning")));
      row.appendChild(createElement("span", "readiness-mark", check.ok ? "✓" : (check.optional ? "i" : "!")));
      var copy = createElement("div");
      copy.appendChild(createElement("strong", "", check.label));
      copy.appendChild(createElement("small", "", check.note));
      row.appendChild(copy);
      dom.readinessChecklist.appendChild(row);
    });
    var blocking = checks.filter(function (check) { return !check.ok && !check.optional; }).length;
    var advice = checks.filter(function (check) { return !check.ok && check.optional; }).length;
    dom.readinessScore.textContent = blocking ? "Исправить" : (advice ? "Можно улучшить" : "Готово");
    dom.readinessScore.dataset.state = blocking ? "warning" : (advice ? "advice" : "ready");
  }

  function toast(message, kind) {
    if (!dom.toastRegion) return;
    var item = createElement("div", "toast " + (kind || ""), message);
    dom.toastRegion.appendChild(item);
    window.setTimeout(function () {
      item.remove();
    }, kind === "error" ? 6500 : 3600);
  }

  function showFatal(message) {
    if (dom.loading) dom.loading.hidden = true;
    var main = document.querySelector(".admin-main");
    if (!main) return;
    var panel = createElement("section", "fatal-error");
    panel.appendChild(createElement("div", "eyebrow", "Панель недоступна"));
    panel.appendChild(createElement("h1", "", "Не удалось загрузить данные"));
    panel.appendChild(createElement("p", "", message));
    var reload = createButton("Обновить страницу", "button button-primary");
    reload.addEventListener("click", function () { window.location.reload(); });
    panel.appendChild(reload);
    main.appendChild(panel);
  }

  async function parseResponse(response) {
    var data;
    try {
      data = await response.json();
    } catch (_error) {
      throw new Error("Сервер вернул неожиданный ответ.");
    }
    if (!response.ok || !data.ok) {
      var error = new Error(data.error || "Не удалось выполнить запрос.");
      error.status = response.status;
      error.conflict = Boolean(data.conflict);
      throw error;
    }
    return data;
  }

  async function apiGet(action, params) {
    var query = new URLSearchParams({ action: action || "content" });
    Object.keys(params || {}).forEach(function (key) { query.set(key, String(params[key])); });
    var response = await fetch("api.php?" + query.toString(), {
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" }
    });
    if (response.status === 401) {
      window.location.reload();
      throw new Error("Сессия завершена.");
    }
    return parseResponse(response);
  }

  function showOperation(stateLabel, title, detail, progress, kind) {
    if (!dom.operationPanel) return;
    state.operationActive = kind !== "success" && kind !== "error";
    document.body.classList.toggle("operation-active", state.operationActive);
    dom.operationPanel.hidden = false;
    dom.operationPanel.dataset.kind = kind || "working";
    dom.operationState.textContent = stateLabel || "Выполняется";
    dom.operationTitle.textContent = title || "Пожалуйста, подождите…";
    dom.operationDetail.textContent = detail || "Не закрывайте эту вкладку.";
    dom.operationProgress.value = Math.max(0, Math.min(100, Number(progress) || 0));
    dom.operationClose.hidden = kind !== "success" && kind !== "error";
  }

  function finishOperation(title, detail) {
    showOperation("Готово", title, detail || "Можно продолжать работу.", 100, "success");
  }

  function failOperation(message) {
    showOperation("Ошибка", "Операция не завершена", message, 100, "error");
  }

  function closeOperation() {
    if (!dom.operationPanel) return;
    dom.operationPanel.hidden = true;
    document.body.classList.remove("operation-active");
  }

  function apiUploadSet(form, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", "api.php?action=upload-set");
      xhr.responseType = "json";
      xhr.withCredentials = true;
      xhr.setRequestHeader("Accept", "application/json");
      xhr.setRequestHeader("X-CSRF-Token", state.csrf);
      xhr.upload.addEventListener("progress", function (event) {
        if (event.lengthComputable && typeof onProgress === "function") onProgress(event.loaded / event.total);
      });
      xhr.addEventListener("load", function () {
        var data = xhr.response;
        if (xhr.status === 401) {
          window.setTimeout(function () { window.location.reload(); }, 800);
        }
        if (xhr.status < 200 || xhr.status >= 300 || !data || !data.ok) {
          var error = new Error(data && data.error ? data.error : "Сервер не принял подготовленную фотографию.");
          error.status = xhr.status;
          reject(error);
          return;
        }
        resolve(data);
      });
      xhr.addEventListener("error", function () { reject(new Error("Соединение прервалось во время загрузки. Проверьте интернет и повторите файл.")); });
      xhr.addEventListener("abort", function () { reject(new Error("Загрузка была отменена.")); });
      xhr.send(form);
    });
  }

  async function apiPost(action, body, isFormData) {
    var headers = {
      Accept: "application/json",
      "X-CSRF-Token": state.csrf
    };
    if (!isFormData) headers["Content-Type"] = "application/json";
    var response = await fetch("api.php?action=" + encodeURIComponent(action), {
      method: "POST",
      credentials: "same-origin",
      headers: headers,
      body: isFormData ? body : JSON.stringify(body)
    });
    if (response.status === 401) {
      toast("Сессия завершена. Войдите снова.", "error");
      window.setTimeout(function () { window.location.reload(); }, 800);
    }
    return parseResponse(response);
  }

  function setSaveState(mode, text) {
    if (!dom.saveIndicator) return;
    dom.saveIndicator.dataset.state = mode;
    dom.saveIndicator.textContent = text;
  }

  function updateSaveButtons() {
    document.querySelectorAll(".save-all").forEach(function (button) {
      button.disabled = state.saving || !state.dirty;
    });
  }

  function markDirty() {
    if (!state.content) return;
    state.changeVersion += 1;
    state.dirty = true;
    if (dom.publishBar) dom.publishBar.hidden = false;
    setSaveState("dirty", "Есть неопубликованные изменения");
    updateSaveButtons();
    updatePublishSummary();
    renderReadiness();
    if (state.historyLoaded) renderHistory();
  }

  function markSaved(serverContent) {
    state.baselineContent = clone(serverContent || state.content);
    state.dirty = false;
    if (dom.publishBar) dom.publishBar.hidden = true;
    setSaveState("saved", "Все изменения сохранены");
    updateSaveButtons();
    updatePublishSummary();
    renderReadiness();
    if (state.historyLoaded) renderHistory();
  }

  function updateOrders() {
    if (!state.content) return;
    state.content.projects.forEach(function (project, index) {
      project.order = index + 1;
    });
  }

  function updateMetrics() {
    if (!state.content) return;
    var projects = state.content.projects || [];
    var hidden = projects.filter(function (project) { return !project.visible; }).length;
    var photoCount = projects.reduce(function (total, project) {
      return total + (Array.isArray(project.photos) ? project.photos.length : 0);
    }, 0);
    var mediaCount = Object.keys(state.content.site.media || {}).reduce(function (total, group) {
      return total + (Array.isArray(state.content.site.media[group]) ? state.content.site.media[group].length : 0);
    }, 0);
    document.getElementById("metricProjects").textContent = String(projects.length);
    document.getElementById("metricHidden").textContent = hidden ? "скрыто: " + hidden : "все проекты видимы";
    document.getElementById("metricPhotos").textContent = String(photoCount);
    document.getElementById("metricMedia").textContent = String(mediaCount);
    document.getElementById("metricRevision").textContent = "№ " + String(state.content.revision || 0);
    document.getElementById("metricUpdated").textContent = formatDate(state.content.updatedAt);
  }

  function projectDisplayTitle(project) {
    return [project.title, project.accent].filter(Boolean).join(" ");
  }

  function renderProjects() {
    if (!dom.projects || !state.content) return;
    dom.projects.replaceChildren();
    updateOrders();

    if (!state.content.projects.length) {
      if (dom.projectsFound) dom.projectsFound.textContent = "0 проектов";
      dom.projects.appendChild(createElement("div", "empty-state", "Проектов пока нет. Нажмите «Новый проект», чтобы создать первый."));
      return;
    }

    var query = state.projectQuery.trim().toLocaleLowerCase("ru-RU");
    var filter = state.projectFilter || "all";
    var filterActive = Boolean(query) || filter !== "all";
    var matching = state.content.projects.map(function (project, index) {
      return { project: project, index: index };
    }).filter(function (entry) {
      var project = entry.project;
      var haystack = [project.title, project.accent, project.type, project.modalType, project.cardLead, project.id].join(" ").toLocaleLowerCase("ru-RU");
      if (query && haystack.indexOf(query) < 0) return false;
      if (filter === "visible" && !project.visible) return false;
      if (filter === "hidden" && project.visible) return false;
      if (filter === "attention" && !projectNeedsAttention(project)) return false;
      return true;
    });

    if (dom.projectsFound) dom.projectsFound.textContent = "Найдено: " + matching.length + " из " + state.content.projects.length;
    if (dom.projectFilterNote) dom.projectFilterNote.hidden = !filterActive;
    if (!matching.length) {
      dom.projects.appendChild(createElement("div", "empty-state", "По этому запросу проектов не найдено. Очистите поиск или выберите другой фильтр."));
      return;
    }

    matching.forEach(function (entry) {
      var project = entry.project;
      var index = entry.index;
      var card = createElement("article", "project-admin-card" + (project.visible ? "" : " is-hidden"));
      card.dataset.projectId = project.id;
      card.draggable = !filterActive;

      var handle = createElement("div", "drag-handle", "⠿");
      handle.title = "Перетащить";
      handle.setAttribute("aria-hidden", "true");
      card.appendChild(handle);

      var image = createElement("img", "project-thumb");
      image.alt = "";
      image.loading = "lazy";
      if (project.cover) image.src = rootImageUrl(project.cover);
      card.appendChild(image);

      var copy = createElement("div", "project-card-copy");
      copy.appendChild(createElement("h3", "", projectDisplayTitle(project) || "Без названия"));
      copy.appendChild(createElement("p", "", project.type + " · " + (project.photos || []).length + " фото · позиция " + (index + 1)));
      copy.appendChild(createElement("span", "status-badge" + (project.visible ? "" : " hidden"), project.visible ? "На сайте" : "Скрыт"));
      projectAttentionLabels(project).forEach(function (label) {
        copy.appendChild(createElement("span", "status-badge attention", label));
      });
      card.appendChild(copy);

      var actions = createElement("div", "project-card-actions");
      var up = createButton("↑", "mini-button", "project-up", "Поднять проект выше");
      up.disabled = filterActive || index === 0;
      var down = createButton("↓", "mini-button", "project-down", "Опустить проект ниже");
      down.disabled = filterActive || index === state.content.projects.length - 1;
      var visibility = createButton(project.visible ? "Скрыть" : "Показать", "button button-ghost", "project-visibility");
      var edit = createButton("Изменить", "button button-secondary", "project-edit");
      var duplicate = createButton("Копия", "button button-ghost", "project-duplicate", "Создать скрытую копию");
      var remove = createButton("Удалить", "button button-danger", "project-delete");
      [up, down, visibility, edit, duplicate, remove].forEach(function (button) { actions.appendChild(button); });
      card.appendChild(actions);
      dom.projects.appendChild(card);
    });
  }

  function moveProject(fromIndex, toIndex) {
    if (!state.content || fromIndex === toIndex || fromIndex < 0 || toIndex < 0) return;
    var moved = state.content.projects.splice(fromIndex, 1)[0];
    state.content.projects.splice(toIndex, 0, moved);
    updateOrders();
    renderProjects();
    updateMetrics();
    markDirty();
  }

  function uniqueProjectId(source) {
    var base = String(source || "project").toLowerCase().replace(/[^a-z0-9-]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 52) || "project";
    var ids = new Set(state.content.projects.map(function (project) { return project.id; }));
    var candidate = base;
    var number = 2;
    while (ids.has(candidate)) {
      candidate = (base.slice(0, 58) + "-" + number).slice(0, 64);
      number += 1;
    }
    return candidate;
  }

  function emptyProject() {
    return {
      id: uniqueProjectId("new-project"),
      slug: "",
      visible: false,
      order: state.content.projects.length + 1,
      type: "Реставрация",
      modalType: "Реставрация",
      title: "Новый проект",
      accent: "",
      cardLead: "Краткое описание проекта",
      lead: "Краткое описание проекта",
      body: [],
      specs: [],
      cover: "",
      coverAlt: "",
      photos: []
    };
  }

  function formField(name) {
    return dom.projectForm.elements.namedItem(name);
  }

  function openProjectEditor(project, index) {
    state.currentDraft = clone(project || emptyProject());
    state.currentDraftIndex = Number.isInteger(index) ? index : -1;
    var draft = state.currentDraft;
    dom.projectDialogTitle.textContent = index >= 0 ? "Редактирование проекта" : "Новый проект";
    formField("originalId").value = index >= 0 ? project.id : "";
    formField("id").value = draft.id || "";
    formField("visible").checked = Boolean(draft.visible);
    formField("title").value = draft.title || "";
    formField("accent").value = draft.accent || "";
    formField("type").value = draft.type || "";
    formField("modalType").value = draft.modalType || draft.type || "";
    formField("cardLead").value = draft.cardLead || "";
    formField("lead").value = draft.lead || "";
    formField("body").value = (draft.body || []).join("\n\n");
    formField("specs").value = (draft.specs || []).map(function (spec) {
      return [spec.label || "", spec.value || ""].join(" | ");
    }).join("\n");
    formField("coverAlt").value = draft.coverAlt || "";
    renderProjectPhotos();
    if (typeof dom.projectDialog.showModal === "function") dom.projectDialog.showModal();
    else dom.projectDialog.setAttribute("open", "");
    window.setTimeout(function () { formField("title").focus(); }, 30);
  }

  function renderProjectPhotos() {
    if (!dom.projectPhotos || !state.currentDraft) return;
    dom.projectPhotos.replaceChildren();
    var photos = state.currentDraft.photos || [];
    if (!photos.length) {
      dom.projectPhotos.appendChild(createElement("div", "empty-state", "Фотографий пока нет. Загрузите снимки JPEG, PNG или WebP."));
      return;
    }

    photos.forEach(function (photo, index) {
      var card = createElement("article", "photo-editor-card" + (photo.src === state.currentDraft.cover ? " is-cover" : ""));
      card.dataset.photoIndex = String(index);
      var image = createElement("img");
      image.src = rootImageUrl(photo.thumb || photo.src);
      image.alt = photo.alt || "Фотография проекта";
      image.loading = "lazy";
      card.appendChild(image);
      if (photo.src === state.currentDraft.cover) card.appendChild(createElement("span", "photo-cover-badge", "Обложка"));
      if (Array.isArray(photo.variants) && photo.variants.length > 1) {
        card.appendChild(createElement("span", "photo-adaptive-badge", photo.mobile ? "Компьютер + телефон" : "Несколько размеров"));
      }

      var altLabel = createElement("label", "photo-alt-label", "Описание фото");
      var altInput = document.createElement("input");
      altInput.type = "text";
      altInput.maxLength = 300;
      altInput.value = photo.alt || "";
      altInput.dataset.photoField = "alt";
      altLabel.appendChild(altInput);
      card.appendChild(altLabel);

      var actions = createElement("div", "photo-actions");
      var cover = createButton("★", "", "photo-cover", "Сделать обложкой");
      cover.disabled = photo.src === state.currentDraft.cover;
      var left = createButton("←", "", "photo-left", "Переместить влево");
      left.disabled = index === 0;
      var right = createButton("→", "", "photo-right", "Переместить вправо");
      right.disabled = index === photos.length - 1;
      var remove = createButton("×", "", "photo-delete", "Убрать фотографию из проекта");
      var recrop = createButton("Изменить кадр обложки", "photo-recrop", "photo-recrop", "Заново настроить кадр для компьютера и телефона");
      [cover, left, right, remove, recrop].forEach(function (button) { actions.appendChild(button); });
      card.appendChild(actions);
      dom.projectPhotos.appendChild(card);
    });
  }

  function splitParagraphs(value) {
    return String(value || "").split(/\n\s*\n+/).map(function (item) { return item.trim(); }).filter(Boolean);
  }

  function parseSpecs(value) {
    return String(value || "").split(/\r?\n/).map(function (line) {
      var clean = line.trim();
      if (!clean) return null;
      var separator = clean.indexOf("|");
      if (separator < 0) return { label: "", value: clean };
      return {
        label: clean.slice(0, separator).trim(),
        value: clean.slice(separator + 1).trim()
      };
    }).filter(Boolean);
  }

  function saveProjectDraft() {
    if (!state.currentDraft || !dom.projectForm.reportValidity()) return;
    var id = String(formField("id").value || "").trim().toLowerCase();
    var duplicate = state.content.projects.some(function (project, index) {
      return index !== state.currentDraftIndex && project.id === id;
    });
    if (duplicate) {
      toast("Проект с таким ID уже существует.", "error");
      formField("id").focus();
      return;
    }

    var photos = state.currentDraft.photos || [];
    var visible = Boolean(formField("visible").checked);
    if (visible && photos.length === 0) {
      toast("Для показа проекта загрузите хотя бы одну фотографию.", "error");
      return;
    }
    var body = splitParagraphs(formField("body").value);
    var specs = parseSpecs(formField("specs").value);
    if (body.length > 15 || specs.length > 15) {
      toast("Можно сохранить не более 15 абзацев и 15 характеристик.", "error");
      return;
    }

    var cover = state.currentDraft.cover;
    if (!photos.some(function (photo) { return photo.src === cover; })) cover = photos[0] ? photos[0].src : "";
    var project = {
      id: id,
      slug: id,
      visible: visible,
      order: state.currentDraftIndex >= 0 ? state.currentDraftIndex + 1 : state.content.projects.length + 1,
      type: String(formField("type").value || "").trim(),
      modalType: String(formField("modalType").value || "").trim(),
      title: String(formField("title").value || "").trim(),
      accent: String(formField("accent").value || "").trim(),
      cardLead: String(formField("cardLead").value || "").trim(),
      lead: String(formField("lead").value || "").trim(),
      body: body,
      specs: specs,
      cover: cover,
      coverAlt: String(formField("coverAlt").value || "").trim(),
      photos: photos
    };

    if (state.currentDraftIndex >= 0) state.content.projects[state.currentDraftIndex] = project;
    else state.content.projects.push(project);
    updateOrders();
    state.currentDraft = null;
    state.currentDraftIndex = -1;
    dom.projectDialog.close("saved");
    renderProjects();
    updateMetrics();
    markDirty();
    toast("Проект сохранён в неопубликованных правках. Проверьте его в предпросмотре, затем при готовности нажмите «Опубликовать».", "success");
  }

  async function uploadImage(file) {
    if (!file || !/^image\/(jpeg|png|webp)$/i.test(file.type || "")) {
      throw new Error("Разрешены только JPEG, PNG и WebP.");
    }
    var max = state.capabilities ? Number(state.capabilities.maxUploadBytes) : 15 * 1024 * 1024;
    if (file.size > max) throw new Error("Файл «" + file.name + "» больше " + formatBytes(max) + ".");
    var form = new FormData();
    form.append("image", file, file.name);
    var result = await apiPost("upload", form, true);
    return result.photo;
  }

  function profileForMedia(group, index) {
    if (group === "master") return cropProfiles[index === 0 ? "master-main" : "master-inset"];
    return cropProfiles[group] || cropProfiles.workshop;
  }

  function cropStateFrom(value) {
    var source = value && typeof value === "object" ? value : {};
    var rotation = Number(source.rotation) || 0;
    rotation = ((Math.round(rotation / 90) * 90) % 360 + 360) % 360;
    return {
      zoom: Math.max(1, Math.min(4, Number(source.zoom) || 1)),
      offsetX: Math.max(-4, Math.min(4, Number(source.offsetX) || 0)),
      offsetY: Math.max(-4, Math.min(4, Number(source.offsetY) || 0)),
      rotation: rotation,
      visited: false
    };
  }

  async function loadCropSource(input) {
    var blob;
    var name;
    if (input instanceof File || input instanceof Blob) {
      blob = input;
      name = input.name || "Фотография";
      var max = state.capabilities ? Number(state.capabilities.maxUploadBytes) : 9 * 1024 * 1024;
      if (blob.size > max) throw new Error("Файл «" + name + "» больше " + formatBytes(max) + ". Уменьшите его и повторите.");
    } else if (input && input.url) {
      name = input.name || "Сохранённая фотография";
      var response = await fetch(input.url, { credentials: "same-origin", cache: "no-store" });
      if (!response.ok) throw new Error("Не удалось открыть мастер-файл для повторной обрезки.");
      blob = await response.blob();
    } else {
      throw new Error("Фотография не выбрана.");
    }
    if (!/^image\/(jpeg|png|webp)$/i.test(blob.type || "")) {
      throw new Error("Разрешены только JPEG, PNG и WebP.");
    }

    var source;
    if (typeof createImageBitmap === "function") {
      try {
        source = await createImageBitmap(blob, { imageOrientation: "from-image" });
      } catch (_error) {
        source = null;
      }
    }
    if (!source) {
      source = await new Promise(function (resolve, reject) {
        var url = URL.createObjectURL(blob);
        var image = new Image();
        image.decoding = "async";
        image.onload = function () { URL.revokeObjectURL(url); resolve(image); };
        image.onerror = function () { URL.revokeObjectURL(url); reject(new Error("Браузер не смог прочитать изображение.")); };
        image.src = url;
      });
    }
    var width = Number(source.naturalWidth || source.width) || 0;
    var height = Number(source.naturalHeight || source.height) || 0;
    if (width < 1 || height < 1 || width > 12000 || height > 12000 || width * height > 60000000) {
      if (source && typeof source.close === "function") source.close();
      throw new Error("Изображение слишком большое по разрешению. Максимум — 60 мегапикселей.");
    }
    return { source: source, width: width, height: height, name: name };
  }

  function rotatedSize(width, height, rotation) {
    return rotation % 180 === 0 ? { width: width, height: height } : { width: height, height: width };
  }

  function currentCropTarget() {
    if (!state.cropSession) return null;
    return state.cropSession.profile.targets.find(function (target) { return target.key === state.cropSession.activeKey; }) || null;
  }

  function targetRatio(target, session) {
    if (target && Number(target.ratio) > 0) return Number(target.ratio);
    var rotated = rotatedSize(session.width, session.height, session.rotation);
    return rotated.width / rotated.height;
  }

  function clampCropState(target, cropState, session, outputWidth, outputHeight) {
    if (!target || target.mode === "contain") {
      cropState.zoom = 1;
      cropState.offsetX = 0;
      cropState.offsetY = 0;
      return;
    }
    var rotated = rotatedSize(session.width, session.height, session.rotation);
    var scale = Math.max(outputWidth / rotated.width, outputHeight / rotated.height) * cropState.zoom;
    var drawnWidth = rotated.width * scale;
    var drawnHeight = rotated.height * scale;
    var maxX = Math.max(0, (drawnWidth - outputWidth) / 2 / outputWidth);
    var maxY = Math.max(0, (drawnHeight - outputHeight) / 2 / outputHeight);
    cropState.offsetX = Math.max(-maxX, Math.min(maxX, cropState.offsetX));
    cropState.offsetY = Math.max(-maxY, Math.min(maxY, cropState.offsetY));
  }

  function drawCrop(context, source, sourceWidth, sourceHeight, cropState, rotation, width, height, mode) {
    context.save();
    context.clearRect(0, 0, width, height);
    context.fillStyle = "#0b0a09";
    context.fillRect(0, 0, width, height);
    var rotated = rotatedSize(sourceWidth, sourceHeight, rotation);
    var baseScale = mode === "contain"
      ? Math.min(width / rotated.width, height / rotated.height)
      : Math.max(width / rotated.width, height / rotated.height);
    var zoom = mode === "contain" ? 1 : cropState.zoom;
    var scale = baseScale * zoom;
    var offsetX = mode === "contain" ? 0 : cropState.offsetX * width;
    var offsetY = mode === "contain" ? 0 : cropState.offsetY * height;
    context.translate(width / 2 + offsetX, height / 2 + offsetY);
    context.rotate(rotation * Math.PI / 180);
    context.scale(scale, scale);
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = "high";
    context.drawImage(source, -sourceWidth / 2, -sourceHeight / 2, sourceWidth, sourceHeight);
    context.restore();
  }

  function renderCropTabs() {
    var session = state.cropSession;
    if (!session || !dom.cropTargetTabs) return;
    dom.cropTargetTabs.replaceChildren();
    session.profile.targets.forEach(function (target) {
      var button = createButton(target.label, "crop-target-tab" + (session.states[target.key].visited ? " is-checked" : ""));
      button.dataset.cropTarget = target.key;
      button.setAttribute("role", "tab");
      button.setAttribute("aria-selected", String(target.key === session.activeKey));
      dom.cropTargetTabs.appendChild(button);
    });
  }

  function renderCropEditor() {
    var session = state.cropSession;
    var target = currentCropTarget();
    if (!session || !target || !dom.cropCanvas) return;
    var crop = session.states[target.key];
    crop.rotation = session.rotation;
    var ratio = targetRatio(target, session);
    dom.cropStageWrap.style.setProperty("--crop-ratio", String(ratio));
    var previewWidth = 1200;
    var previewHeight = Math.max(1, Math.round(previewWidth / ratio));
    if (previewHeight > 1500) {
      previewHeight = 1500;
      previewWidth = Math.max(300, Math.round(previewHeight * ratio));
    }
    dom.cropCanvas.width = previewWidth;
    dom.cropCanvas.height = previewHeight;
    clampCropState(target, crop, session, previewWidth, previewHeight);
    drawCrop(dom.cropCanvas.getContext("2d"), session.source, session.width, session.height, crop, session.rotation, previewWidth, previewHeight, target.mode || "cover");
    dom.cropTargetTitle.textContent = target.label;
    dom.cropTargetRatio.textContent = target.ratioLabel;
    dom.cropZoom.disabled = target.mode === "contain";
    dom.cropZoom.value = String(Math.round(crop.zoom * 100));
    dom.cropZoomOutput.textContent = Math.round(crop.zoom * 100) + "%";
    dom.cropFormatNote.innerHTML = "<strong>Что проверить:</strong><br>" + target.note + "<br><br>Будут созданы размеры: " + target.sizes.join(", ") + " px.";
    renderCropTabs();
  }

  function selectCropTarget(key) {
    var session = state.cropSession;
    if (!session || !session.states[key]) return;
    session.activeKey = key;
    session.states[key].visited = true;
    renderCropEditor();
  }

  function closeCropSource(session) {
    if (session && session.source && typeof session.source.close === "function") {
      try { session.source.close(); } catch (_error) {}
    }
  }

  function cancelCropSession() {
    var session = state.cropSession;
    if (!session || session.processing) return;
    state.cropSession = null;
    closeCropSource(session);
    if (dom.cropDialog.open) dom.cropDialog.close("cancel");
    session.resolve(null);
  }

  async function openCropEditor(input, profile, existingAsset) {
    if (!profile) throw new Error("Для этого места не настроен формат фотографии.");
    showOperation("Чтение файла", "Открываю фотографию…", "Проверяю формат и разрешение.", 8);
    var loaded;
    try {
      loaded = await loadCropSource(input);
    } catch (error) {
      failOperation(error.message);
      throw error;
    }
    closeOperation();
    return new Promise(function (resolve) {
      var states = {};
      profile.targets.forEach(function (target) {
        var savedCrop = target.key === "default"
          ? existingAsset && existingAsset.crop
          : existingAsset && existingAsset[target.key] && existingAsset[target.key].crop;
        states[target.key] = cropStateFrom(savedCrop);
        if (input && input.url) states[target.key].rotation = 0;
      });
      var firstKey = profile.targets[0].key;
      var firstSaved = states[firstKey];
      var session = {
        source: loaded.source,
        width: loaded.width,
        height: loaded.height,
        name: loaded.name,
        profile: profile,
        states: states,
        rotation: firstSaved.rotation,
        activeKey: firstKey,
        processing: false,
        resolve: resolve
      };
      session.states[firstKey].visited = true;
      state.cropSession = session;
      dom.cropConfirm.disabled = false;
      document.querySelectorAll(".crop-cancel, .crop-target-tab, #cropRotateLeft, #cropRotateRight, #cropReset, #cropZoom").forEach(function (control) { control.disabled = false; });
      dom.cropFileName.textContent = loaded.name + " · " + loaded.width + " × " + loaded.height + " px";
      dom.cropExplanation.innerHTML = "<strong>Зачем это нужно:</strong> " + profile.explanation;
      if (dom.cropFooterTitle) {
        dom.cropFooterTitle.textContent = profile.targets.length > 1 ? "Проверьте каждый кадр" : (profile.id === "certificates" ? "Проверьте документ целиком" : "Проверьте фотографию");
      }
      if (dom.cropFooterNote) {
        dom.cropFooterNote.textContent = profile.targets.length > 1
          ? "После проверки всех вкладок панель сама сделает нужные размеры."
          : "Панель сама сделает нужные размеры без лишней обрезки.";
      }
      renderCropEditor();
      if (typeof dom.cropDialog.showModal === "function") dom.cropDialog.showModal();
      else dom.cropDialog.setAttribute("open", "");
    });
  }

  function canvasBlobAtQuality(canvas, quality) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (!blob) {
          reject(new Error("Браузер не смог подготовить изображение."));
          return;
        }
        resolve(blob);
      }, "image/webp", quality);
    });
  }

  async function canvasToImageBlob(canvas, quality, maxBytes) {
    var nextQuality = quality;
    var blob = null;
    for (var attempt = 0; attempt < 5; attempt += 1) {
      blob = await canvasBlobAtQuality(canvas, nextQuality);
      if (!maxBytes || blob.size <= maxBytes) return blob;
      nextQuality = Math.max(0.56, nextQuality - 0.08);
    }
    throw new Error("Фотография слишком детальная для лимита хостинга даже после сжатия. Уменьшите исходный файл и повторите.");
  }

  function makeFitCanvas(session, maxSide) {
    var rotated = rotatedSize(session.width, session.height, session.rotation);
    var scale = Math.min(1, maxSide / Math.max(rotated.width, rotated.height));
    var canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(rotated.width * scale));
    canvas.height = Math.max(1, Math.round(rotated.height * scale));
    drawCrop(canvas.getContext("2d"), session.source, session.width, session.height, { zoom: 1, offsetX: 0, offsetY: 0 }, session.rotation, canvas.width, canvas.height, "contain");
    return canvas;
  }

  function makeCroppedCanvas(session, target, width) {
    var ratio = targetRatio(target, session);
    var canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = Math.max(1, Math.round(width / ratio));
    var crop = session.states[target.key];
    clampCropState(target, crop, session, canvas.width, canvas.height);
    drawCrop(canvas.getContext("2d"), session.source, session.width, session.height, crop, session.rotation, canvas.width, canvas.height, target.mode || "cover");
    return canvas;
  }

  async function prepareAndUploadCrop() {
    var session = state.cropSession;
    if (!session || session.processing) return;
    var unvisited = session.profile.targets.find(function (target) { return !session.states[target.key].visited; });
    if (unvisited) {
      selectCropTarget(unvisited.key);
      toast("Сначала проверьте кадр «" + unvisited.label + "». Затем снова нажмите «Подготовить и загрузить».", "error");
      return;
    }
    session.processing = true;
    dom.cropConfirm.disabled = true;
    document.querySelectorAll(".crop-cancel, .crop-target-tab, #cropRotateLeft, #cropRotateRight, #cropReset, #cropZoom").forEach(function (control) { control.disabled = true; });
    showOperation("Подготовка фотографии", "Создаю мастер-файл…", "Не закрывайте вкладку. Затем начнётся передача на сервер.", 6);
    try {
      var form = new FormData();
      var manifest = { profile: session.profile.id, files: [], crops: {} };
      var fileNumber = 0;
      var preparedBytes = 0;
      var maxPreparedFileBytes = state.capabilities ? Number(state.capabilities.maxPreparedFileBytes) || 0 : 0;
      var maxUploadSetBytes = state.capabilities ? Number(state.capabilities.maxUploadSetBytes) || 0 : 0;
      async function appendCanvas(canvas, role, quality) {
        var blob = await canvasToImageBlob(canvas, quality, maxPreparedFileBytes);
        preparedBytes += blob.size;
        if (maxUploadSetBytes && preparedBytes > maxUploadSetBytes) {
          throw new Error("Все версии фотографии получились слишком тяжёлыми для одного запроса хостинга. Уменьшите исходный файл и повторите.");
        }
        var field = "asset_" + fileNumber;
        fileNumber += 1;
        var extension = blob.type === "image/webp" ? "webp" : (blob.type === "image/png" ? "png" : "jpg");
        form.append(field, blob, session.profile.id + "-" + role + "-" + canvas.width + "." + extension);
        manifest.files.push({ field: field, role: role, width: canvas.width, height: canvas.height });
      }

      await appendCanvas(makeFitCanvas(session, 2400), "master", 0.9);
      showOperation("Подготовка фотографии", "Создаю размеры для сайта…", "Мастер-файл готов. Подготавливаю быстрые версии.", 18);

      if (Array.isArray(session.profile.defaultSizes)) {
        var seenFit = {};
        for (var fitIndex = 0; fitIndex < session.profile.defaultSizes.length; fitIndex += 1) {
          var fitCanvas = makeFitCanvas(session, session.profile.defaultSizes[fitIndex]);
          var fitKey = fitCanvas.width + "x" + fitCanvas.height;
          if (seenFit[fitKey]) continue;
          seenFit[fitKey] = true;
          await appendCanvas(fitCanvas, "default", 0.86);
          showOperation("Подготовка фотографии", "Создаю размеры для сайта…", "Готово файлов: " + manifest.files.length, 18 + Math.min(38, manifest.files.length * 5));
        }
      }

      for (var targetIndex = 0; targetIndex < session.profile.targets.length; targetIndex += 1) {
        var target = session.profile.targets[targetIndex];
        if (Array.isArray(session.profile.defaultSizes) && target.key === "default") {
          manifest.crops.default = Object.assign({}, session.states[target.key], { rotation: session.rotation });
          continue;
        }
        for (var sizeIndex = 0; sizeIndex < target.sizes.length; sizeIndex += 1) {
          await appendCanvas(makeCroppedCanvas(session, target, target.sizes[sizeIndex]), target.key, 0.84);
          showOperation("Подготовка фотографии", "Создаю кадры для «" + target.label + "»…", "Готово файлов: " + manifest.files.length, 28 + Math.min(36, manifest.files.length * 4));
        }
        manifest.crops[target.key] = Object.assign({}, session.states[target.key], { rotation: session.rotation });
      }

      form.append("manifest", JSON.stringify(manifest));
      showOperation("Загрузка на сервер", "Передаю подготовленные размеры…", "0% передано. Вкладку можно оставить открытой.", 68);
      var result = await apiUploadSet(form, function (fraction) {
        var percent = Math.round(fraction * 100);
        showOperation("Загрузка на сервер", "Передаю подготовленные размеры…", percent + "% передано. Не закрывайте вкладку.", 68 + Math.round(fraction * 29));
      });
      state.cropSession = null;
      closeCropSource(session);
      dom.cropDialog.close("uploaded");
      finishOperation("Фотография полностью готова", "Созданы версии для компьютера, телефона и разных размеров экрана.");
      session.resolve(result.photo);
    } catch (error) {
      session.processing = false;
      dom.cropConfirm.disabled = false;
      document.querySelectorAll(".crop-cancel, .crop-target-tab, #cropRotateLeft, #cropRotateRight, #cropReset, #cropZoom").forEach(function (control) { control.disabled = false; });
      failOperation(error.message);
      toast(error.message, "error");
    }
  }

  function replaceMediaAsset(media, prepared) {
    var identity = {
      id: media.id,
      label: media.label,
      alt: media.alt,
      caption: media.caption
    };
    ["src", "thumb", "width", "height", "cropProfile", "master", "variants", "card", "mobile", "crop"].forEach(function (key) {
      delete media[key];
    });
    Object.keys(prepared || {}).forEach(function (key) { media[key] = prepared[key]; });
    media.id = identity.id;
    media.label = identity.label;
    media.alt = identity.alt;
    media.caption = identity.caption;
  }

  function mediaGroupLimit(groupKey) {
    var setting = mediaGroupSettings[groupKey] || {};
    var serverLimits = state.capabilities && state.capabilities.mediaLimits;
    var serverLimit = serverLimits ? Number(serverLimits[groupKey]) : 0;
    return serverLimit > 0 ? serverLimit : Number(setting.fallbackLimit || 1);
  }

  function mediaGroupIsDynamic(groupKey) {
    return Boolean(mediaGroupSettings[groupKey] && mediaGroupSettings[groupKey].dynamic);
  }

  function nextMediaId(groupKey) {
    var items = state.content.site.media[groupKey] || [];
    var used = new Set(items.map(function (item) { return String(item.id || ""); }));
    var number = 1;
    while (used.has(groupKey + "-" + number)) number += 1;
    return groupKey + "-" + number;
  }

  function mediaItemLabel(groupKey, index) {
    if (groupKey === "hero") return "Первый экран · кадр " + (index + 1);
    if (groupKey === "workshop") return "Мастерская · фото " + (index + 1);
    if (groupKey === "certificates") return "Сертификат " + (index + 1);
    return (groupTitles[groupKey] || "Фотография") + " · " + (index + 1);
  }

  function renumberMediaGroup(groupKey) {
    var items = state.content.site.media[groupKey] || [];
    if (!mediaGroupIsDynamic(groupKey)) return;
    items.forEach(function (item, index) {
      item.label = mediaItemLabel(groupKey, index);
      if (groupKey === "workshop" && String(item.caption || "").trim()) {
        var caption = String(item.caption).replace(/^(?:N°\s*)?\d{1,3}\s*·\s*/i, "").trim();
        item.caption = String(index + 1).padStart(2, "0") + " · " + caption;
      }
    });
  }

  function moveMediaItem(groupKey, fromIndex, toIndex) {
    var items = state.content.site.media[groupKey] || [];
    if (!mediaGroupIsDynamic(groupKey) || fromIndex < 0 || toIndex < 0 || fromIndex >= items.length || toIndex >= items.length || fromIndex === toIndex) return;
    var moved = items.splice(fromIndex, 1)[0];
    items.splice(toIndex, 0, moved);
    renumberMediaGroup(groupKey);
    renderMedia();
    markDirty();
    toast("Порядок фотографий изменён. Проверьте раздел в предпросмотре.", "success");
  }

  function removeMediaItem(groupKey, index) {
    var items = state.content.site.media[groupKey] || [];
    if (!mediaGroupIsDynamic(groupKey) || !items[index]) return;
    if (items.length <= 1) {
      toast("В этом разделе должна остаться хотя бы одна фотография.", "error");
      return;
    }
    var title = items[index].label || "эту фотографию";
    if (!window.confirm("Убрать «" + title + "» из раздела? Файл останется на сервере, а опубликованный сайт не изменится до публикации.")) return;
    items.splice(index, 1);
    renumberMediaGroup(groupKey);
    renderMedia();
    updateMetrics();
    markDirty();
    toast("Фотография убрана из неопубликованных правок.", "success");
  }

  async function addMediaFiles(groupKey, fileList) {
    if (!mediaGroupIsDynamic(groupKey)) return;
    var files = Array.from(fileList || []);
    if (!files.length) return;
    var items = state.content.site.media[groupKey] || [];
    var limit = mediaGroupLimit(groupKey);
    var available = Math.max(0, limit - items.length);
    if (!available) {
      toast("Достигнут лимит раздела: " + limit + " фотографий.", "error");
      return;
    }
    if (files.length > available) {
      toast("Сейчас можно добавить ещё " + available + ". Выбрано файлов: " + files.length + ". Уменьшите выбор и повторите.", "error");
      return;
    }

    var uploadedCount = 0;
    try {
      for (var index = 0; index < files.length; index += 1) {
        var prepared = await openCropEditor(files[index], profileForMedia(groupKey, items.length), null);
        if (!prepared) continue;
        var media = {
          id: nextMediaId(groupKey),
          label: mediaItemLabel(groupKey, items.length),
          alt: "",
          caption: groupKey === "workshop" ? String(items.length + 1).padStart(2, "0") + " · Новая фотография" : ""
        };
        replaceMediaAsset(media, prepared);
        items.push(media);
        renumberMediaGroup(groupKey);
        uploadedCount += 1;
        renderMedia();
        updateMetrics();
      }
      if (uploadedCount) {
        markDirty();
        finishOperation(uploadedCount === 1 ? "Фотография раздела готова" : "Все фотографии раздела готовы", "Заполните описания, расставьте порядок и откройте предпросмотр.");
        toast("Добавлено фотографий: " + uploadedCount + ". Заполните описания перед публикацией.", "success");
        var lastAlt = dom.media.querySelector('[data-media-group="' + groupKey + '"] [data-media-index]:last-child [data-media-field="alt"]');
        if (lastAlt) lastAlt.focus();
      }
    } catch (error) {
      if (uploadedCount) {
        markDirty();
        updateMetrics();
      }
      failOperation(error.message);
      toast(error.message + (uploadedCount ? " Уже подготовленные фотографии остались в неопубликованных правках." : ""), "error");
    }
  }

  function renderMedia() {
    if (!dom.media || !state.content) return;
    dom.media.replaceChildren();
    Object.keys(groupTitles).forEach(function (groupKey) {
      var items = state.content.site.media[groupKey] || [];
      var setting = mediaGroupSettings[groupKey] || {};
      var limit = mediaGroupLimit(groupKey);
      var section = createElement("section", "media-group");
      section.dataset.mediaGroup = groupKey;
      var header = createElement("header", "media-group-header");
      var titleBlock = createElement("div", "media-group-title");
      titleBlock.appendChild(createElement("h2", "", groupTitles[groupKey]));
      titleBlock.appendChild(createElement("p", "", setting.note || ""));
      header.appendChild(titleBlock);
      var headerActions = createElement("div", "media-group-actions");
      headerActions.appendChild(createElement("span", "media-group-count", items.length + (setting.dynamic ? " из " + limit : "") + " фото"));
      if (setting.dynamic) {
        if (items.length < limit) {
          var addLabel = createElement("label", "button button-secondary file-button media-add-button", setting.addLabel || "+ Добавить фотографии");
          var addInput = document.createElement("input");
          addInput.type = "file";
          addInput.accept = "image/jpeg,image/png,image/webp";
          addInput.multiple = true;
          addInput.hidden = true;
          addInput.dataset.mediaAdd = groupKey;
          addLabel.appendChild(addInput);
          headerActions.appendChild(addLabel);
        } else {
          headerActions.appendChild(createElement("span", "media-limit-reached", "Достигнут лимит " + limit));
        }
      }
      header.appendChild(headerActions);
      section.appendChild(header);
      var grid = createElement("div", "media-items");

      if (!items.length) {
        grid.appendChild(createElement("div", "empty-state", "В этом разделе пока нет фотографий."));
      }

      items.forEach(function (item, index) {
        var mediaProfile = profileForMedia(groupKey, index);
        var card = createElement("article", "media-item");
        card.dataset.mediaIndex = String(index);
        var previewWrap = createElement("div", "media-preview-wrap");
        var preview = createElement("img", "media-preview");
        preview.src = rootImageUrl(item.src);
        preview.alt = item.alt || item.label;
        preview.loading = "lazy";
        var firstTarget = mediaProfile.targets[0];
        if (firstTarget && firstTarget.ratio) preview.style.aspectRatio = String(firstTarget.ratio);
        previewWrap.appendChild(preview);
        var uploadLabel = createElement("label", "button button-secondary file-button", "Заменить и обрезать");
        var upload = document.createElement("input");
        upload.type = "file";
        upload.accept = "image/jpeg,image/png,image/webp";
        upload.hidden = true;
        upload.dataset.mediaUpload = "true";
        uploadLabel.appendChild(upload);
        previewWrap.appendChild(uploadLabel);
        var recrop = createButton("Изменить кадр", "button button-ghost", "media-recrop");
        recrop.dataset.mediaRecrop = "true";
        previewWrap.appendChild(recrop);
        card.appendChild(previewWrap);

        var fields = createElement("div", "media-fields");
        var itemTitle = createElement("div", "media-item-title");
        itemTitle.appendChild(createElement("h3", "", item.label));
        itemTitle.appendChild(createElement("span", "media-position", "Позиция " + (index + 1)));
        fields.appendChild(itemTitle);
        fields.appendChild(createElement("p", "media-profile-note", mediaProfile.explanation));
        fields.appendChild(createElement("p", "media-adaptive-status", Array.isArray(item.variants) && item.variants.length > 1
          ? (item.mobile ? "✓ Готовы отдельные кадры и размеры для компьютера и телефона" : "✓ Готово несколько размеров")
          : "Старая фотография: при следующей замене будут автоматически созданы все размеры"));
        var altLabel = createElement("label", "", "Описание для доступности");
        var alt = document.createElement("input");
        alt.type = "text";
        alt.maxLength = 300;
        alt.value = item.alt || "";
        alt.dataset.mediaField = "alt";
        altLabel.appendChild(alt);
        fields.appendChild(altLabel);
        var captionLabel = createElement("label", "", "Подпись (если используется)");
        var caption = document.createElement("input");
        caption.type = "text";
        caption.maxLength = 200;
        caption.value = item.caption || "";
        caption.dataset.mediaField = "caption";
        captionLabel.appendChild(caption);
        fields.appendChild(captionLabel);
        if (setting.dynamic) {
          var actions = createElement("div", "media-item-actions");
          var up = createButton("↑ Выше", "button button-ghost", "media-up", "Поднять фотографию выше");
          var down = createButton("↓ Ниже", "button button-ghost", "media-down", "Опустить фотографию ниже");
          var remove = createButton("Удалить", "button button-danger", "media-remove", "Убрать фотографию из раздела");
          up.dataset.mediaAction = "up";
          down.dataset.mediaAction = "down";
          remove.dataset.mediaAction = "remove";
          up.disabled = index === 0;
          down.disabled = index === items.length - 1;
          remove.disabled = items.length <= 1;
          if (remove.disabled) remove.title = "В разделе должна остаться хотя бы одна фотография";
          actions.append(up, down, remove);
          fields.appendChild(actions);
        }
        card.appendChild(fields);
        grid.appendChild(card);
      });
      section.appendChild(grid);
      dom.media.appendChild(section);
    });
  }

  function populateContacts() {
    if (!dom.contacts || !state.content) return;
    var contacts = state.content.site.contacts;
    ["city", "serviceArea", "phoneDisplay", "phoneE164", "telegramLabel", "telegramUrl", "whatsappUrl"].forEach(function (name) {
      var input = dom.contacts.elements.namedItem(name);
      if (input) input.value = contacts[name] || "";
    });
    renderSocials();
  }

  function syncContactFields() {
    if (!state.content || !dom.contacts) return;
    var contacts = state.content.site.contacts;
    ["city", "serviceArea", "phoneDisplay", "phoneE164", "telegramLabel", "telegramUrl", "whatsappUrl"].forEach(function (name) {
      var input = dom.contacts.elements.namedItem(name);
      if (input) contacts[name] = input.value;
    });
    markDirty();
  }

  function renderSocials() {
    if (!dom.socials || !state.content) return;
    dom.socials.replaceChildren();
    var socials = state.content.site.contacts.socials || [];
    if (!socials.length) {
      dom.socials.appendChild(createElement("div", "empty-state", "Ссылок пока нет."));
      return;
    }

    socials.forEach(function (social, index) {
      var row = createElement("article", "social-row");
      row.dataset.socialIndex = String(index);
      var visibleLabel = createElement("label", "social-visible", "Виден");
      var visible = document.createElement("input");
      visible.type = "checkbox";
      visible.checked = Boolean(social.visible);
      visible.dataset.socialField = "visible";
      visibleLabel.prepend(visible);
      row.appendChild(visibleLabel);

      var idField = createElement("label", "", "ID");
      var id = document.createElement("input");
      id.value = social.id || "";
      id.maxLength = 64;
      id.pattern = "[a-z0-9][a-z0-9\\-]{0,63}";
      id.required = true;
      id.dataset.socialField = "id";
      idField.appendChild(id);
      row.appendChild(idField);

      var labelField = createElement("label", "", "Название");
      var label = document.createElement("input");
      label.value = social.label || "";
      label.maxLength = 80;
      label.required = true;
      label.dataset.socialField = "label";
      labelField.appendChild(label);
      row.appendChild(labelField);

      var urlField = createElement("label", "", "Ссылка https://");
      var url = document.createElement("input");
      url.type = "url";
      url.value = social.url || "";
      url.maxLength = 500;
      url.required = true;
      url.dataset.socialField = "url";
      urlField.appendChild(url);
      row.appendChild(urlField);

      var actions = createElement("div", "social-actions");
      var up = createButton("↑", "mini-button", "social-up", "Поднять ссылку");
      up.disabled = index === 0;
      var down = createButton("↓", "mini-button", "social-down", "Опустить ссылку");
      down.disabled = index === socials.length - 1;
      var remove = createButton("×", "mini-button", "social-delete", "Удалить ссылку");
      [up, down, remove].forEach(function (button) { actions.appendChild(button); });
      row.appendChild(actions);
      dom.socials.appendChild(row);
    });
  }

  function updateWorkingVersion() {
    if (!dom.workingVersion) return;
    dom.workingVersion.hidden = !state.activeDraftId;
    dom.workingVersion.textContent = state.activeDraftId ? "Черновик: " + state.activeDraftName : "";
    dom.workingVersion.title = state.activeDraftId ? "Сейчас открыта рабочая версия «" + state.activeDraftName + "». Она не опубликована." : "";
  }

  function draftStatsText(entry) {
    var stats = entry.stats || {};
    return (stats.projects || 0) + " проектов · " + (stats.projectPhotos || 0) + " фото проектов · " + (stats.sitePhotos || 0) + " фото сайта";
  }

  function renderDrafts() {
    if (!dom.draftsList || !dom.draftStatus) return;
    dom.draftsList.replaceChildren();
    if (state.draftsError) {
      dom.draftStatus.textContent = "Не удалось загрузить черновики: " + state.draftsError;
      return;
    }
    if (!state.draftsLoaded) {
      dom.draftStatus.textContent = state.draftsLoading ? "Загружаю черновики…" : "Список черновиков ещё не загружен.";
      return;
    }
    if (!state.drafts.length) {
      dom.draftStatus.textContent = "Сохранённых черновиков пока нет.";
      dom.draftsList.appendChild(createElement("div", "empty-state", "Внесите правки и нажмите «Сохранить текущий черновик»."));
      return;
    }
    dom.draftStatus.textContent = "Сохранено черновиков: " + state.drafts.length + ". Они не видны посетителям и не входят в историю публикаций.";
    state.drafts.forEach(function (entry) {
      var card = createElement("article", "draft-card" + (entry.id === state.activeDraftId ? " is-active" : ""));
      card.dataset.draftId = entry.id;
      var copy = createElement("div", "draft-card-copy");
      copy.appendChild(createElement("h3", "", entry.name));
      copy.appendChild(createElement("p", "", "Сохранён: " + formatDate(entry.updatedAt) + " · основа — версия сайта № " + String(entry.baseRevision || 0)));
      copy.appendChild(createElement("p", "", draftStatsText(entry)));
      var badges = createElement("div", "draft-card-badges");
      badges.appendChild(createElement("span", "status-badge hidden", "Не опубликован"));
      if (entry.id === state.activeDraftId) badges.appendChild(createElement("span", "status-badge", "Открыт сейчас"));
      copy.appendChild(badges);
      card.appendChild(copy);
      var actions = createElement("div", "draft-card-actions");
      var open = createButton("Открыть для работы", "button button-secondary", "draft-open");
      var preview = createButton("Предпросмотр ↗", "button button-ghost", "draft-preview");
      var remove = createButton("Удалить", "button button-danger", "draft-delete");
      [open, preview, remove].forEach(function (button) { actions.appendChild(button); });
      card.appendChild(actions);
      dom.draftsList.appendChild(card);
    });
  }

  async function loadDrafts(force) {
    if (state.draftsLoading || (state.draftsLoaded && !force)) return;
    state.draftsLoading = true;
    state.draftsError = "";
    renderDrafts();
    try {
      var result = await apiGet("drafts");
      state.drafts = Array.isArray(result.drafts) ? result.drafts : [];
      state.draftsLoaded = true;
    } catch (error) {
      state.draftsError = error.message;
      toast(error.message, "error");
    } finally {
      state.draftsLoading = false;
      renderDrafts();
    }
  }

  function openDraftSaveDialog() {
    if (!state.content || state.saving || state.operationActive) return;
    var date = new Date().toLocaleDateString("ru-RU", { day: "2-digit", month: "long" });
    dom.draftName.value = state.activeDraftName || ("Рабочая версия — " + date);
    dom.draftUpdateChoice.hidden = !state.activeDraftId;
    dom.updateCurrentDraft.checked = Boolean(state.activeDraftId);
    if (typeof dom.draftDialog.showModal === "function") dom.draftDialog.showModal();
    else dom.draftDialog.setAttribute("open", "");
    window.setTimeout(function () { dom.draftName.focus(); dom.draftName.select(); }, 30);
  }

  async function saveNamedDraft() {
    if (!state.content || !dom.draftForm.reportValidity()) return;
    var name = String(dom.draftName.value || "").trim();
    var updateExisting = Boolean(state.activeDraftId && dom.updateCurrentDraft.checked);
    var submit = dom.draftForm.querySelector('button[value="save"]');
    submit.disabled = true;
    showOperation("Сохранение черновика", "Записываю рабочую версию…", "Опубликованный сайт не меняется.", 35);
    try {
      var result = await apiPost("save-draft", {
        id: updateExisting ? state.activeDraftId : "",
        name: name,
        content: state.content
      }, false);
      state.activeDraftId = result.draft.id;
      state.activeDraftName = result.draft.name;
      state.drafts = Array.isArray(result.drafts) ? result.drafts : [];
      state.draftsLoaded = true;
      updateWorkingVersion();
      renderDrafts();
      dom.draftDialog.close("saved");
      if (state.dirty) setSaveState("dirty", "Черновик сохранён; сайт не опубликован");
      finishOperation("Черновик «" + state.activeDraftName + "» сохранён", "Посетители по-прежнему видят опубликованный сайт.");
      toast(result.message || "Черновик сохранён отдельно.", "success");
    } catch (error) {
      failOperation(error.message);
      toast(error.message, "error");
    } finally {
      submit.disabled = false;
    }
  }

  async function openSavedDraft(id) {
    if (!id || state.saving || state.operationActive) return;
    if (state.dirty && !window.confirm("Текущие неопубликованные правки будут заменены выбранным черновиком. Если они нужны, сначала нажмите «Сохранить черновик». Продолжить?")) return;
    showOperation("Открытие черновика", "Загружаю рабочую версию…", "Опубликованный сайт не меняется.", 35);
    try {
      var result = await apiGet("draft", { id: id });
      var draft = result.draft;
      state.content = clone(draft.content);
      state.content.revision = Number(state.baselineContent.revision || 0);
      state.content.updatedAt = state.baselineContent.updatedAt || "";
      state.activeDraftId = draft.id;
      state.activeDraftName = draft.name;
      state.changeVersion += 1;
      renderAll();
      markDirty();
      updateWorkingVersion();
      renderDrafts();
      switchSection("dashboard", true);
      finishOperation("Черновик открыт", "Теперь можно править, смотреть предпросмотр или публиковать.");
    } catch (error) {
      failOperation(error.message);
      toast(error.message, "error");
    }
  }

  async function deleteSavedDraft(id) {
    var entry = state.drafts.find(function (draft) { return draft.id === id; });
    if (!entry || !window.confirm("Удалить черновик «" + entry.name + "»? Опубликованный сайт и история не изменятся.")) return;
    showOperation("Удаление черновика", "Удаляю только выбранную рабочую версию…", "Публичный сайт останется без изменений.", 45);
    try {
      var result = await apiPost("delete-draft", { id: id }, false);
      state.drafts = Array.isArray(result.drafts) ? result.drafts : [];
      state.draftsLoaded = true;
      if (state.activeDraftId === id) {
        state.activeDraftId = "";
        state.activeDraftName = "";
        updateWorkingVersion();
      }
      renderDrafts();
      finishOperation("Черновик удалён", "Опубликованный сайт не менялся.");
      toast(result.message || "Черновик удалён.", "success");
    } catch (error) {
      failOperation(error.message);
      toast(error.message, "error");
    }
  }

  async function previewContent(content, label) {
    if (!content || state.operationActive) return;
    if (dom.projectDialog && dom.projectDialog.open) {
      toast("Сначала нажмите «Сохранить проект в правках», затем откройте предпросмотр.", "error");
      return;
    }
    var previewWindow = window.open("about:blank", "_blank");
    if (!previewWindow) {
      toast("Браузер заблокировал новую вкладку. Разрешите всплывающие окна для этого сайта.", "error");
      return;
    }
    try {
      previewWindow.document.title = "Подготавливаю предпросмотр…";
      previewWindow.document.body.textContent = "Подготавливаю закрытый предпросмотр…";
    } catch (_error) {}
    showOperation("Предпросмотр", "Подготавливаю точную копию сайта…", (label || "Текущие правки") + ". Ничего не публикуется.", 45);
    try {
      var result = await apiPost("prepare-preview", { content: content }, false);
      previewWindow.location.replace(result.url);
      finishOperation("Предпросмотр открыт в новой вкладке", "Плашка сверху подтверждает, что это неопубликованная версия.");
    } catch (error) {
      previewWindow.close();
      failOperation(error.message);
      toast(error.message, "error");
    }
  }

  async function previewSavedDraft(id) {
    showOperation("Предпросмотр", "Загружаю выбранный черновик…", "Опубликованный сайт не меняется.", 25);
    try {
      var result = await apiGet("draft", { id: id });
      closeOperation();
      await previewContent(result.draft.content, "Черновик «" + result.draft.name + "»");
    } catch (error) {
      failOperation(error.message);
      toast(error.message, "error");
    }
  }

  function formatHistoryDate(value) {
    if (!value) return "Время неизвестно";
    var date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString("ru-RU", {
      weekday: "short",
      day: "2-digit",
      month: "long",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    });
  }

  function historyStatsText(entry) {
    var stats = entry.stats || {};
    return (stats.projects || 0) + " проектов · " + (stats.projectPhotos || 0) + " фото в проектах · " + (stats.sitePhotos || 0) + " фото сайта";
  }

  function renderHistory() {
    if (!dom.historyList || !dom.historyStatus) return;
    dom.historyList.replaceChildren();
    var hours = Number((state.capabilities || {}).historyHours) || 72;
    var minimum = Number((state.capabilities || {}).historyMinimumVersions) || 100;
    if (state.historyError) {
      dom.historyStatus.textContent = "Не удалось загрузить историю: " + state.historyError;
      return;
    }
    if (!state.historyLoaded) {
      dom.historyStatus.textContent = state.historyLoading ? "Загружаю историю…" : "История ещё не загружена.";
      return;
    }
    if (!state.history.length) {
      dom.historyStatus.textContent = "История пока пуста. Первая страховочная копия появится после следующей публикации.";
      dom.historyList.appendChild(createElement("div", "empty-state", "Здесь появятся предыдущие версии сайта."));
      return;
    }
    dom.historyStatus.textContent = "Доступно версий: " + state.history.length + ". Панель сохраняет версии минимум за " + hours + " часа и не менее " + minimum + " последних публикаций.";

    state.history.forEach(function (entry, index) {
      var card = createElement("article", "history-card" + (index === 0 ? " is-latest" : ""));
      var header = createElement("div", "history-card-header");
      var title = createElement("div");
      title.appendChild(createElement("strong", "history-date", formatHistoryDate(entry.capturedAt)));
      title.appendChild(createElement("span", "history-revision", "Версия № " + String(entry.revision || 0)));
      header.appendChild(title);
      var badges = createElement("div", "history-badges");
      if (index === 0) badges.appendChild(createElement("span", "history-badge", "До последней публикации"));
      if (entry.withinRecoveryWindow) badges.appendChild(createElement("span", "history-badge safe", "В пределах " + hours + " часов"));
      header.appendChild(badges);
      card.appendChild(header);
      card.appendChild(createElement("p", "history-stats", historyStatsText(entry)));

      var details = createElement("ul", "history-change-list");
      ((entry.changes || {}).details || ["Сведения об отличиях недоступны."]).forEach(function (detail) {
        details.appendChild(createElement("li", "", detail));
      });
      card.appendChild(details);

      var footer = createElement("div", "history-card-footer");
      var hint = state.dirty
        ? "Есть неопубликованные правки. При восстановлении панель отдельно предложит их отменить."
        : ((entry.changes || {}).hasChanges ? "Текущая версия сохранится автоматически." : "Эта версия совпадает с текущей.");
      footer.appendChild(createElement("small", "", hint));
      var restore = createButton(state.dirty ? "Проверить и восстановить" : "Посмотреть и восстановить", "button button-secondary", "history-restore");
      restore.dataset.historyId = entry.id;
      restore.disabled = !(entry.changes || {}).hasChanges;
      footer.appendChild(restore);
      card.appendChild(footer);
      dom.historyList.appendChild(card);
    });
  }

  async function loadHistory(force) {
    if (state.historyLoading || (state.historyLoaded && !force)) return;
    state.historyLoading = true;
    state.historyError = "";
    renderHistory();
    if (dom.refreshHistory) dom.refreshHistory.disabled = true;
    try {
      var result = await apiGet("history");
      state.history = Array.isArray(result.history) ? result.history : [];
      state.historyLoaded = true;
      if (result.policy) {
        state.capabilities.historyHours = Number(result.policy.recoveryHours) || state.capabilities.historyHours;
        state.capabilities.historyMinimumVersions = Number(result.policy.minimumVersions) || state.capabilities.historyMinimumVersions;
      }
    } catch (error) {
      state.historyError = error.message;
      toast(error.message, "error");
    } finally {
      state.historyLoading = false;
      if (dom.refreshHistory) dom.refreshHistory.disabled = false;
      renderHistory();
    }
  }

  function closeRestoreDialog() {
    state.selectedHistory = null;
    if (dom.restoreDirtyWarning) dom.restoreDirtyWarning.hidden = true;
    if (dom.confirmRestore) dom.confirmRestore.textContent = "Восстановить и опубликовать";
    if (!dom.restoreDialog) return;
    if (typeof dom.restoreDialog.close === "function" && dom.restoreDialog.open) dom.restoreDialog.close();
    else dom.restoreDialog.removeAttribute("open");
  }

  function openRestoreDialog(historyId) {
    var entry = state.history.find(function (item) { return item.id === historyId; });
    if (!entry || !(entry.changes || {}).hasChanges) return;
    state.selectedHistory = entry;
    if (dom.restoreDirtyWarning) dom.restoreDirtyWarning.hidden = !state.dirty;
    if (dom.confirmRestore) dom.confirmRestore.textContent = state.dirty ? "Отменить правки и восстановить" : "Восстановить и опубликовать";
    dom.restoreVersionMeta.textContent = formatHistoryDate(entry.capturedAt) + " · версия № " + String(entry.revision || 0) + " · " + historyStatsText(entry);
    dom.restoreChangeList.replaceChildren();
    (entry.changes.details || []).forEach(function (detail) {
      dom.restoreChangeList.appendChild(createElement("li", "", detail));
    });
    if (typeof dom.restoreDialog.showModal === "function") dom.restoreDialog.showModal();
    else dom.restoreDialog.setAttribute("open", "");
    dom.confirmRestore.focus();
  }

  async function restoreSelectedHistory() {
    if (!state.selectedHistory || state.saving || !state.content) return;
    var entry = state.selectedHistory;
    state.saving = true;
    dom.confirmRestore.disabled = true;
    setSaveState("saving", "Восстанавливаю версию…");
    try {
      var result = await apiPost("restore", {
        historyId: entry.id,
        revision: Number(state.content.revision || 0)
      }, false);
      state.content = result.content;
      state.history = Array.isArray(result.history) ? result.history : [];
      state.historyLoaded = true;
      state.changeVersion += 1;
      markSaved(result.content);
      state.activeDraftId = "";
      state.activeDraftName = "";
      updateWorkingVersion();
      renderAll();
      renderHistory();
      closeRestoreDialog();
      toast(result.message || "Версия восстановлена.", "success");
    } catch (error) {
      setSaveState("error", "Не удалось восстановить версию");
      toast(error.message, "error");
    } finally {
      state.saving = false;
      dom.confirmRestore.disabled = false;
    }
  }

  function downloadContentBackup() {
    var content = state.baselineContent || state.content;
    if (!content) return;
    var blob = new Blob([JSON.stringify(content, null, 2) + "\n"], { type: "application/json;charset=utf-8" });
    var url = URL.createObjectURL(blob);
    var link = document.createElement("a");
    var date = new Date().toISOString().slice(0, 10);
    link.href = url;
    link.download = "tv-content-r" + String(content.revision || 0).padStart(6, "0") + "-" + date + ".json";
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    toast("Копия опубликованного контента скачана.", "success");
  }

  function discardUnpublishedChanges() {
    if (!state.dirty || !state.baselineContent) return;
    if (!window.confirm("Отменить все неопубликованные правки в этой вкладке? Опубликованный сайт не изменится.")) return;
    if (dom.projectDialog && dom.projectDialog.open) dom.projectDialog.close("discarded");
    state.content = clone(state.baselineContent);
    state.currentDraft = null;
    state.currentDraftIndex = -1;
    state.activeDraftId = "";
    state.activeDraftName = "";
    state.changeVersion += 1;
    renderAll();
    markSaved(state.baselineContent);
    updateWorkingVersion();
    renderHistory();
    toast("Неопубликованные правки отменены. Сайт не менялся.", "success");
  }

  function switchSection(name, updateHash) {
    var target = document.querySelector('[data-section="' + name + '"]');
    if (!target) name = "dashboard";
    document.querySelectorAll("[data-section]").forEach(function (section) {
      var active = section.dataset.section === name;
      section.hidden = !active;
      section.classList.toggle("active", active);
    });
    document.querySelectorAll("[data-section-target]").forEach(function (button) {
      button.classList.toggle("active", button.dataset.sectionTarget === name);
    });
    if (updateHash && window.location.hash !== "#" + name) history.replaceState(null, "", "#" + name);
    setSidebarOpen(false);
    window.scrollTo({ top: 0, behavior: "smooth" });
    if (name === "history") loadHistory(false);
    if (name === "drafts") loadDrafts(false);
  }

  function setSidebarOpen(open) {
    open = Boolean(open && dom.sidebar);
    if (dom.sidebar) dom.sidebar.classList.toggle("open", open);
    if (dom.sidebarToggle) {
      dom.sidebarToggle.setAttribute("aria-expanded", String(open));
      dom.sidebarToggle.setAttribute("aria-label", open ? "Закрыть меню" : "Открыть меню");
    }
    if (dom.sidebarBackdrop) dom.sidebarBackdrop.hidden = !open;
    document.body.classList.toggle("admin-menu-open", open);
  }

  async function publishChanges() {
    if (!state.content || state.saving || !state.dirty) return;
    if (dom.contacts && !dom.contacts.reportValidity()) {
      switchSection("contacts", true);
      toast("Проверьте контактные данные и ссылки.", "error");
      return;
    }
    state.saving = true;
    var versionAtSave = state.changeVersion;
    var contentToSave = clone(state.content);
    setSaveState("saving", "Публикую изменения…");
    showOperation("Публикация", "Передаю изменения на сайт…", "Не закрывайте вкладку до сообщения об успехе.", 35);
    updateSaveButtons();
    try {
      var revision = Number(contentToSave.revision || 0);
      var result = await apiPost("save", { revision: revision, content: contentToSave }, false);
      if (state.changeVersion === versionAtSave) {
        state.content = result.content;
        markSaved(result.content);
        state.activeDraftId = "";
        state.activeDraftName = "";
        updateWorkingVersion();
      } else {
        state.content.revision = result.content.revision;
        state.content.updatedAt = result.content.updatedAt;
        state.baselineContent = clone(result.content);
        state.dirty = true;
        if (dom.publishBar) dom.publishBar.hidden = false;
        setSaveState("dirty", "Есть новые неопубликованные изменения");
        updatePublishSummary();
        renderReadiness();
      }
      updateMetrics();
      renderProjects();
      loadHistory(true);
      finishOperation("Изменения опубликованы", "Теперь посетители видят новую версию сайта.");
      toast(state.dirty ? "Предыдущие изменения опубликованы; новые ещё ждут публикации." : (result.message || "Изменения опубликованы."), "success");
    } catch (error) {
      setSaveState("error", "Не удалось опубликовать");
      failOperation(error.message);
      if (error.conflict) {
        toast(error.message + " Ваши данные в этой вкладке сохранены до обновления.", "error");
      } else {
        toast(error.message, "error");
      }
    } finally {
      state.saving = false;
      updateSaveButtons();
    }
  }

  function renderAll() {
    updateMetrics();
    renderProjects();
    renderMedia();
    populateContacts();
    renderReadiness();
    updateWorkingVersion();
    if (state.draftsLoaded) renderDrafts();
  }

  document.addEventListener("click", function (event) {
    var target = event.target.closest("button, a");
    if (!target) return;

    if (target.matches("[data-section-target]")) {
      switchSection(target.dataset.sectionTarget, true);
      return;
    }
    if (target.matches("[data-go-section]")) {
      switchSection(target.dataset.goSection, true);
      return;
    }
    if (target.matches("[data-nav]")) {
      event.preventDefault();
      switchSection(target.dataset.nav, true);
      return;
    }
    if (target.matches(".new-project")) {
      openProjectEditor(null, -1);
      return;
    }
    if (target.matches(".save-draft")) {
      openDraftSaveDialog();
      return;
    }
    if (target.matches(".preview-current")) {
      previewContent(clone(state.content), state.activeDraftName ? "Черновик «" + state.activeDraftName + "»" : "Текущие правки");
      return;
    }
    if (target.matches(".save-all")) {
      publishChanges();
      return;
    }
    if (target.matches(".download-backup")) {
      downloadContentBackup();
      return;
    }
    if (target.id === "refreshHistory") {
      loadHistory(true);
      return;
    }
    if (target.dataset.action === "history-restore") {
      openRestoreDialog(target.dataset.historyId || "");
      return;
    }
    if (target.matches(".restore-close")) {
      closeRestoreDialog();
      return;
    }
    if (target.dataset.cropTarget) {
      selectCropTarget(target.dataset.cropTarget);
      return;
    }

    var draftCard = target.closest(".draft-card");
    if (draftCard && target.dataset.action) {
      var draftId = draftCard.dataset.draftId || "";
      if (target.dataset.action === "draft-open") openSavedDraft(draftId);
      if (target.dataset.action === "draft-preview") previewSavedDraft(draftId);
      if (target.dataset.action === "draft-delete") deleteSavedDraft(draftId);
      return;
    }

    if (target.dataset.mediaRecrop) {
      var mediaGroup = target.closest("[data-media-group]");
      var mediaCard = target.closest("[data-media-index]");
      if (!mediaGroup || !mediaCard) return;
      var mediaKey = mediaGroup.dataset.mediaGroup;
      var mediaIndex = Number(mediaCard.dataset.mediaIndex);
      var mediaItem = state.content.site.media[mediaKey][mediaIndex];
      var masterPath = mediaItem && mediaItem.master && mediaItem.master.src ? mediaItem.master.src : mediaItem.src;
      openCropEditor({ url: rootImageUrl(masterPath), name: mediaItem.label }, profileForMedia(mediaKey, mediaIndex), mediaItem).then(function (prepared) {
        if (!prepared) return;
        replaceMediaAsset(mediaItem, prepared);
        renderMedia();
        markDirty();
        toast("Кадры обновлены. Проверьте предпросмотр; сайт ещё не опубликован.", "success");
      }).catch(function (error) { toast(error.message, "error"); });
      return;
    }

    if (target.dataset.mediaAction) {
      var actionGroup = target.closest("[data-media-group]");
      var actionCard = target.closest("[data-media-index]");
      if (!actionGroup || !actionCard) return;
      var actionKey = actionGroup.dataset.mediaGroup;
      var actionIndex = Number(actionCard.dataset.mediaIndex);
      if (target.dataset.mediaAction === "up") moveMediaItem(actionKey, actionIndex, actionIndex - 1);
      if (target.dataset.mediaAction === "down") moveMediaItem(actionKey, actionIndex, actionIndex + 1);
      if (target.dataset.mediaAction === "remove") removeMediaItem(actionKey, actionIndex);
      return;
    }

    var projectCard = target.closest(".project-admin-card");
    if (projectCard && target.dataset.action) {
      var projectIndex = state.content.projects.findIndex(function (project) { return project.id === projectCard.dataset.projectId; });
      if (projectIndex < 0) return;
      var project = state.content.projects[projectIndex];
      if (target.dataset.action === "project-edit") openProjectEditor(project, projectIndex);
      if (target.dataset.action === "project-up") moveProject(projectIndex, projectIndex - 1);
      if (target.dataset.action === "project-down") moveProject(projectIndex, projectIndex + 1);
      if (target.dataset.action === "project-visibility") {
        if (!project.visible && (!project.photos || !project.photos.length)) {
          toast("Сначала добавьте в проект фотографию.", "error");
          return;
        }
        project.visible = !project.visible;
        renderProjects();
        updateMetrics();
        markDirty();
      }
      if (target.dataset.action === "project-duplicate") {
        var copy = clone(project);
        copy.id = uniqueProjectId(project.id + "-copy");
        copy.slug = copy.id;
        copy.visible = false;
        copy.title = project.title + " — копия";
        state.content.projects.splice(projectIndex + 1, 0, copy);
        updateOrders();
        renderProjects();
        updateMetrics();
        markDirty();
        toast("Создана скрытая копия проекта.", "success");
      }
      if (target.dataset.action === "project-delete") {
        if (window.confirm("Удалить проект «" + projectDisplayTitle(project) + "» из списка? Загруженные файлы останутся на сервере.")) {
          state.content.projects.splice(projectIndex, 1);
          updateOrders();
          renderProjects();
          updateMetrics();
          markDirty();
        }
      }
      return;
    }

    var photoCard = target.closest(".photo-editor-card");
    if (photoCard && state.currentDraft && target.dataset.action) {
      var photoIndex = Number(photoCard.dataset.photoIndex);
      var photos = state.currentDraft.photos;
      if (target.dataset.action === "photo-cover") state.currentDraft.cover = photos[photoIndex].src;
      if (target.dataset.action === "photo-left" && photoIndex > 0) {
        var leftPhoto = photos.splice(photoIndex, 1)[0];
        photos.splice(photoIndex - 1, 0, leftPhoto);
      }
      if (target.dataset.action === "photo-right" && photoIndex < photos.length - 1) {
        var rightPhoto = photos.splice(photoIndex, 1)[0];
        photos.splice(photoIndex + 1, 0, rightPhoto);
      }
      if (target.dataset.action === "photo-delete") {
        var removed = photos.splice(photoIndex, 1)[0];
        if (removed && removed.src === state.currentDraft.cover) state.currentDraft.cover = photos[0] ? photos[0].src : "";
      }
      if (target.dataset.action === "photo-recrop") {
        var sourcePhoto = photos[photoIndex];
        var sourcePath = sourcePhoto && sourcePhoto.master && sourcePhoto.master.src ? sourcePhoto.master.src : sourcePhoto.src;
        openCropEditor({ url: rootImageUrl(sourcePath), name: "Фотография проекта " + (photoIndex + 1) }, cropProfiles.project, sourcePhoto).then(function (prepared) {
          if (!prepared || !state.currentDraft) return;
          prepared.alt = sourcePhoto.alt || projectDisplayTitle(state.currentDraft);
          state.currentDraft.photos[photoIndex] = prepared;
          if (state.currentDraft.cover === sourcePhoto.src) state.currentDraft.cover = prepared.src;
          renderProjectPhotos();
          toast("Кадры обложки обновлены. Нажмите «Сохранить проект в правках».", "success");
        }).catch(function (error) { toast(error.message, "error"); });
        return;
      }
      renderProjectPhotos();
      return;
    }

    var socialRow = target.closest(".social-row");
    if (socialRow && target.dataset.action) {
      var socialIndex = Number(socialRow.dataset.socialIndex);
      var socials = state.content.site.contacts.socials;
      var socialChanged = false;
      if (target.dataset.action === "social-up" && socialIndex > 0) {
        var previous = socials.splice(socialIndex, 1)[0];
        socials.splice(socialIndex - 1, 0, previous);
        socialChanged = true;
      }
      if (target.dataset.action === "social-down" && socialIndex < socials.length - 1) {
        var next = socials.splice(socialIndex, 1)[0];
        socials.splice(socialIndex + 1, 0, next);
        socialChanged = true;
      }
      if (target.dataset.action === "social-delete") {
        if (!window.confirm("Удалить эту ссылку из списка?")) return;
        socials.splice(socialIndex, 1);
        socialChanged = true;
      }
      if (!socialChanged) return;
      renderSocials();
      markDirty();
    }
  });

  if (dom.projectSearch) {
    dom.projectSearch.addEventListener("input", function () {
      state.projectQuery = dom.projectSearch.value || "";
      renderProjects();
    });
  }

  if (dom.projectFilter) {
    dom.projectFilter.addEventListener("change", function () {
      state.projectFilter = dom.projectFilter.value || "all";
      renderProjects();
    });
  }

  if (dom.discardChanges) dom.discardChanges.addEventListener("click", discardUnpublishedChanges);
  if (dom.confirmRestore) dom.confirmRestore.addEventListener("click", restoreSelectedHistory);

  if (dom.restoreDialog) {
    dom.restoreDialog.addEventListener("cancel", function (event) {
      event.preventDefault();
      closeRestoreDialog();
    });
  }

  if (dom.draftForm) {
    dom.draftForm.addEventListener("submit", function (event) {
      event.preventDefault();
      if (event.submitter && event.submitter.value === "cancel") {
        dom.draftDialog.close("cancel");
        return;
      }
      saveNamedDraft();
    });
  }

  if (dom.operationClose) dom.operationClose.addEventListener("click", closeOperation);

  if (dom.cropDialog) {
    dom.cropDialog.addEventListener("cancel", function (event) {
      event.preventDefault();
      cancelCropSession();
    });
  }

  document.querySelectorAll(".crop-cancel").forEach(function (button) {
    button.addEventListener("click", cancelCropSession);
  });

  if (dom.cropConfirm) dom.cropConfirm.addEventListener("click", prepareAndUploadCrop);

  if (dom.cropZoom) {
    dom.cropZoom.addEventListener("input", function () {
      var session = state.cropSession;
      var target = currentCropTarget();
      if (!session || !target || target.mode === "contain") return;
      session.states[target.key].zoom = Number(dom.cropZoom.value) / 100;
      session.states[target.key].visited = true;
      renderCropEditor();
    });
  }

  function rotateCrop(delta) {
    var session = state.cropSession;
    if (!session || session.processing) return;
    session.rotation = ((session.rotation + delta) % 360 + 360) % 360;
    Object.keys(session.states).forEach(function (key) {
      session.states[key].rotation = session.rotation;
      session.states[key].offsetX = 0;
      session.states[key].offsetY = 0;
    });
    renderCropEditor();
  }

  var rotateLeft = document.getElementById("cropRotateLeft");
  var rotateRight = document.getElementById("cropRotateRight");
  var cropReset = document.getElementById("cropReset");
  if (rotateLeft) rotateLeft.addEventListener("click", function () { rotateCrop(-90); });
  if (rotateRight) rotateRight.addEventListener("click", function () { rotateCrop(90); });
  if (cropReset) cropReset.addEventListener("click", function () {
    var session = state.cropSession;
    var target = currentCropTarget();
    if (!session || !target) return;
    var crop = session.states[target.key];
    crop.zoom = 1;
    crop.offsetX = 0;
    crop.offsetY = 0;
    crop.visited = true;
    renderCropEditor();
  });

  if (dom.cropCanvas && dom.cropStageWrap) {
    var dragState = null;
    dom.cropCanvas.addEventListener("pointerdown", function (event) {
      var session = state.cropSession;
      var target = currentCropTarget();
      if (!session || !target || target.mode === "contain" || session.processing) return;
      var crop = session.states[target.key];
      dragState = {
        pointerId: event.pointerId,
        x: event.clientX,
        y: event.clientY,
        offsetX: crop.offsetX,
        offsetY: crop.offsetY
      };
      dom.cropCanvas.setPointerCapture(event.pointerId);
      dom.cropStageWrap.classList.add("is-dragging");
      event.preventDefault();
    });
    dom.cropCanvas.addEventListener("pointermove", function (event) {
      if (!dragState || event.pointerId !== dragState.pointerId || !state.cropSession) return;
      var target = currentCropTarget();
      if (!target) return;
      var rect = dom.cropCanvas.getBoundingClientRect();
      var crop = state.cropSession.states[target.key];
      crop.offsetX = dragState.offsetX + (event.clientX - dragState.x) / Math.max(1, rect.width);
      crop.offsetY = dragState.offsetY + (event.clientY - dragState.y) / Math.max(1, rect.height);
      crop.visited = true;
      renderCropEditor();
    });
    function endCropDrag(event) {
      if (!dragState || (event && event.pointerId !== dragState.pointerId)) return;
      dragState = null;
      dom.cropStageWrap.classList.remove("is-dragging");
    }
    dom.cropCanvas.addEventListener("pointerup", endCropDrag);
    dom.cropCanvas.addEventListener("pointercancel", endCropDrag);
    dom.cropCanvas.addEventListener("wheel", function (event) {
      var session = state.cropSession;
      var target = currentCropTarget();
      if (!session || !target || target.mode === "contain" || session.processing) return;
      event.preventDefault();
      var crop = session.states[target.key];
      crop.zoom = Math.max(1, Math.min(4, crop.zoom + (event.deltaY < 0 ? 0.08 : -0.08)));
      crop.visited = true;
      renderCropEditor();
    }, { passive: false });
  }

  dom.projects.addEventListener("dragstart", function (event) {
    var card = event.target.closest(".project-admin-card");
    if (!card) return;
    state.draggedProjectId = card.dataset.projectId;
    card.classList.add("dragging");
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", state.draggedProjectId);
  });

  dom.projects.addEventListener("dragover", function (event) {
    var card = event.target.closest(".project-admin-card");
    if (!card || card.dataset.projectId === state.draggedProjectId) return;
    event.preventDefault();
    dom.projects.querySelectorAll(".drag-over").forEach(function (item) { item.classList.remove("drag-over"); });
    card.classList.add("drag-over");
  });

  dom.projects.addEventListener("drop", function (event) {
    var card = event.target.closest(".project-admin-card");
    event.preventDefault();
    if (!card || !state.draggedProjectId) return;
    var from = state.content.projects.findIndex(function (project) { return project.id === state.draggedProjectId; });
    var to = state.content.projects.findIndex(function (project) { return project.id === card.dataset.projectId; });
    moveProject(from, to);
  });

  dom.projects.addEventListener("dragend", function () {
    state.draggedProjectId = "";
    dom.projects.querySelectorAll(".dragging, .drag-over").forEach(function (item) {
      item.classList.remove("dragging", "drag-over");
    });
  });

  dom.projectPhotos.addEventListener("input", function (event) {
    var input = event.target.closest("[data-photo-field]");
    var card = event.target.closest(".photo-editor-card");
    if (!input || !card || !state.currentDraft) return;
    var photo = state.currentDraft.photos[Number(card.dataset.photoIndex)];
    if (photo) photo[input.dataset.photoField] = input.value;
  });

  dom.projectPhotoUpload.addEventListener("change", async function () {
    if (!state.currentDraft) return;
    var files = Array.from(dom.projectPhotoUpload.files || []);
    dom.projectPhotoUpload.value = "";
    if (!files.length) return;
    if (state.currentDraft.photos.length + files.length > 40) {
      toast("В одном проекте может быть не более 40 фотографий.", "error");
      return;
    }
    dom.projectUploadProgress.hidden = false;
    var uploadedCount = 0;
    try {
      for (var index = 0; index < files.length; index += 1) {
        dom.projectUploadProgress.textContent = "Фотография " + (index + 1) + " из " + files.length + ": настройте кадр для компьютера и телефона.";
        var photo = await openCropEditor(files[index], cropProfiles.project, null);
        if (!photo) continue;
        if (!photo.alt) photo.alt = projectDisplayTitle(state.currentDraft);
        state.currentDraft.photos.push(photo);
        if (!state.currentDraft.cover) state.currentDraft.cover = photo.src;
        uploadedCount += 1;
        renderProjectPhotos();
      }
      if (uploadedCount) {
        finishOperation(uploadedCount === 1 ? "Фотография готова" : "Все выбранные фотографии готовы", "Теперь нажмите «Сохранить проект в правках».");
        toast(uploadedCount === 1 ? "Фотография подготовлена и загружена." : "Подготовлено и загружено фотографий: " + uploadedCount + ".", "success");
      }
    } catch (error) {
      failOperation(error.message);
      toast(error.message, "error");
    } finally {
      dom.projectUploadProgress.hidden = true;
      dom.projectUploadProgress.textContent = "";
    }
  });

  dom.projectForm.addEventListener("submit", function (event) {
    event.preventDefault();
    if (event.submitter && event.submitter.value === "cancel") {
      state.currentDraft = null;
      state.currentDraftIndex = -1;
      dom.projectDialog.close("cancel");
      return;
    }
    saveProjectDraft();
  });

  dom.projectDialog.addEventListener("close", function () {
    state.currentDraft = null;
    state.currentDraftIndex = -1;
  });

  dom.media.addEventListener("input", function (event) {
    var input = event.target.closest("[data-media-field]");
    var group = event.target.closest("[data-media-group]");
    var item = event.target.closest("[data-media-index]");
    if (!input || !group || !item) return;
    state.content.site.media[group.dataset.mediaGroup][Number(item.dataset.mediaIndex)][input.dataset.mediaField] = input.value;
    markDirty();
  });

  dom.media.addEventListener("change", async function (event) {
    var addInput = event.target.closest("[data-media-add]");
    if (addInput) {
      var addFiles = Array.from(addInput.files || []);
      var addGroup = addInput.dataset.mediaAdd || "";
      addInput.value = "";
      await addMediaFiles(addGroup, addFiles);
      return;
    }
    var input = event.target.closest("[data-media-upload]");
    var group = event.target.closest("[data-media-group]");
    var item = event.target.closest("[data-media-index]");
    if (!input || !group || !item || !input.files || !input.files[0]) return;
    var file = input.files[0];
    input.value = "";
    var key = group.dataset.mediaGroup;
    var index = Number(item.dataset.mediaIndex);
    try {
      var photo = await openCropEditor(file, profileForMedia(key, index), null);
      if (!photo) return;
      var media = state.content.site.media[key][index];
      replaceMediaAsset(media, photo);
      renderMedia();
      updateMetrics();
      markDirty();
      finishOperation("Фотография раздела готова", "Откройте предпросмотр. Посетители пока видят старую версию.");
      toast("Фотография подготовлена. Проверьте предпросмотр, затем при готовности опубликуйте.", "success");
    } catch (error) {
      failOperation(error.message);
      toast(error.message, "error");
    }
  });

  dom.contacts.addEventListener("input", function (event) {
    if (event.target.closest("#socialsList")) return;
    syncContactFields();
  });

  dom.socials.addEventListener("input", function (event) {
    var input = event.target.closest("[data-social-field]");
    var row = event.target.closest(".social-row");
    if (!input || !row) return;
    var social = state.content.site.contacts.socials[Number(row.dataset.socialIndex)];
    if (!social) return;
    social[input.dataset.socialField] = input.type === "checkbox" ? input.checked : input.value;
    markDirty();
  });

  document.getElementById("addSocial").addEventListener("click", function () {
    if (state.content.site.contacts.socials.length >= 20) {
      toast("Можно добавить не более 20 ссылок.", "error");
      return;
    }
    var ids = new Set(state.content.site.contacts.socials.map(function (social) { return social.id; }));
    var number = 1;
    while (ids.has("social-" + number)) number += 1;
    state.content.site.contacts.socials.push({
      id: "social-" + number,
      label: "Новая ссылка",
      url: "https://",
      visible: false
    });
    renderSocials();
    markDirty();
    var last = dom.socials.querySelector(".social-row:last-child [data-social-field='label']");
    if (last) last.focus();
  });

  dom.passwordForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    if (!dom.passwordForm.reportValidity()) return;
    var data = new FormData(dom.passwordForm);
    var currentPassword = String(data.get("currentPassword") || "");
    var newPassword = String(data.get("newPassword") || "");
    var confirmPassword = String(data.get("confirmPassword") || "");
    if (newPassword !== confirmPassword) {
      toast("Новый пароль и его повтор не совпадают.", "error");
      return;
    }
    var submit = dom.passwordForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      var result = await apiPost("change-password", {
        currentPassword: currentPassword,
        newPassword: newPassword
      }, false);
      if (result.csrf) {
        state.csrf = result.csrf;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) csrfMeta.content = result.csrf;
        var logoutToken = document.querySelector('#logoutForm input[name="csrf"]');
        if (logoutToken) logoutToken.value = result.csrf;
      }
      dom.passwordForm.reset();
      toast(result.message || "Пароль изменён.", "success");
    } catch (error) {
      toast(error.message, "error");
    } finally {
      submit.disabled = false;
    }
  });

  if (dom.sidebarToggle) {
    dom.sidebarToggle.addEventListener("click", function () {
      setSidebarOpen(!dom.sidebar.classList.contains("open"));
    });
  }

  if (dom.sidebarBackdrop) {
    dom.sidebarBackdrop.addEventListener("click", function () {
      setSidebarOpen(false);
    });
  }

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && dom.sidebar && dom.sidebar.classList.contains("open")) {
      setSidebarOpen(false);
      if (dom.sidebarToggle) dom.sidebarToggle.focus();
    }
  });

  window.addEventListener("resize", function () {
    if (window.innerWidth > 780 && dom.sidebar && dom.sidebar.classList.contains("open")) {
      setSidebarOpen(false);
    }
  });

  window.addEventListener("hashchange", function () {
    switchSection(window.location.hash.replace(/^#/, "") || "dashboard", false);
  });

  window.addEventListener("beforeunload", function (event) {
    if (!state.dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  async function initialize() {
    try {
      var result = await apiGet();
      state.content = result.content;
      state.capabilities = result.capabilities || {};
      if (result.csrf) state.csrf = result.csrf;
      renderAll();
      if (dom.loading) dom.loading.hidden = true;
      switchSection(window.location.hash.replace(/^#/, "") || "dashboard", false);
      markSaved();
    } catch (error) {
      showFatal(error.message);
    }
  }

  initialize();
}());
