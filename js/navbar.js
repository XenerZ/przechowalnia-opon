var Navbar = (function () {
  var LINKS = [
    { href: 'dashboard.html', label: 'Dashboard' },
    { href: 'tires.html',     label: 'Opony' },
    { href: 'customers.html', label: 'Klienci', feature: 'customers' },
  ];
  var ADMIN_LINKS = [
    { href: 'templates.html', label: 'Szablony',           feature: 'actions' },
    { href: 'actions.html',   label: 'Akcje automatyczne', feature: 'actions' },
  ];

  function currentFile() {
    return window.location.pathname.split('/').pop().split('?')[0] || 'index.html';
  }

  function render() {
    var el = document.getElementById('navbar-container');
    if (!el) return;

    var user  = API.getUser();
    var file  = currentFile();
    var theme = localStorage.getItem('po_theme') || 'light';
    var initial  = user ? user.username.charAt(0).toUpperCase() : '?';
    var planId   = user && user.plan_id ? user.plan_id : '';
    var planBadge = planId ? '<span class="navbar-plan-badge navbar-plan-badge--' + planId + '">' + planId.toUpperCase() + '</span>' : '';

    var allLinks = LINKS.slice();
    if (user && Auth.can(user, 'manage_users')) allLinks = allLinks.concat(ADMIN_LINKS);
    // pokaż tylko linki, których funkcja jest w planie użytkownika (np. customers/actions)
    allLinks = allLinks.filter(function (l) { return !l.feature || Auth.hasFeature(user, l.feature); });

    var linksHtml = allLinks.map(function (l) {
      var active = file === l.href;
      return '<a href="' + l.href + '" class="nav-link' + (active ? ' active' : '') + '">' + l.label + '</a>';
    }).join('');

    var mobileLinksHtml = allLinks.map(function (l) {
      var active = file === l.href;
      return '<a href="' + l.href + '" class="navbar-mobile-link' + (active ? ' active' : '') + '" onclick="Navbar.closeMobile()">' + l.label + '</a>';
    }).join('');

    var themeSwitch =
      '<div class="theme-switch">' +
        '<button class="theme-switch-btn' + (theme === 'light' ? ' theme-switch-btn--active' : '') + '" onclick="Navbar.setTheme(\'light\')">Jasny</button>' +
        '<button class="theme-switch-btn' + (theme === 'dark'  ? ' theme-switch-btn--active' : '') + '" onclick="Navbar.setTheme(\'dark\')">Ciemny</button>' +
      '</div>';

    var usersLink = (user && Auth.can(user, 'manage_users'))
      ? '<a href="users.html" class="user-dropdown-item">Użytkownicy</a><div class="user-dropdown-divider"></div>'
      : '';
    var mobileUsersLink = (user && Auth.can(user, 'manage_users'))
      ? '<a href="users.html" class="navbar-mobile-link" onclick="Navbar.closeMobile()">Użytkownicy</a>'
      : '';

    var billingWarn = (user && user.billing && user.billing.overdue)
      ? '<div style="background:#fff7ed;color:#9a3412;border-bottom:1px solid #fed7aa;padding:.6rem 1rem;font-size:.85rem;text-align:center;font-weight:600">' +
          '⚠ Minął termin płatności faktury (' + (user.billing.dueDate || '') + '). Ureguluj ją — dostęp zostanie zablokowany za ' + (user.billing.blockInDays) + ' dni. Faktury: <a href="account.html" style="color:#9a3412;text-decoration:underline">Moje konto → Rozliczenia</a>.' +
        '</div>'
      : '';

    el.innerHTML =
      billingWarn +
      '<nav class="navbar">' +
        '<div class="navbar-brand">' +
          '<div class="navbar-logo-wrap">' +
            '<img src="assets/logo.png" class="navbar-logo-img" onload="this.nextElementSibling.style.display=\'none\'" onerror="this.style.display=\'none\'">' +
            '<span class="navbar-brand-text">Przechowalnia Opon</span>' +
          '</div>' +
        '</div>' +

        '<div class="navbar-links">' + linksHtml + '</div>' +

        '<a href="tickets.html" class="navbar-help-btn' + (file === 'tickets.html' ? ' active' : '') + '" data-tooltip="Pomoc i zgłoszenia" aria-label="Pomoc i zgłoszenia" style="margin-left:auto">?</a>' +

        '<div class="navbar-user" style="position:relative">' +
          '<button class="user-menu-btn" id="navBtn">' +
            '<span class="user-menu-name">' + (user ? user.username : '') + '</span> ' +
            '<span class="user-menu-chevron">▼</span>' +
          '</button>' +
          '<div class="user-dropdown" id="navMenu" style="display:none">' +
            '<div class="user-dropdown-header">' +
              '<div class="user-dropdown-name">' + (user ? user.username : '') + ' ' + planBadge + '</div>' +
              '<div class="user-dropdown-role">' + (user ? user.role : '') + '</div>' +
            '</div>' +
            '<div class="user-dropdown-divider"></div>' +
            '<div style="padding:0.4rem 0.75rem;display:flex;align-items:center;gap:0.5rem">' +
              '<span style="font-size:0.72rem;color:var(--text-sub)">Motyw:</span>' + themeSwitch +
            '</div>' +
            '<div class="user-dropdown-divider"></div>' +
            usersLink +
            '<a href="account.html" class="user-dropdown-item">Moje konto</a>' +
            '<button class="user-dropdown-item" onclick="Navbar.openPasswordChange()">Zmień hasło</button>' +
            '<button class="user-dropdown-item user-dropdown-item--danger" onclick="Auth.logout()">Wyloguj się</button>' +
          '</div>' +
        '</div>' +

        '<div class="navbar-mobile-controls">' +
          '<a href="tickets.html" class="navbar-help-btn navbar-help-btn--mobile' + (file === 'tickets.html' ? ' active' : '') + '" aria-label="Pomoc i zgłoszenia">?</a>' +
          '<button class="navbar-mobile-account-btn" id="navMobileAccountBtn" aria-label="Konto" aria-expanded="false">' + initial + '</button>' +
          '<button class="navbar-hamburger" id="navHamburger" aria-label="Menu" aria-expanded="false">' +
            '<span></span><span></span><span></span>' +
          '</button>' +
        '</div>' +
      '</nav>' +

      // Panel nawigacyjny
      '<div class="navbar-mobile-panel" id="navMobilePanel">' +
        '<div class="navbar-mobile-nav">' + mobileLinksHtml + '</div>' +
      '</div>' +

      // Panel użytkownika
      '<div class="navbar-mobile-panel" id="navMobileUserPanel">' +
        '<div class="navbar-mobile-user-info">' +
          '<div class="navbar-mobile-username">' + (user ? user.username : '') + ' ' + planBadge + '</div>' +
          '<div class="navbar-mobile-role">' + (user ? user.role : '') + '</div>' +
        '</div>' +
        '<div class="navbar-mobile-divider"></div>' +
        '<div style="padding:.55rem 1.25rem;display:flex;align-items:center;gap:.6rem">' +
          '<span style="font-size:.78rem;color:#8fb3d9">Motyw:</span>' + themeSwitch +
        '</div>' +
        '<div class="navbar-mobile-divider"></div>' +
        mobileUsersLink +
        '<a href="account.html" class="navbar-mobile-link" onclick="Navbar.closeMobile()">Moje konto</a>' +
        '<button class="navbar-mobile-action" onclick="Navbar.closeMobile();Navbar.openPasswordChange()">Zmień hasło</button>' +
        '<button class="navbar-mobile-action navbar-mobile-action--danger" onclick="Auth.logout()">Wyloguj się</button>' +
      '</div>';

    document.getElementById('navBtn').addEventListener('click', function (e) {
      e.stopPropagation();
      var m = document.getElementById('navMenu');
      m.style.display = m.style.display === 'none' ? 'block' : 'none';
    });

    document.getElementById('navHamburger').addEventListener('click', function (e) {
      e.stopPropagation();
      var panel     = document.getElementById('navMobilePanel');
      var userPanel = document.getElementById('navMobileUserPanel');
      var btn       = document.getElementById('navHamburger');
      var accBtn    = document.getElementById('navMobileAccountBtn');
      var willOpen  = !panel.classList.contains('open');
      // zamknij panel użytkownika jeśli był otwarty
      userPanel.classList.remove('open');
      accBtn.classList.remove('open');
      accBtn.setAttribute('aria-expanded', 'false');
      // przełącz panel nawigacyjny
      panel.classList.toggle('open', willOpen);
      btn.classList.toggle('open', willOpen);
      btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    document.getElementById('navMobileAccountBtn').addEventListener('click', function (e) {
      e.stopPropagation();
      var panel     = document.getElementById('navMobilePanel');
      var userPanel = document.getElementById('navMobileUserPanel');
      var btn       = document.getElementById('navHamburger');
      var accBtn    = document.getElementById('navMobileAccountBtn');
      var willOpen  = !userPanel.classList.contains('open');
      // zamknij panel nawigacyjny jeśli był otwarty
      panel.classList.remove('open');
      btn.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      // przełącz panel użytkownika
      userPanel.classList.toggle('open', willOpen);
      accBtn.classList.toggle('open', willOpen);
      accBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function () {
      var m = document.getElementById('navMenu');
      if (m) m.style.display = 'none';
      closeMobile();
    });
  }

  function closeMobile() {
    var panel     = document.getElementById('navMobilePanel');
    var userPanel = document.getElementById('navMobileUserPanel');
    var btn       = document.getElementById('navHamburger');
    var accBtn    = document.getElementById('navMobileAccountBtn');
    if (panel)     panel.classList.remove('open');
    if (userPanel) userPanel.classList.remove('open');
    if (btn)       { btn.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
    if (accBtn)    { accBtn.classList.remove('open'); accBtn.setAttribute('aria-expanded', 'false'); }
  }

  function setTheme(t) {
    localStorage.setItem('po_theme', t);
    document.documentElement.setAttribute('data-theme', t);
    render();
  }

  // ── Modal zmiany hasła ─────────────────────────────────────────────────────────
  function ensurePasswordModal() {
    if (document.getElementById('navMChangePass')) return;
    var div = document.createElement('div');
    div.innerHTML =
      '<div class="modal-overlay" id="navMChangePass" style="display:none" onclick="if(event.target===this)Navbar.closePasswordChange()">' +
        '<div class="modal-box" style="max-width:420px">' +
          '<div class="modal-header">' +
            '<span class="modal-title">Zmień hasło</span>' +
            '<button class="modal-close" onclick="Navbar.closePasswordChange()">✕</button>' +
          '</div>' +
          '<div class="modal-body">' +
            '<form onsubmit="Navbar.savePassword(event)">' +
              '<div class="form-group">' +
                '<label>Obecne hasło *</label>' +
                '<input type="password" id="navCurPass" placeholder="••••••••">' +
                '<span class="form-error" id="navCurPassErr" style="display:none"></span>' +
              '</div>' +
              '<div class="form-group">' +
                '<label>Nowe hasło *</label>' +
                '<input type="password" id="navNewPass" placeholder="min. 6 znaków">' +
                '<span class="form-error" id="navNewPassErr" style="display:none"></span>' +
              '</div>' +
              '<div class="form-group">' +
                '<label>Powtórz nowe hasło *</label>' +
                '<input type="password" id="navNewPass2" placeholder="••••••••">' +
                '<span class="form-error" id="navNewPass2Err" style="display:none"></span>' +
              '</div>' +
              '<div id="navPassAlert"></div>' +
              '<div class="form-actions">' +
                '<button type="button" class="btn btn-outline" onclick="Navbar.closePasswordChange()">Anuluj</button>' +
                '<button type="submit" class="btn btn-primary" id="navPassBtn">Zmień hasło</button>' +
              '</div>' +
            '</form>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(div.firstChild);
  }

  function openPasswordChange() {
    ensurePasswordModal();
    document.getElementById('navCurPass').value  = '';
    document.getElementById('navNewPass').value  = '';
    document.getElementById('navNewPass2').value = '';
    ['navCurPassErr','navNewPassErr','navNewPass2Err'].forEach(function(id){
      document.getElementById(id).style.display = 'none';
    });
    document.getElementById('navPassAlert').innerHTML = '';
    document.getElementById('navMChangePass').style.display = 'flex';
    setTimeout(function(){ document.getElementById('navCurPass').focus(); }, 50);
  }

  function closePasswordChange() {
    var m = document.getElementById('navMChangePass');
    if (m) m.style.display = 'none';
  }

  function showErr(id, msg) {
    var el = document.getElementById(id); el.textContent = msg; el.style.display = 'block';
  }

  async function savePassword(e) {
    e.preventDefault();
    ['navCurPassErr','navNewPassErr','navNewPass2Err'].forEach(function(id){
      document.getElementById(id).style.display = 'none';
    });
    document.getElementById('navPassAlert').innerHTML = '';

    var cur  = document.getElementById('navCurPass').value;
    var np   = document.getElementById('navNewPass').value;
    var np2  = document.getElementById('navNewPass2').value;
    var ok   = true;

    if (!cur)               { showErr('navCurPassErr',  'Pole wymagane'); ok = false; }
    if (!np)                { showErr('navNewPassErr',  'Pole wymagane'); ok = false; }
    else if (np.length < 6) { showErr('navNewPassErr',  'Minimum 6 znaków'); ok = false; }
    if (!np2)               { showErr('navNewPass2Err', 'Pole wymagane'); ok = false; }
    else if (np !== np2)    { showErr('navNewPass2Err', 'Hasła nie są identyczne'); ok = false; }
    if (!ok) return;

    var btn = document.getElementById('navPassBtn');
    btn.disabled = true; btn.textContent = 'Zapisywanie…';

    try {
      await API.post('/auth/change-password', { currentPassword: cur, newPassword: np });
      document.getElementById('navPassAlert').innerHTML =
        '<div class="alert alert-success" style="margin-top:.75rem">Hasło zostało zmienione.</div>';
      setTimeout(function(){ closePasswordChange(); }, 1500);
    } catch (err) {
      if (err.message && err.message.toLowerCase().includes('obecne')) {
        showErr('navCurPassErr', err.message);
      } else {
        document.getElementById('navPassAlert').innerHTML =
          '<div class="alert alert-error" style="margin-top:.75rem">' + err.message + '</div>';
      }
    } finally {
      btn.disabled = false; btn.textContent = 'Zmień hasło';
    }
  }

  return {
    render:              render,
    setTheme:            setTheme,
    closeMobile:         closeMobile,
    openPasswordChange:  openPasswordChange,
    closePasswordChange: closePasswordChange,
    savePassword:        savePassword,
  };
})();
