/**
 * Nextpress page editor (prototype) — tidied-native split.
 *
 * Piggybacks the native post.php editor (only when ?np_spike=1):
 *   - top strip: Exit + Save + save status
 *   - left: the REAL Gutenberg editor (noise hidden via CSS) — native inserter,
 *     ordering, grouping, ACF fields inline on select (mode: auto)
 *   - right: the live Next /page-preview canvas (click-to-select, live re-render)
 *
 * No custom block management, no field extraction — Gutenberg does the hard work.
 */
(function () {
  var cfg = window.NP_PREVIEW;
  if (!cfg) return; // not the preview editor page

  // --------------------------------------------------------------- WP adapter
  // Every coupling to WordPress/Gutenberg internals lives here, so a WP/ACF
  // version bump is a one-object patch instead of a codebase grep. Tiers:
  //   - stores: sanctioned public wp.data APIs (stable, low risk)
  //   - DOM/ARIA locators: fragile — note aria-labels are LOCALIZED
  //   - ourPreviewLabel: our own markup (from render_nextpress_block)
  // The boot() self-check validates the critical ones and fails LOUDLY (banner +
  // standard editor left intact) rather than silently half-working.
  var NP_WP = {
    stores: { blockEditor: 'core/block-editor', editor: 'core/editor' },
    // The block list moved into this iframe in WP 6.3+; we fall back to the top
    // document when it isn't present (see editorDoc).
    canvasIframe: 'iframe[name="editor-canvas"]',
    blockNode: function (id) { return '[data-block="' + id + '"]'; },
    ourPreviewLabel: '.np-editor-block-label',
    // ACF edit/preview toggle. aria-label is TRANSLATED, so no single string is
    // safe — try a chain (most stable / known-good first), first hit wins.
    // Entries are CSS selector strings or (doc) -> Element|null functions. If a
    // WP/ACF release renames the toggle, add a locator here.
    editToggleChain: [
      'button[aria-label="Edit Block"]',   // confirmed on our current WP/ACF
      'button[aria-label="Edit block"]',
      'button[aria-label="Edit"]',
      function (doc) {
        // Locale-tolerant fallback: scan block-toolbar buttons for an edit verb.
        var btns = doc.querySelectorAll(
          '.block-editor-block-toolbar button, .block-editor-block-contextual-toolbar button'
        );
        for (var i = 0; i < btns.length; i++) {
          var name = (btns[i].getAttribute('aria-label') || btns[i].textContent || '')
            .trim().toLowerCase();
          if (name === 'edit' || name.indexOf('edit ') === 0) return btns[i];
        }
        return null;
      }
    ]
  };

  var DEBOUNCE_MS = 350;
  var iframe, statusEl, saveBtn, titleEl, pushTimer;
  var lastPayload = '';
  var lastSelected = null;
  var pendingEditorScroll = null; // set when selection came from a canvas click

  // Viewport preview: render the iframe at the device's LOGICAL width (so the
  // site picks the right responsive layout) and CSS-scale it to fit the pane.
  var DEVICES = { desktop: 1440, tablet: 768, mobile: 380 };
  // Minimal device frames. Padding (device px, pre-scale) is set inline by JS so
  // the math and the CSS bezel share one source; the .cls draws the look. The
  // iframe keeps the exact DEVICES width so the site's media queries still fire.
  var FRAMES = {
    desktop: { cls: 'np-frame--desktop', top: 0,  right: 0,  bottom: 0,  left: 0 },
    tablet:  { cls: 'np-frame--tablet',  top: 20, right: 20, bottom: 20, left: 20 },
    mobile:  { cls: 'np-frame--mobile',  top: 40, right: 12, bottom: 40, left: 12 }
  };
  var device = 'desktop';
  var stageEl, sizerEl, frameEl, widthReadout, fitBtnEl;
  var zoomMode = 'fit';           // 'fit' (auto) | 'manual'
  var zoomLevel = 1;              // used in manual mode
  var ZOOM_MIN = 0.25, ZOOM_MAX = 2, ZOOM_STEP = 0.1;

  var bed = function () { return wp.data.select(NP_WP.stores.blockEditor); };
  var bedDispatch = function () { return wp.data.dispatch(NP_WP.stores.blockEditor); };
  var edSel = function () { return wp.data.select(NP_WP.stores.editor); };

  function el(tag, props, children) {
    var node = document.createElement(tag);
    if (props) {
      Object.keys(props).forEach(function (k) {
        if (k === 'class') node.className = props[k];
        else if (k === 'text') node.textContent = props[k];
        else if (k.indexOf('on') === 0 && typeof props[k] === 'function') node.addEventListener(k.slice(2), props[k]);
        else if (props[k] != null) node.setAttribute(k, props[k]);
      });
    }
    (children || []).forEach(function (c) { if (c) node.appendChild(c); });
    return node;
  }

  function editedTitle() {
    try { return edSel().getEditedPostAttribute('title') || '(untitled)'; } catch (e) { return '(untitled)'; }
  }

  // ------------------------------------------------------------------- shell
  function buildShell() {
    document.documentElement.classList.add('np-editor-active');

    var exit = el('button', { class: 'np-exit', text: '← Exit', onclick: doExit });
    titleEl = el('div', { class: 'np-title', text: editedTitle() });
    statusEl = el('div', { id: 'np-status', text: '' });
    saveBtn = el('button', { class: 'np-save', text: 'Save', onclick: doSave });
    var strip = el('div', { id: 'np-strip' }, [
      exit, titleEl, el('div', { class: 'np-spacer' }), statusEl, saveBtn
    ]);

    iframe = el('iframe', { id: 'np-preview-iframe',
      src: cfg.frontendUrl + '/page-preview/?post=' + encodeURIComponent(cfg.postId)
        + '&np_token=' + encodeURIComponent(cfg.previewToken || '') });
    frameEl = el('div', { id: 'np-canvas-frame' }, [iframe]);
    sizerEl = el('div', { id: 'np-canvas-sizer' }, [frameEl]);
    stageEl = el('div', { id: 'np-canvas-stage' }, [sizerEl]);
    var wrap = el('div', { id: 'np-canvas-wrap' }, [buildViewportBar(), stageEl]);

    restoreSplit();
    document.body.appendChild(strip);
    document.body.appendChild(wrap);
    document.body.appendChild(buildResizeHandle());
  }

  // ------------------------------------------------------- split resize
  var SPLIT_KEY = 'npEditW';
  function restoreSplit() {
    try {
      var w = localStorage.getItem(SPLIT_KEY);
      if (w) document.documentElement.style.setProperty('--np-edit-w', w);
    } catch (e) {}
  }

  function buildResizeHandle() {
    var handle = el('div', { id: 'np-resize-handle', title: 'Drag to resize' });
    handle.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      // Pointer capture keeps events coming to the handle even while the cursor
      // is over the (cross-origin) iframes — otherwise the drag would stall.
      try { handle.setPointerCapture(e.pointerId); } catch (er) {}
      document.documentElement.classList.add('np-resizing');

      function onMove(ev) {
        var x = Math.max(320, Math.min(window.innerWidth - 360, ev.clientX));
        document.documentElement.style.setProperty('--np-edit-w', x + 'px');
        applyViewport();
      }
      function onUp() {
        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', onUp);
        document.documentElement.classList.remove('np-resizing');
        try {
          localStorage.setItem(SPLIT_KEY,
            getComputedStyle(document.documentElement).getPropertyValue('--np-edit-w').trim());
        } catch (er) {}
        applyViewport();
      }
      handle.addEventListener('pointermove', onMove);
      handle.addEventListener('pointerup', onUp);
    });
    return handle;
  }

  function buildViewportBar() {
    var devBtns = ['desktop', 'tablet', 'mobile'].map(function (d) {
      return el('button', {
        class: 'np-vp' + (d === device ? ' is-active' : ''), 'data-vp': d,
        text: d.charAt(0).toUpperCase() + d.slice(1),
        onclick: function () { setDevice(d); }
      });
    });
    var zoomOut = el('button', { class: 'np-vp np-zoom', text: '−', title: 'Zoom out', onclick: function () { zoomBy(-ZOOM_STEP); } });
    var zoomIn = el('button', { class: 'np-vp np-zoom', text: '+', title: 'Zoom in', onclick: function () { zoomBy(ZOOM_STEP); } });
    fitBtnEl = el('button', { class: 'np-vp', text: 'Fit', title: 'Fit to width', onclick: setFit });
    widthReadout = el('span', { class: 'np-vp-width' });
    return el('div', { id: 'np-canvas-toolbar' }, devBtns.concat([
      el('span', { class: 'np-vp-spacer' }),
      zoomOut, widthReadout, zoomIn, fitBtnEl
    ]));
  }

  function setDevice(d) {
    device = d;
    zoomMode = 'fit'; // re-fit for the newly chosen device
    var bar = document.getElementById('np-canvas-toolbar');
    if (bar) bar.querySelectorAll('.np-vp[data-vp]').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-vp') === d);
    });
    applyViewport();
  }

  // Outer width = device width + side bezels (what actually has to fit the pane).
  function frameOuterW(dev) {
    var f = FRAMES[dev] || FRAMES.desktop;
    return (DEVICES[dev] || DEVICES.desktop) + f.left + f.right;
  }
  function fitScaleFor(dev) {
    var cs = getComputedStyle(stageEl);
    var padX = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight);
    return Math.min(1, (stageEl.clientWidth - padX) / frameOuterW(dev));
  }

  function zoomBy(delta) {
    var base = (zoomMode === 'fit') ? fitScaleFor(device) : zoomLevel;
    zoomLevel = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Math.round((base + delta) * 20) / 20));
    zoomMode = 'manual';
    applyViewport();
  }

  function setFit() {
    zoomMode = 'fit';
    applyViewport();
  }

  // Render at the device width; the iframe gets a real viewport of
  // DEVICES[device] px so the site's media queries fire. transform:scale only
  // affects display. Fit = scale-down to pane width; manual = zoomLevel (may
  // exceed the pane → the stage scrolls to pan).
  function applyViewport() {
    if (!stageEl || !frameEl || !sizerEl) return;
    var cs = getComputedStyle(stageEl);
    var padX = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight);
    var padY = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom);
    var availW = stageEl.clientWidth - padX;
    var availH = stageEl.clientHeight - padY;
    var dw = DEVICES[device] || DEVICES.desktop;
    var f = FRAMES[device] || FRAMES.desktop;
    var outerW = dw + f.left + f.right;
    var scale = (zoomMode === 'fit') ? Math.min(1, availW / outerW) : zoomLevel;
    var outerH = Math.max(1, Math.round(availH / scale)); // fill pane height at this scale

    // Device look + bezel (border-box, so the iframe content box stays dw wide).
    frameEl.classList.remove('np-frame--desktop', 'np-frame--tablet', 'np-frame--mobile');
    frameEl.classList.add(f.cls);
    frameEl.style.padding = f.top + 'px ' + f.right + 'px ' + f.bottom + 'px ' + f.left + 'px';

    frameEl.style.width = outerW + 'px';
    frameEl.style.height = outerH + 'px';
    frameEl.style.transform = 'scale(' + scale + ')';
    sizerEl.style.width = Math.round(outerW * scale) + 'px';
    sizerEl.style.height = availH + 'px';
    if (widthReadout) widthReadout.textContent = Math.round(scale * 100) + '%';
    if (fitBtnEl) fitBtnEl.classList.toggle('is-active', zoomMode === 'fit');
  }

  function doExit() {
    var u = new URL(location.href);
    u.searchParams.delete('np_spike');
    location.href = u.toString();
  }

  function doSave() {
    wp.data.dispatch('core/editor').savePost();
  }

  function updateStatus() {
    var s = edSel(), text = 'All changes saved', disabled = true;
    try {
      if (s.isSavingPost()) { text = 'Saving…'; disabled = true; }
      else if (s.isEditedPostDirty()) { text = 'Unsaved changes'; disabled = false; }
    } catch (e) {}
    if (statusEl) statusEl.textContent = text;
    if (saveBtn) saveBtn.disabled = disabled;
    // Title isn't in the store yet at domReady; refresh it as the store settles.
    if (titleEl) titleEl.textContent = editedTitle();
  }

  // -------------------------------------------------------------- canvas bridge
  // Serialize current editor state to post_content markup (native save format),
  // stamping anchor=clientId at every depth so canvas ids === clientIds.
  function serializeContent() {
    function anchored(b) {
      // Normalise mode in the payload so selecting a block (which flips its mode)
      // never changes the serialized content → no spurious canvas re-render.
      return wp.blocks.cloneBlock(
        b, { anchor: b.clientId, mode: 'preview' }, (b.innerBlocks || []).map(anchored)
      );
    }
    return wp.blocks.serialize(bed().getBlocks().map(anchored));
  }

  function pushBlocks() {
    if (!iframe || !iframe.contentWindow) return;
    var content = serializeContent();
    if (content === lastPayload) return;
    lastPayload = content;

    fetch(cfg.restUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: JSON.stringify({ content: content }),
    })
      .then(function (res) { return res.json(); })
      .then(function (formatted) {
        iframe.contentWindow.postMessage(
          { type: 'np-blocks', post: cfg.postId, blocks: formatted },
          cfg.frontendOrigin
        );
      })
      .catch(function (err) { console.error('[np-editor] format failed', err); });
  }

  function scrollCanvasTo(clientId) {
    if (iframe && iframe.contentWindow) {
      iframe.contentWindow.postMessage({ type: 'np-scroll-to', clientId: clientId }, cfg.frontendOrigin);
    }
  }

  // The block list renders inside the editor-canvas iframe on modern WP; fall
  // back to the top document otherwise.
  function editorDoc() {
    var cv = document.querySelector(NP_WP.canvasIframe);
    return (cv && cv.contentDocument) ? cv.contentDocument : document;
  }
  function editorBlockNode(clientId) {
    return editorDoc().querySelector(NP_WP.blockNode(clientId));
  }

  // Scroll the LEFT Gutenberg pane to a block. Uses INSTANT scroll (not smooth):
  // a canvas click moves focus into the Next iframe, and browsers defer smooth
  // scrollIntoView in an unfocused document — which is why it only worked after
  // first clicking the editor directly. Instant scroll isn't throttled. Repeats
  // over a short window to also absorb the edit-mode re-render/height change.
  function scrollEditorTo(clientId) {
    [0, 120, 320, 600].forEach(function (delay) {
      setTimeout(function () {
        var node = editorBlockNode(clientId);
        if (node && node.scrollIntoView) node.scrollIntoView({ block: 'center', inline: 'nearest' });
      }, delay);
    });
  }

  window.addEventListener('message', function (event) {
    if (event.origin !== cfg.frontendOrigin) return;
    var data = event.data || {};
    if (data.type === 'np-ready') {
      lastPayload = '';
      pushBlocks();
    } else if (data.type === 'np-rendered') {
      fadeBootLoader(); // canvas has painted real content → reveal the shell
    } else if (data.type === 'np-select' && data.clientId) {
      pendingEditorScroll = data.clientId; // came from canvas → scroll the editor
      bedDispatch().selectBlock(data.clientId); // id === clientId, any depth
    }
  });

  // Fade + remove the server-painted boot loader (see page-editor.css).
  function fadeBootLoader() {
    var b = document.body;
    if (!b.classList.contains('np-editor-booting') || b.classList.contains('np-boot-done')) return;
    b.classList.add('np-boot-done');
    setTimeout(function () { b.classList.remove('np-editor-booting', 'np-boot-done'); }, 400);
  }

  // ------------------------------------------------------- ACF edit mode
  // ACF ignores programmatic attribute writes — its edit/preview view is driven
  // by its own state, flipped only by the toolbar toggle. That toggle's
  // aria-label is LOCALIZED, so we resolve it through NP_WP.editToggleChain (a
  // priority list of locators, first hit wins) across both the top document and
  // the canvas iframe. We click it for the selected block, but ONLY while it
  // still shows our preview label, so we never toggle a block's fields back off.
  // Retries because the toolbar/block render async after selection.
  function findEditToggle() {
    var docs = [document, editorDoc()];
    for (var i = 0; i < NP_WP.editToggleChain.length; i++) {
      var entry = NP_WP.editToggleChain[i];
      for (var d = 0; d < docs.length; d++) {
        if (!docs[d]) continue;
        var hit = (typeof entry === 'function') ? entry(docs[d]) : docs[d].querySelector(entry);
        if (hit) return hit;
      }
    }
    return null;
  }

  function forceEditMode(attempt) {
    attempt = attempt || 0;
    var clientId = bed().getSelectedBlockClientId();
    if (!clientId) return;
    var node = editorBlockNode(clientId);
    // Done only once the SELECTED block shows its fields (no preview label).
    // Don't stop after a single click: right after selection the toolbar can
    // still be the previous block's, so clicking then would edit the wrong
    // (previous) block. Click only when the selected block's own toolbar is
    // active (is-selected); fall back to clicking anyway after a few tries.
    if (node && !node.querySelector(NP_WP.ourPreviewLabel)) return;
    if (node && (node.classList.contains('is-selected') || attempt >= 3)) {
      var btn = findEditToggle();
      if (btn) btn.click();
    }
    if (attempt < 15) setTimeout(function () { forceEditMode(attempt + 1); }, 80);
  }

  // --------------------------------------------------------------- store loop
  function onStoreChange() {
    clearTimeout(pushTimer);
    pushTimer = setTimeout(pushBlocks, DEBOUNCE_MS);

    var selected = bed().getSelectedBlockClientId();
    if (selected !== lastSelected) {
      lastSelected = selected;
      if (selected) {
        forceEditMode(); // reveal ACF fields for the newly selected block
        // Clicking one pane scrolls the OTHER into view (both retry internally).
        if (pendingEditorScroll === selected) {
          scrollEditorTo(selected);
          pendingEditorScroll = null;
        } else {
          scrollCanvasTo(selected);
        }
      }
    }

    updateStatus();
  }

  // --------------------------------------------------------------- compat + boot
  // Fail LOUD, not silent. The old failure mode was a locator returning null and
  // a feature quietly dying. Instead: verify the critical WP internals up front;
  // if one is missing, show a banner and leave the STANDARD editor usable (we
  // never add .np-editor-active, so nothing gets stripped) rather than shipping a
  // broken half-experience.
  function compatIssues() {
    var out = [];
    if (!window.wp || !wp.data || !wp.data.select) { out.push('wp.data unavailable'); return out; }
    if (!wp.data.select(NP_WP.stores.blockEditor)) out.push('block-editor store "' + NP_WP.stores.blockEditor + '" missing');
    if (!wp.data.select(NP_WP.stores.editor)) out.push('editor store "' + NP_WP.stores.editor + '" missing');
    if (!wp.blocks || !wp.blocks.serialize || !wp.blocks.cloneBlock) out.push('wp.blocks.serialize/cloneBlock missing');
    return out;
  }

  function showCompatBanner(lines, fatal) {
    if (document.getElementById('np-compat-banner')) return;
    var ver = cfg.wpVersion ? ' (WordPress ' + cfg.wpVersion + ')' : '';
    var msg = fatal
      ? 'Live preview isn’t available on this setup' + ver + ' — you can keep editing in the standard editor.'
      : 'Live preview loaded with issues' + ver + ' — some features may not work.';
    var banner = el('div', { id: 'np-compat-banner', class: fatal ? 'is-fatal' : '' }, [
      el('span', { class: 'np-compat-msg', text: '⚠ ' + msg }),
      el('button', { class: 'np-compat-detail', text: 'Details', title: lines.join('\n'),
        onclick: function () { window.alert(lines.join('\n')); } }),
      el('button', { class: 'np-compat-dismiss', text: '✕', title: 'Dismiss',
        onclick: function () { banner.remove(); } })
    ]);
    document.body.appendChild(banner);
    console.error('[np-editor] compatibility issue(s):', lines);
  }

  // The canvas + block nodes render async; retry before warning (non-fatal — the
  // core preview may still work, only click-to-select depends on this).
  function checkEditorReachable(attempt) {
    attempt = attempt || 0;
    var cv = document.querySelector(NP_WP.canvasIframe);
    if ((cv && cv.contentDocument && cv.contentDocument.querySelector('[data-block]')) ||
        document.querySelector('[data-block]')) return; // found
    if (attempt < 40) { setTimeout(function () { checkEditorReachable(attempt + 1); }, 150); return; }
    showCompatBanner(['Editor canvas / block nodes not found — selecting a block may not work. ' +
      'The "' + NP_WP.canvasIframe + '" locator may be out of date for this WP version.'], false);
  }

  function boot() {
    var issues = compatIssues();
    if (issues.length) {
      showCompatBanner(issues, true); // standard editor left intact
      fadeBootLoader();               // reveal it (loader was covering the flash)
      return;
    }

    buildShell();
    updateStatus();
    applyViewport();
    // Re-fit when the pane resizes (window resize, sidebar open, etc.).
    if (window.ResizeObserver && stageEl) new ResizeObserver(applyViewport).observe(stageEl);
    window.addEventListener('resize', applyViewport);
    // Attach the subscription FIRST so a later error can never detach it.
    wp.data.subscribe(onStoreChange);
    checkEditorReachable();
    // Primary reveal is the canvas's np-rendered message; this is the safety net
    // if it never arrives (empty page, canvas error).
    setTimeout(fadeBootLoader, 8000);
  }

  if (window.wp && wp.domReady) wp.domReady(boot);
  else document.addEventListener('DOMContentLoaded', boot); // wp missing → still banner
})();
