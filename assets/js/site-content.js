(function () {
  'use strict';

  var CONTENT_URL = window.TV_CONTENT_URL || 'content/site.json';

  function stringValue(value) {
    return typeof value === 'string' ? value.trim() : '';
  }

  function sortedProjects(projects) {
    return (Array.isArray(projects) ? projects : [])
      .filter(function (project) { return project && project.visible !== false; })
      .sort(function (left, right) { return Number(left.order || 0) - Number(right.order || 0); });
  }

  function photoSource(photo) {
    return typeof photo === 'string' ? photo : stringValue(photo && photo.src);
  }

  function photoThumb(photo) {
    if (typeof photo === 'object' && photo) return stringValue(photo.thumb) || photoSource(photo);
    return photoSource(photo).replace('img/projects/', 'img/projects/thumbs/').replace(/\.jpe?g$/i, '.webp');
  }

  function variantsOf(asset) {
    return asset && Array.isArray(asset.variants) ? asset.variants.filter(function (variant) {
      return variant && stringValue(variant.src) && Number(variant.width) > 0;
    }) : [];
  }

  function variantSrcset(asset) {
    return variantsOf(asset).map(function (variant) {
      return stringValue(variant.src) + ' ' + Number(variant.width) + 'w';
    }).join(', ');
  }

  function ensurePicture(image) {
    if (!image || !image.parentNode) return null;
    if (image.parentElement && image.parentElement.tagName === 'PICTURE') return image.parentElement;
    var picture = document.createElement('picture');
    picture.className = 'tv-responsive-picture';
    picture.style.display = 'contents';
    image.parentNode.insertBefore(picture, image);
    picture.appendChild(image);
    return picture;
  }

  function applyResponsiveImage(image, desktopAsset, mobileAsset, sizes) {
    if (!image || !desktopAsset || !stringValue(desktopAsset.src)) return;
    var desktopSet = variantSrcset(desktopAsset);
    image.src = stringValue(desktopAsset.src);
    if (desktopSet) image.srcset = desktopSet;
    else image.removeAttribute('srcset');
    image.sizes = sizes || '100vw';
    if (Number(desktopAsset.width) > 0) image.width = Number(desktopAsset.width);
    if (Number(desktopAsset.height) > 0) image.height = Number(desktopAsset.height);

    var picture = ensurePicture(image);
    if (!picture) return;
    picture.querySelectorAll('source[data-tv-responsive]').forEach(function (source) { source.remove(); });
    if (mobileAsset && stringValue(mobileAsset.src)) {
      var source = document.createElement('source');
      source.dataset.tvResponsive = 'mobile';
      source.media = '(max-width: 680px)';
      source.srcset = variantSrcset(mobileAsset) || stringValue(mobileAsset.src);
      source.sizes = sizes || '100vw';
      picture.insertBefore(source, image);
    }
  }

  function appendProjectTitle(element, project) {
    element.textContent = '';
    element.appendChild(document.createTextNode(stringValue(project.title)));
    if (stringValue(project.accent)) {
      element.appendChild(document.createTextNode(' '));
      var emphasis = document.createElement('em');
      emphasis.textContent = stringValue(project.accent);
      element.appendChild(emphasis);
    }
  }

  function createProjectCard(project) {
    var photos = Array.isArray(project.photos) ? project.photos : [];
    var coverPhoto = photos.find(function (photo) { return photoSource(photo) === project.cover; }) || photos[0] || null;
    var cover = stringValue(project.cover) || photoSource(coverPhoto);

    var article = document.createElement('article');
    article.className = 'proj-card reveal proj-reveal in';
    article.setAttribute('data-project', stringValue(project.id || project.slug));

    var photo = document.createElement('div');
    photo.className = 'photo proj-photo';
    var image = document.createElement('img');
    image.src = cover;
    image.width = Number(coverPhoto && coverPhoto.width) || 1280;
    image.height = Number(coverPhoto && coverPhoto.height) || 853;
    image.loading = 'lazy';
    image.decoding = 'async';
    image.alt = stringValue(project.coverAlt) || stringValue(coverPhoto && coverPhoto.alt) || [project.title, project.accent].filter(Boolean).join(' ');
    photo.appendChild(image);
    var cardAsset = coverPhoto && coverPhoto.card ? coverPhoto.card : coverPhoto;
    var mobileAsset = coverPhoto && coverPhoto.mobile ? coverPhoto.mobile : null;
    if (cardAsset && stringValue(cardAsset.src)) {
      applyResponsiveImage(image, cardAsset, mobileAsset, '(max-width: 680px) calc(100vw - 32px), (max-width: 1180px) 50vw, 560px');
    }
    ['grain', 'vignette'].forEach(function (className) {
      var decoration = document.createElement('div');
      decoration.className = className;
      photo.appendChild(decoration);
    });
    ['tl', 'tr', 'bl', 'br'].forEach(function (position) {
      var corner = document.createElement('div');
      corner.className = 'corner ' + position;
      photo.appendChild(corner);
    });
    var count = document.createElement('div');
    count.className = 'proj-count';
    count.textContent = photos.length + ' ' + (photos.length === 1 ? 'кадр' : (photos.length > 1 && photos.length < 5 ? 'кадра' : 'кадров'));
    photo.appendChild(count);

    var body = document.createElement('div');
    body.className = 'proj-body';
    var type = document.createElement('div');
    type.className = 'proj-type';
    type.textContent = stringValue(project.type);
    var heading = document.createElement('h3');
    heading.className = 'proj-name';
    appendProjectTitle(heading, project);
    var lead = document.createElement('div');
    lead.className = 'proj-lead';
    lead.textContent = stringValue(project.cardLead || project.lead);
    var button = document.createElement('button');
    button.className = 'proj-open';
    button.type = 'button';
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-label', 'Смотреть проект: ' + heading.textContent.trim());
    button.appendChild(document.createTextNode('Смотреть проект '));
    var arrow = document.createElement('span');
    arrow.className = 'arrow';
    arrow.textContent = '→';
    button.appendChild(arrow);
    body.append(type, heading, lead, button);
    article.append(photo, body);
    return article;
  }

  function applyProjects(data) {
    var grid = document.getElementById('projectsGrid');
    if (!grid) return;
    var projects = sortedProjects(data.projects);
    var runtimeProjects = {};
    var fragment = document.createDocumentFragment();

    projects.forEach(function (project) {
      var id = stringValue(project.id || project.slug);
      var photos = (Array.isArray(project.photos) ? project.photos : []).filter(function (photo) { return !!photoSource(photo); });
      if (!id || !photos.length) return;
      runtimeProjects[id] = {
        title: stringValue(project.title),
        accent: stringValue(project.accent),
        type: stringValue(project.modalType || project.type),
        lead: stringValue(project.lead || project.cardLead),
        body: (Array.isArray(project.body) ? project.body : []).map(stringValue).filter(Boolean),
        specs: (Array.isArray(project.specs) ? project.specs : []).map(function (spec) {
          return [stringValue(spec && spec.label), stringValue(spec && spec.value)];
        }).filter(function (spec) { return spec[0] || spec[1]; }),
        photos: photos.map(function (photo) {
          return {
            src: photoSource(photo),
            thumb: photoThumb(photo),
            alt: stringValue(photo && photo.alt),
            width: Number(photo && photo.width) || 0,
            height: Number(photo && photo.height) || 0,
            variants: variantsOf(photo),
            card: photo && photo.card ? photo.card : null,
            mobile: photo && photo.mobile ? photo.mobile : null,
          };
        }),
      };
      fragment.appendChild(createProjectCard(project));
    });

    grid.replaceChildren(fragment);
    window.PROJECTS = runtimeProjects;
    if (typeof window.refreshProjectGrid === 'function') window.refreshProjectGrid(true);
  }

  function applyMediaItem(image, item) {
    if (!image || !item || !stringValue(item.src)) return;
    applyResponsiveImage(image, item, item.mobile || null, '100vw');
    image.removeAttribute('data-src');
    image.alt = stringValue(item.alt);
    if (Number(item.width) > 0) image.width = Number(item.width);
    if (Number(item.height) > 0) image.height = Number(item.height);
    if (stringValue(item.caption)) image.setAttribute('data-caption', item.caption);
  }

  function applyMedia(data) {
    var media = data.site && data.site.media;
    if (!media) return;

    var groups = [
      { selector: '#hero .hero-slide', items: media.hero },
      { selector: '#directions img', items: media.directions },
      { selector: '#master img', items: media.master },
      { selector: '#certificatesModal .cert-slide img', items: media.certificates },
    ];
    groups.forEach(function (group) {
      var images = document.querySelectorAll(group.selector);
      (Array.isArray(group.items) ? group.items : []).forEach(function (item, index) {
        applyMediaItem(images[index], item);
      });
    });

    var workshopImages = document.querySelectorAll('#workshop .workshop-grid img');
    (Array.isArray(media.workshop) ? media.workshop : []).forEach(function (item, index) {
      var image = workshopImages[index];
      if (!image) return;
      applyMediaItem(image, item);
      var button = image.closest('button');
      var label = button && button.querySelector('.photo-label');
      if (label && stringValue(item.caption)) label.textContent = item.caption;
      if (button) button.setAttribute('aria-label', 'Открыть фотографию: ' + (stringValue(item.caption) || stringValue(item.alt)));
    });

    var activeHero = document.querySelector('#hero .hero-slide.active');
    var heroCaption = document.getElementById('heroPhotoCaption');
    if (activeHero && heroCaption && activeHero.getAttribute('data-caption')) {
      heroCaption.textContent = activeHero.getAttribute('data-caption');
    }
  }

  function setHref(selector, href) {
    if (!stringValue(href)) return;
    document.querySelectorAll(selector).forEach(function (link) { link.href = href; });
  }

  function applyContacts(data) {
    var contacts = data.site && data.site.contacts;
    if (!contacts) return;
    setHref('.mobile-nav-primary, #contacts .contact-actions .btn-gold, .footer-contact a[href*="t.me"]', contacts.telegramUrl);
    setHref('.mobile-nav-secondary, #contacts .contact-actions .btn-outline, .footer-contact a[href*="wa.me"]', contacts.whatsappUrl);

    var city = stringValue(contacts.city);
    var serviceArea = stringValue(contacts.serviceArea);
    var place = document.querySelector('.contact-place');
    if (place) place.textContent = [city, serviceArea].filter(Boolean).join(' · ');
    var mobilePlace = document.querySelector('.mobile-nav-place');
    if (mobilePlace) mobilePlace.textContent = [city, 'мастерская'].filter(Boolean).join(' · ');
    var footerTagline = document.querySelector('.footer-tagline');
    if (footerTagline && city) footerTagline.textContent = 'Мастерская антикварных часов «Твоё Время» · ' + city;
    var footerDescription = document.querySelector('.footer-contact p');
    if (footerDescription) footerDescription.textContent = [city, serviceArea ? serviceArea.charAt(0).toUpperCase() + serviceArea.slice(1) + ' после предварительного диалога.' : ''].filter(Boolean).join(' · ');
    var footerTelegram = document.querySelector('.footer-contact a[href*="t.me"]');
    if (footerTelegram) footerTelegram.textContent = 'Telegram · ' + (stringValue(contacts.telegramLabel) || stringValue(contacts.telegramUrl));
    var footerWhatsapp = document.querySelector('.footer-contact a[href*="wa.me"]');
    if (footerWhatsapp) footerWhatsapp.textContent = 'WhatsApp · ' + stringValue(contacts.phoneDisplay);

    var socialContainer = document.querySelector('#contacts .social-links');
    if (socialContainer) {
      var label = document.createElement('span');
      label.className = 'slbl';
      label.textContent = '— Площадки мастерской —';
      var fragment = document.createDocumentFragment();
      fragment.appendChild(label);
      (Array.isArray(contacts.socials) ? contacts.socials : []).filter(function (social) {
        return social && social.visible !== false && stringValue(social.label) && stringValue(social.url);
      }).forEach(function (social) {
        var link = document.createElement('a');
        link.href = social.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = social.label;
        fragment.appendChild(link);
      });
      socialContainer.replaceChildren(fragment);
    }

    var structuredData = document.querySelector('script[type="application/ld+json"]');
    if (structuredData) {
      try {
        var schema = JSON.parse(structuredData.textContent);
        schema.telephone = stringValue(contacts.phoneE164 || contacts.phoneDisplay);
        if (schema.address && city) schema.address.addressLocality = city;
        schema.sameAs = (Array.isArray(contacts.socials) ? contacts.socials : [])
          .filter(function (social) { return social && social.visible !== false && stringValue(social.url); })
          .map(function (social) { return social.url; });
        structuredData.textContent = JSON.stringify(schema);
      } catch (error) {
        console.warn('Не удалось обновить структурированные контактные данные.', error);
      }
    }
  }

  function applyContent(data) {
    if (!data || Number(data.schemaVersion) !== 1) throw new Error('Unsupported content schema');
    applyProjects(data);
    applyMedia(data);
    applyContacts(data);
    document.documentElement.setAttribute('data-content-revision', String(data.revision || 0));
    window.dispatchEvent(new CustomEvent('sitecontentready', { detail: { revision: data.revision || 0 } }));
    return data;
  }

  var contentPromise = window.TV_PREVIEW_CONTENT
    ? Promise.resolve(window.TV_PREVIEW_CONTENT)
    : fetch(CONTENT_URL, { cache: 'no-store', credentials: 'same-origin' }).then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      });

  window.siteContentReady = contentPromise
    .then(applyContent)
    .catch(function (error) {
      console.warn('Динамический контент недоступен — показана встроенная резервная версия.', error);
      return null;
    });
})();
