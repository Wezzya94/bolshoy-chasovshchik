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
    csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || "",
    dirty: false,
    saving: false,
    currentDraft: null,
    currentDraftIndex: -1,
    draggedProjectId: "",
    changeVersion: 0,
    projectQuery: "",
    projectFilter: "all"
  };

  var groupTitles = {
    hero: "Первый экран",
    directions: "Направления работы",
    master: "О мастере",
    workshop: "Мастерская",
    certificates: "Сертификаты"
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

  function projectNeedsAttention(project) {
    var photos = Array.isArray(project.photos) ? project.photos : [];
    if (!photos.length) return true;
    if (!String(project.coverAlt || "").trim()) return true;
    return photos.some(function (photo) { return !String(photo.alt || "").trim(); });
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

  async function apiGet(action) {
    var response = await fetch("api.php?action=" + encodeURIComponent(action || "content"), {
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
      if (projectNeedsAttention(project)) copy.appendChild(createElement("span", "status-badge attention", "Проверить фото"));
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
      [cover, left, right, remove].forEach(function (button) { actions.appendChild(button); });
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

  async function saveProjectDraft() {
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
    await publishChanges();
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

  function renderMedia() {
    if (!dom.media || !state.content) return;
    dom.media.replaceChildren();
    Object.keys(groupTitles).forEach(function (groupKey) {
      var items = state.content.site.media[groupKey] || [];
      var section = createElement("section", "media-group");
      section.dataset.mediaGroup = groupKey;
      var header = createElement("header", "media-group-header");
      header.appendChild(createElement("h2", "", groupTitles[groupKey]));
      header.appendChild(createElement("span", "", items.length + " фото"));
      section.appendChild(header);
      var grid = createElement("div", "media-items");

      items.forEach(function (item, index) {
        var card = createElement("article", "media-item");
        card.dataset.mediaIndex = String(index);
        var previewWrap = createElement("div", "media-preview-wrap");
        var preview = createElement("img", "media-preview");
        preview.src = rootImageUrl(item.src);
        preview.alt = item.alt || item.label;
        preview.loading = "lazy";
        previewWrap.appendChild(preview);
        var uploadLabel = createElement("label", "button button-secondary file-button", "Заменить фото");
        var upload = document.createElement("input");
        upload.type = "file";
        upload.accept = "image/jpeg,image/png,image/webp";
        upload.hidden = true;
        upload.dataset.mediaUpload = "true";
        uploadLabel.appendChild(upload);
        previewWrap.appendChild(uploadLabel);
        card.appendChild(previewWrap);

        var fields = createElement("div", "media-fields");
        fields.appendChild(createElement("h3", "", item.label));
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
        ? "Сначала опубликуйте или отмените текущие правки."
        : ((entry.changes || {}).hasChanges ? "Текущая версия сохранится автоматически." : "Эта версия совпадает с текущей.");
      footer.appendChild(createElement("small", "", hint));
      var restore = createButton("Посмотреть и восстановить", "button button-secondary", "history-restore");
      restore.dataset.historyId = entry.id;
      restore.disabled = state.dirty || !(entry.changes || {}).hasChanges;
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
    if (!dom.restoreDialog) return;
    if (typeof dom.restoreDialog.close === "function" && dom.restoreDialog.open) dom.restoreDialog.close();
    else dom.restoreDialog.removeAttribute("open");
  }

  function openRestoreDialog(historyId) {
    if (state.dirty) {
      toast("Сначала опубликуйте текущие правки или нажмите «Отменить правки».", "error");
      return;
    }
    var entry = state.history.find(function (item) { return item.id === historyId; });
    if (!entry || !(entry.changes || {}).hasChanges) return;
    state.selectedHistory = entry;
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
    state.changeVersion += 1;
    renderAll();
    markSaved(state.baselineContent);
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
    updateSaveButtons();
    try {
      var revision = Number(contentToSave.revision || 0);
      var result = await apiPost("save", { revision: revision, content: contentToSave }, false);
      if (state.changeVersion === versionAtSave) {
        state.content = result.content;
        markSaved(result.content);
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
      toast(state.dirty ? "Предыдущие изменения опубликованы; новые ещё ждут публикации." : (result.message || "Изменения опубликованы."), "success");
    } catch (error) {
      setSaveState("error", "Не удалось опубликовать");
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
    try {
      for (var index = 0; index < files.length; index += 1) {
        dom.projectUploadProgress.textContent = "Загружаю " + (index + 1) + " из " + files.length + ": " + files[index].name;
        var photo = await uploadImage(files[index]);
        if (!photo.alt) photo.alt = projectDisplayTitle(state.currentDraft);
        state.currentDraft.photos.push(photo);
        if (!state.currentDraft.cover) state.currentDraft.cover = photo.src;
        renderProjectPhotos();
      }
      toast(files.length === 1 ? "Фотография загружена." : "Фотографии загружены.", "success");
    } catch (error) {
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
    var input = event.target.closest("[data-media-upload]");
    var group = event.target.closest("[data-media-group]");
    var item = event.target.closest("[data-media-index]");
    if (!input || !group || !item || !input.files || !input.files[0]) return;
    var file = input.files[0];
    input.value = "";
    var key = group.dataset.mediaGroup;
    var index = Number(item.dataset.mediaIndex);
    try {
      toast("Загружаю «" + file.name + "»…");
      var photo = await uploadImage(file);
      var media = state.content.site.media[key][index];
      media.src = photo.src;
      media.width = photo.width || 0;
      media.height = photo.height || 0;
      renderMedia();
      updateMetrics();
      markDirty();
      toast("Фотография загружена. Нажмите «Опубликовать».", "success");
    } catch (error) {
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
      if (!state.capabilities.gd) {
        toast("На сервере не включён GD: изображения будут храниться без автоматической оптимизации.");
      }
    } catch (error) {
      showFatal(error.message);
    }
  }

  initialize();
}());
