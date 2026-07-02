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

// Odświeżenie sesji z serwera — plan/uprawnienia/rola mogły zmienić się po stronie admina.
// Aktualizuje token i (jeśli zakres się zmienił) przeładowuje stronę, by odblokować funkcje.
(function refreshSession() {
  if (typeof API === 'undefined' || !API.getToken || !API.getToken()) return;
  var before = API.getUser() || {};
  if (before.impersonated_by) return; // nie ruszaj sesji impersonacji
  API.post('/auth/refresh', {}).then(function (res) {
    if (!res || !res.token || !res.user) return;
    API.saveToken(res.token);
    API.saveUser(res.user);
    var scope = function (u) {
      return JSON.stringify([
        u.role || '', u.plan_id || '',
        (u.features || []).slice().sort(),
        (u.permissions || []).slice().sort()
      ]);
    };
    if (scope(before) !== scope(res.user)) location.reload();
  }).catch(function () {});
})();
