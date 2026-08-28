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
  if (!cfg || !window.wp || !wp.data || !wp.blocks) {
    return;
  }

  var DEBOUNCE_MS = 350;
  var iframe, statusEl, saveBtn, titleEl, pushTimer;
  var lastPayload = '';
  var lastSelected = null;
  var pendingEditorScroll = null; // set when selection came from a canvas click

  // Viewport preview: render the iframe at the device's LOGICAL width (so the
  // site picks the right responsive layout) and CSS-scale it to fit the pane.
  var DEVICES = { desktop: 1440, tablet: 768, mobile: 380 };
  var device = 'desktop';
  var stageEl, sizerEl, frameEl, widthReadout, fitBtnEl;
  var zoomMode = 'fit';           // 'fit' (auto) | 'manual'
  var zoomLevel = 1;              // used in manual mode
  var ZOOM_MIN = 0.25, ZOOM_MAX = 2, ZOOM_STEP = 0.1;

  var bed = function () { return wp.data.select('core/block-editor'); };
  var bedDispatch = function () { return wp.data.dispatch('core/block-editor'); };
  var edSel = function () { return wp.data.select('core/editor'); };

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
      src: cfg.frontendUrl + '/page-preview/?post=' + encodeURIComponent(cfg.postId) });
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

  function fitScaleFor(dw) {
    var cs = getComputedStyle(stageEl);
    var padX = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight);
    return Math.min(1, (stageEl.clientWidth - padX) / dw);
  }

  function zoomBy(delta) {
    var dw = DEVICES[device] || DEVICES.desktop;
    var base = (zoomMode === 'fit') ? fitScaleFor(dw) : zoomLevel;
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
    var scale = (zoomMode === 'fit') ? Math.min(1, availW / dw) : zoomLevel;
    var frameH = Math.max(1, Math.round(availH / scale)); // fill pane height at this scale

    frameEl.style.width = dw + 'px';
    frameEl.style.height = frameH + 'px';
    frameEl.style.transform = 'scale(' + scale + ')';
    sizerEl.style.width = Math.round(dw * scale) + 'px';
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
    var cv = document.querySelector('iframe[name="editor-canvas"]');
    return (cv && cv.contentDocument) ? cv.contentDocument : document;
  }
  function editorBlockNode(clientId) {
    return editorDoc().querySelector('[data-block="' + clientId + '"]');
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
    } else if (data.type === 'np-select' && data.clientId) {
      pendingEditorScroll = data.clientId; // came from canvas → scroll the editor
      bedDispatch().selectBlock(data.clientId); // id === clientId, any depth
    }
  });

  // ------------------------------------------------------- ACF edit mode
  // ACF ignores programmatic attribute writes — its edit/preview view is driven
  // by its own state, flipped only by the toolbar toggle (aria-label "Edit
  // Block"). We click it for the selected block, but ONLY while it still shows
  // our preview label, so we never toggle a block's fields back off. Retries
  // because the toolbar/block render async after selection.
  function findEditToggle() {
    var sel = 'button[aria-label="Edit Block"], button[aria-label="Edit block"]';
    return document.querySelector(sel) || editorDoc().querySelector(sel);
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
    if (node && !node.querySelector('.np-editor-block-label')) return;
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

  wp.domReady(function () {
    buildShell();
    updateStatus();
    applyViewport();
    // Re-fit when the pane resizes (window resize, sidebar open, etc.).
    if (window.ResizeObserver && stageEl) new ResizeObserver(applyViewport).observe(stageEl);
    window.addEventListener('resize', applyViewport);
    // Attach the subscription FIRST so a later error can never detach it.
    wp.data.subscribe(onStoreChange);
  });
})();
