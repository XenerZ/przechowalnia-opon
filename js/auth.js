// Motyw — przed wyrenderowaniem body
(function () {
  var t = localStorage.getItem('po_theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
})();

var Auth = (function () {
  function requireAuth() {
    var user = API.getUser();
    if (!user || !API.getToken()) { window.location.href = 'login.html'; return null; }
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
