(function () {
  function initNavigation() {
    const nav = document.querySelector('.sidebar-nav');
    if (!nav) return;

    const menuLinks = nav.querySelectorAll(':scope > a');
    const settings = nav.querySelector('.settings-menu');
    const settingsSummary = settings ? settings.querySelector('.sidebar-group-title') : null;
    const settingsSubmenu = settings ? settings.querySelector('.sidebar-submenu') : null;

    menuLinks.forEach(function (link) {
      link.addEventListener('mouseenter', function () {
        this.classList.add('menu-hover');
      });
      link.addEventListener('mouseleave', function () {
        this.classList.remove('menu-hover');
      });
      link.addEventListener('focus', function () {
        this.classList.add('menu-hover');
      });
      link.addEventListener('blur', function () {
        this.classList.remove('menu-hover');
      });
    });

    if (settings && settingsSummary && settingsSubmenu) {
      let closeTimer;

      function openSettings() {
        clearTimeout(closeTimer);
        settings.open = true;
        settings.classList.add('js-hover-open');
        settingsSubmenu.setAttribute('aria-hidden', 'false');
      }

      function closeSettings(delay) {
        clearTimeout(closeTimer);
        closeTimer = setTimeout(function () {
          settings.open = false;
          settings.classList.remove('js-hover-open');
          settingsSubmenu.setAttribute('aria-hidden', 'true');
        }, delay || 0);
      }

      // Settings opens only while hovering/focusing the Settings control.
      settingsSummary.addEventListener('mouseenter', openSettings);
      settingsSubmenu.addEventListener('mouseenter', openSettings);
      settings.addEventListener('mouseleave', function () {
        closeSettings(180);
      });
      settingsSummary.addEventListener('focus', openSettings);
      settings.addEventListener('focusout', function (event) {
        if (!settings.contains(event.relatedTarget)) {
          closeSettings(0);
        }
      });

      // Clicking any Settings submenu item closes the dropdown immediately.
      // Navigation then proceeds normally to the selected page.
      settingsSubmenu.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('mouseenter', function () {
          this.classList.add('submenu-hover');
        });
        link.addEventListener('mouseleave', function () {
          this.classList.remove('submenu-hover');
        });
        link.addEventListener('click', function () {
          clearTimeout(closeTimer);
          settings.open = false;
          settings.classList.remove('js-hover-open');
          settingsSubmenu.setAttribute('aria-hidden', 'true');
        });
      });

      // Always start closed on a newly loaded page.
      settings.open = false;
      settings.classList.remove('js-hover-open');
      settingsSubmenu.setAttribute('aria-hidden', 'true');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (!confirm(el.dataset.confirm)) e.preventDefault();
      });
    });

    document.querySelectorAll('[data-number]').forEach(function (el) {
      el.addEventListener('input', function () {
        if (parseFloat(el.value) < 0) el.value = 0;
      });
    });

    initNavigation();
  });
})();

/* PPMP Review navigation is intentionally hidden for all users. */
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.sidebar .sidebar-nav a').forEach(function(link){
    var href=(link.getAttribute('href')||'').toLowerCase();
    var label=(link.textContent||'').replace(/\s+/g,' ').trim().toLowerCase();
    if(href.indexOf('ppmp_review.php')!==-1 || label.indexOf('ppmp review')!==-1){
      link.remove();
    }
  });
});
