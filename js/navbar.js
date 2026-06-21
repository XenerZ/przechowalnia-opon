var Navbar = (function () {
  var LINKS = [
    { href: 'dashboard.html', label: 'Dashboard' },
    { href: 'tires.html',     label: 'Opony' },
    { href: 'customers.html', label: 'Klienci' },
  ];
  var ADMIN_LINKS = [
    { href: 'users.html',     label: 'Użytkownicy' },
    { href: 'templates.html', label: 'Szablony' },
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

    var allLinks = LINKS.slice();
    if (user && Auth.can(user, 'manage_users')) allLinks = allLinks.concat(ADMIN_LINKS);

    var linksHtml = allLinks.map(function (l) {
      var active = file === l.href;
      return '<a href="' + l.href + '" class="nav-link' + (active ? ' active' : '') + '">' + l.label + '</a>';
    }).join('');

    el.innerHTML =
      '<nav class="navbar">' +
        '<div class="navbar-brand">' +
          '<div class="navbar-logo-wrap"><img src="assets/logo.png" class="navbar-logo-img" onload="this.nextElementSibling.style.display=\'none\'" onerror="this.style.display=\'none\'"><span class="navbar-brand-text">Przechowalnia Opon</span></div>' +
        '</div>' +
        '<div class="navbar-links">' + linksHtml + '</div>' +
        '<div class="navbar-user" style="position:relative;margin-left:auto">' +
          '<button class="user-menu-btn" id="navBtn">' +
            '<span class="user-menu-name">' + (user ? user.username : '') + '</span> ' +
            '<span class="user-menu-chevron">▼</span>' +
          '</button>' +
          '<div class="user-dropdown" id="navMenu" style="display:none">' +
            '<div class="user-dropdown-header">' +
              '<div class="user-dropdown-name">' + (user ? user.username : '') + '</div>' +
              '<div class="user-dropdown-role">' + (user ? user.role : '') + '</div>' +
            '</div>' +
            '<div class="user-dropdown-divider"></div>' +
            '<div style="padding:0.4rem 0.75rem;display:flex;align-items:center;gap:0.5rem">' +
              '<span style="font-size:0.72rem;color:var(--text-sub)">Motyw:</span>' +
              '<div class="theme-switch">' +
                '<button class="theme-switch-btn' + (theme==='light'?' theme-switch-btn--active':'') + '" onclick="Navbar.setTheme(\'light\')">Jasny</button>' +
                '<button class="theme-switch-btn' + (theme==='dark'?' theme-switch-btn--active':'') + '" onclick="Navbar.setTheme(\'dark\')">Ciemny</button>' +
              '</div>' +
            '</div>' +
            '<div class="user-dropdown-divider"></div>' +
            '<button class="user-dropdown-item" onclick="Navbar.openPasswordChange()">🔒 Zmień hasło</button>' +
            '<button class="user-dropdown-item user-dropdown-item--danger" onclick="Auth.logout()">Wyloguj się</button>' +
          '</div>' +
        '</div>' +
      '</nav>';

    document.getElementById('navBtn').addEventListener('click', function (e) {
      e.stopPropagation();
      var m = document.getElementById('navMenu');
      m.style.display = m.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', function () {
      var m = document.getElementById('navMenu');
      if (m) m.style.display = 'none';
    });
  }

  function setTheme(t) {
    localStorage.setItem('po_theme', t);
    document.documentElement.setAttribute('data-theme', t);
    render();
  }

  // ── Modal zmiany własnego hasła ───────────────────────────────────────────────
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

    if (!cur)              { showErr('navCurPassErr',  'Pole wymagane'); ok = false; }
    if (!np)               { showErr('navNewPassErr',  'Pole wymagane'); ok = false; }
    else if (np.length < 6){ showErr('navNewPassErr',  'Minimum 6 znaków'); ok = false; }
    if (!np2)              { showErr('navNewPass2Err', 'Pole wymagane'); ok = false; }
    else if (np !== np2)   { showErr('navNewPass2Err', 'Hasła nie są identyczne'); ok = false; }
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

  return { render: render, setTheme: setTheme, openPasswordChange: openPasswordChange, closePasswordChange: closePasswordChange, savePassword: savePassword };
})();
