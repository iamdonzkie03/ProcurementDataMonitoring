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
        settings.classList.add('js-hover-open');
        settingsSubmenu.setAttribute('aria-hidden', 'false');
      }

      function closeSettings() {
        clearTimeout(closeTimer);
        closeTimer = setTimeout(function () {
          if (!settings.matches(':hover') && !settings.matches(':focus-within')) {
            settings.classList.remove('js-hover-open');
            settingsSubmenu.setAttribute('aria-hidden', 'true');
          }
        }, 120);
      }

      settingsSummary.addEventListener('mouseenter', openSettings);
      settingsSubmenu.addEventListener('mouseenter', openSettings);
      settings.addEventListener('mouseleave', closeSettings);
      settingsSummary.addEventListener('focus', openSettings);
      settings.addEventListener('focusout', closeSettings);

      settingsSubmenu.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('mouseenter', function () {
          this.classList.add('submenu-hover');
        });
        link.addEventListener('mouseleave', function () {
          this.classList.remove('submenu-hover');
        });
      });
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