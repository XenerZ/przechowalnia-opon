// Motyw — przed wyrenderowaniem body
(function () {
  var t = localStorage.getItem('po_theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
})();

var Auth = (function () {
  // token wygasł? (dekoduje pole exp z JWT; błąd dekodowania nie blokuje logowania)
  function tokenExpired() {
    var t = API.getToken();
    if (!t) return true;
    try {
      var p = JSON.parse(atob(t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
      return (p.exp || 0) * 1000 < Date.now();
    } catch (e) { return false; }
  }

  function requireAuth() {
    var user = API.getUser();
    if (!user || !API.getToken() || tokenExpired()) {
      API.removeToken(); API.removeUser();
      window.location.href = 'login.html'; return null;
    }
    return user;
  }

  function can(user, perm) {
    return user && Array.isArray(user.permissions) && user.permissions.indexOf(perm) !== -1;
  }

  function hasFeature(user, feature) {
    return user && Array.isArray(user.features) && user.features.indexOf(feature) !== -1;
  }

  function logout() {
    API.removeToken(); API.removeUser();
    window.location.href = 'login.html';
  }

  return { requireAuth: requireAuth, can: can, hasFeature: hasFeature, logout: logout };
})();

// Odświeżenie sesji z serwera — plan/uprawnienia/rola/rozliczenia mogły się zmienić.
// Aktualizuje token, przeładowuje przy zmianie zakresu, a przy 401/403 (sesja
// wygasła / konto zablokowane np. za zaległość) wylogowuje na stronę logowania.
(function refreshSession() {
  if (typeof API === 'undefined' || !API.getToken || !API.getToken()) return;
  var before = API.getUser() || {};
  if (before.impersonated_by) return; // nie ruszaj sesji impersonacji
  fetch('/api/auth/refresh', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + API.getToken(), 'Content-Type': 'application/json' },
    body: '{}'
  }).then(function (res) {
    if (res.status === 401 || res.status === 403) { // konto nieaktywne/zablokowane
      API.removeToken(); API.removeUser();
      if (!/login\.html$/.test(location.pathname)) location.href = 'login.html';
      return null;
    }
    if (!res.ok) return null;
    return res.json();
  }).then(function (data) {
    if (!data || !data.token || !data.user) return;
    API.saveToken(data.token);
    API.saveUser(data.user);
    var scope = function (u) {
      return JSON.stringify([
        u.role || '', u.plan_id || '',
        (u.features || []).slice().sort(),
        (u.permissions || []).slice().sort(),
        u.billing ? (u.billing.blockInDays + '|' + u.billing.overdue) : ''
      ]);
    };
    if (scope(before) !== scope(data.user)) location.reload();
  }).catch(function () {});
})();
