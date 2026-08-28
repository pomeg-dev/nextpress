/**
 * Nextpress page editor — entry button.
 *
 * Adds an "Edit visually" button to the normal Gutenberg header that opens the
 * page editor prototype (?np_spike=1). Runs only on the standard editor.
 */
(function () {
  if (!window.wp || !wp.domReady) return;

  wp.domReady(function () {
    var tries = 0;
    var timer = setInterval(function () {
      tries++;
      var host = document.querySelector('.editor-header__settings, .edit-post-header__settings');
      if (host && !document.getElementById('np-enter-btn')) {
        var url = new URL(location.href);
        url.searchParams.set('np_spike', '1');

        var a = document.createElement('a');
        a.id = 'np-enter-btn';
        a.textContent = 'Edit visually';
        a.href = url.toString();
        a.style.cssText =
          'display:inline-flex;align-items:center;margin-right:8px;padding:6px 12px;' +
          'background:#3858e9;color:#fff;border-radius:6px;font-weight:600;' +
          'text-decoration:none;font-size:13px;';
        host.insertBefore(a, host.firstChild);
        clearInterval(timer);
      }
      if (tries > 40) clearInterval(timer); // give up after ~10s
    }, 250);
  });
})();
