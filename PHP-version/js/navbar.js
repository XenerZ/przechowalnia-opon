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

  return { render: render, setTheme: setTheme };
})();
