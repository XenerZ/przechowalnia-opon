/* ============================================================
   api.js — warstwa komunikacji z backendem
   MOCK_MODE = true  → działa bez bazy danych (do testów)
   MOCK_MODE = false → wysyła prawdziwe żądania HTTP do /api
   ============================================================ */
var MOCK_MODE = false;

var API = (function () {

  /* ── Magazyn sesji ──────────────────────────────────────── */
  function getToken()   { return sessionStorage.getItem('po_token'); }
  function saveToken(t) { sessionStorage.setItem('po_token', t); }
  function removeToken(){ sessionStorage.removeItem('po_token'); }
  function getUser()    { var s = sessionStorage.getItem('po_session'); return s ? JSON.parse(s) : null; }
  function saveUser(u)  { sessionStorage.setItem('po_session', JSON.stringify(u)); }
  function removeUser() { sessionStorage.removeItem('po_session'); }

  /* ══════════════════════════════════════════════════════════
     MOCK — dane w pamięci przeglądarki
     ══════════════════════════════════════════════════════════ */
  var _nextId = { users: 3, tires: 7, tpl: 3 };
  function nid(k) { return _nextId[k]++; }

  var _users = [
    { id:1, username:'admin',    email:'admin@firma.pl',    role:'admin',
      permissions:['manage_users','add_entries','edit_entries','delete_entries'],
      createdAt:'2025-01-10', _password:'admin123' },
    { id:2, username:'pracownik',email:'pracownik@firma.pl',role:'pracownik',
      permissions:['add_entries','edit_entries'],
      createdAt:'2025-03-15', _password:'pass123' },
  ];

  var _tires = [
    { id:1, customerId:1, fullName:'Jan Kowalski',    phone:'600 100 200', licensePlate:'CB 12345',
      tireWidth:205, tireProfile:55,  tireDiameter:16, tireYear:2022,
      location:'A-1', dateIn:'2025-10-01', status:'W przechowalni', dateOut:null, notes:'Zimowe' },
    { id:2, customerId:2, fullName:'Anna Nowak',      phone:'500 200 300', licensePlate:'GD 54321',
      tireWidth:225, tireProfile:45,  tireDiameter:17, tireYear:2021,
      location:'B-2', dateIn:'2025-10-05', status:'W przechowalni', dateOut:null, notes:'' },
    { id:3, customerId:3, fullName:'Piotr Wiśniewski',phone:'',            licensePlate:'WA 98765',
      tireWidth:195, tireProfile:65,  tireDiameter:15, tireYear:2020,
      location:'A-3', dateIn:'2025-09-20', status:'Wydane',         dateOut:'2025-10-15', notes:'' },
    { id:4, customerId:4, fullName:'Maria Zielińska', phone:'700 400 500', licensePlate:'KR 11111',
      tireWidth:215, tireProfile:60,  tireDiameter:16, tireYear:2023,
      location:'C-1', dateIn:'2025-10-18', status:'W przechowalni', dateOut:null, notes:'Ostrożnie z felgami' },
    { id:5, customerId:1, fullName:'Jan Kowalski',    phone:'600 100 200', licensePlate:'CB 12345',
      tireWidth:215, tireProfile:55,  tireDiameter:17, tireYear:2019,
      location:'A-5', dateIn:'2025-10-20', status:'W przechowalni', dateOut:null, notes:'' },
    { id:6, customerId:5, fullName:'Tomasz Malinowski',phone:'600 999 888',licensePlate:'PO 33333',
      tireWidth:235, tireProfile:50,  tireDiameter:18, tireYear:2022,
      location:'D-1', dateIn:'2025-10-21', status:'Wydane',         dateOut:'2025-10-28', notes:'' },
  ];

  var _templates = [
    { id:1, name:'Potwierdzenie przyjęcia', pageSize:'A4',
      htmlContent:'<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:24px;border:1px solid #ccc">\n  <h2 style="text-align:center;font-size:16pt;margin-bottom:6px">POTWIERDZENIE PRZYJĘCIA OPON</h2>\n  <p style="text-align:center;color:#666;font-size:9pt;margin-bottom:20px">Nr wpisu: {{id_pozycji}}</p>\n  <table style="width:100%;border-collapse:collapse;font-size:11pt">\n    <tr><td style="padding:6px 0;font-weight:bold;width:45%;border-bottom:1px solid #eee">Klient:</td><td style="padding:6px 0;border-bottom:1px solid #eee">{{imie_nazwisko}}</td></tr>\n    <tr><td style="padding:6px 0;font-weight:bold;border-bottom:1px solid #eee">Telefon:</td><td style="padding:6px 0;border-bottom:1px solid #eee">{{telefon}}</td></tr>\n    <tr><td style="padding:6px 0;font-weight:bold;border-bottom:1px solid #eee">Nr rej.:</td><td style="padding:6px 0;border-bottom:1px solid #eee"><strong>{{nr_rejestracyjny}}</strong></td></tr>\n    <tr><td style="padding:6px 0;font-weight:bold;border-bottom:1px solid #eee">Rozmiar:</td><td style="padding:6px 0;border-bottom:1px solid #eee">{{rozmiar_kol}}</td></tr>\n    <tr><td style="padding:6px 0;font-weight:bold;border-bottom:1px solid #eee">Lokalizacja:</td><td style="padding:6px 0;border-bottom:1px solid #eee">{{lokalizacja}}</td></tr>\n    <tr><td style="padding:6px 0;font-weight:bold;border-bottom:1px solid #eee">Data przyjęcia:</td><td style="padding:6px 0;border-bottom:1px solid #eee">{{data_przyjecia}}</td></tr>\n    <tr><td style="padding:6px 0;font-weight:bold">Status:</td><td style="padding:6px 0">{{status}}</td></tr>\n  </table>\n  <div style="display:flex;gap:20px;justify-content:center;margin-top:20px">{{kod_qr}} {{kod_kreskowy}}</div>\n  <p style="margin-top:24px;font-size:9pt;color:#666;text-align:center;border-top:1px solid #eee;padding-top:12px">Dziękujemy za skorzystanie z naszych usług.</p>\n</div>',
      createdAt:'2025-10-01', updatedAt:'2025-10-10' },
  ];

  /* ── Pomocnicy mock ─────────────────────────────────────── */
  function delay() { return new Promise(function(r){ setTimeout(r, 80); }); }
  function clone(x) { return JSON.parse(JSON.stringify(x)); }
  function pub(u) { var c=clone(u); delete c._password; return c; }

  function mockError(msg, status) {
    var e = new Error(msg); e.status = status || 400; throw e;
  }

  function getThisWeekRange() {
    var today = new Date(); today.setHours(0,0,0,0);
    var day = today.getDay(), diff = day===0?-6:1-day;
    var start = new Date(today); start.setDate(today.getDate()+diff);
    var end   = new Date(start); end.setDate(start.getDate()+7);
    return [start,end];
  }

  /* ── Router mock ────────────────────────────────────────── */
  async function mockRequest(method, path, body) {
    await delay();
    var p = path.replace(/^\//, '');
    var parts = p.split('/');
    var res = parts[0], id = parts[1] ? +parts[1] : null, sub = parts[2];

    /* ── /auth ── */
    if (res === 'auth') {
      if (id === 'login' || parts[1] === 'login') {
        var u = _users.find(function(x){ return x.username===body.username && x._password===body.password; });
        if (!u) mockError('Nieprawidłowa nazwa użytkownika lub hasło.', 401);
        return { token: 'mock-token-' + Date.now(), user: pub(u) };
      }
      if (parts[1] === 'verify-password') {
        var cur = getUser();
        if (!cur) mockError('Brak sesji', 401);
        var u2 = _users.find(function(x){ return x.id===cur.id; });
        return { valid: u2 && u2._password === body.password };
      }
      if (parts[1] === 'change-password') {
        var cur = getUser();
        if (!cur) mockError('Brak sesji', 401);
        var u2 = _users.find(function(x){ return x.id===cur.id; });
        if (!u2 || u2._password !== body.currentPassword) mockError('Nieprawidłowe obecne hasło.', 401);
        if (!body.newPassword || body.newPassword.length < 6) mockError('Nowe hasło musi mieć minimum 6 znaków.', 400);
        u2._password = body.newPassword;
        return { success: true };
      }
      if (parts[1] === 'forgot-password') {
        // Zawsze sukces — nie ujawniamy czy email istnieje
        return { success: true };
      }
      if (parts[1] === 'reset-password') {
        if (!body.newPassword || body.newPassword.length < 6) mockError('Hasło musi mieć minimum 6 znaków.', 400);
        if (body.token !== 'mock-reset-token') mockError('Link jest nieważny lub wygasł. Wyślij nowy.', 400);
        var mu = _users.find(function(x){ return x.id===1; });
        if (mu) mu._password = body.newPassword;
        return { success: true };
      }
    }

    /* ── /tires ── */
    if (res === 'tires') {
      if (parts[1] === 'stats') {
        var range = getThisWeekRange();
        return {
          total:  _tires.length,
          inStorage:   _tires.filter(function(t){ return t.status==='W przechowalni'; }).length,
          released:    _tires.filter(function(t){ return t.status==='Wydane'; }).length,
          releasedThisWeek: _tires.filter(function(t){
            if(!t.dateOut) return false;
            var d=new Date(t.dateOut); return d>=range[0]&&d<range[1];
          }).length,
          receivedThisWeek: _tires.filter(function(t){
            var d=new Date(t.dateIn); return d>=range[0]&&d<range[1];
          }).length,
        };
      }
      if (!id) {
        if (method==='GET') return clone(_tires);
        if (method==='POST') {
          var t=Object.assign({id:nid('tires'),customerId:null},body);
          _tires.push(t); return clone(t);
        }
      }
      var idx=_tires.findIndex(function(x){return x.id===id;});
      if (method==='GET'  && idx!==-1) return clone(_tires[idx]);
      if (method==='PUT'  && idx!==-1) { _tires[idx]=Object.assign(_tires[idx],body); return clone(_tires[idx]); }
      if (method==='DELETE'&&idx!==-1) { _tires.splice(idx,1); return {ok:true}; }
      mockError('Nie znaleziono wpisu', 404);
    }

    /* ── /users ── */
    if (res === 'users') {
      if (!id) {
        if (method==='GET') return _users.map(pub);
        if (method==='POST') {
          if (_users.find(function(x){return x.username===body.username;})) mockError('Nazwa użytkownika jest już zajęta');
          var nu={id:nid('users'),username:body.username,email:body.email,role:body.role||'pracownik',
            permissions:body.permissions||[],createdAt:new Date().toISOString().slice(0,10),_password:body.password||'pass123'};
          _users.push(nu); return pub(nu);
        }
      }
      var uidx=_users.findIndex(function(x){return x.id===id;});
      if (sub==='permissions' && method==='PUT' && uidx!==-1) {
        _users[uidx].permissions=body.permissions; return pub(_users[uidx]);
      }
      if (method==='GET'  && uidx!==-1) return pub(_users[uidx]);
      if (method==='PUT'  && uidx!==-1) {
        Object.assign(_users[uidx],{username:body.username,email:body.email,role:body.role});
        if (body.password) _users[uidx]._password=body.password;
        return pub(_users[uidx]);
      }
      if (method==='DELETE'&&uidx!==-1) { _users.splice(uidx,1); return {ok:true}; }
      mockError('Nie znaleziono użytkownika', 404);
    }

    /* ── /customers ── */
    if (res === 'customers') {
      var map = {};
      _tires.forEach(function(t){
        var key=t.fullName+'||'+(t.phone||'');
        if(!map[key]) map[key]={id:t.customerId||t.id,fullName:t.fullName,phone:t.phone||'',entries:[]};
        map[key].entries.push(clone(t));
      });
      return Object.values(map);
    }

    /* ── /templates ── */
    if (res === 'templates') {
      if (!id) {
        if (method==='GET') return clone(_templates).map(function(t){
          return {id:t.id,name:t.name,pageSize:t.pageSize,createdAt:t.createdAt,updatedAt:t.updatedAt};
        });
        if (method==='POST') {
          var nt=Object.assign({id:nid('tpl'),createdAt:new Date().toISOString().slice(0,10),updatedAt:new Date().toISOString().slice(0,10)},body);
          _templates.push(nt); return clone(nt);
        }
      }
      var tidx=_templates.findIndex(function(x){return x.id===id;});
      if (method==='GET'  &&tidx!==-1) return clone(_templates[tidx]);
      if (method==='PUT'  &&tidx!==-1) {
        Object.assign(_templates[tidx],body,{updatedAt:new Date().toISOString().slice(0,10)});
        return clone(_templates[tidx]);
      }
      if (method==='DELETE'&&tidx!==-1) { _templates.splice(tidx,1); return {ok:true}; }
      mockError('Nie znaleziono szablonu', 404);
    }

    mockError('Nieznany zasób: '+path, 404);
  }

  /* ══════════════════════════════════════════════════════════
     PRAWDZIWE żądania HTTP
     ══════════════════════════════════════════════════════════ */
  var BASE = '/api';

  async function realRequest(method, path, body) {
    var token = getToken();
    var headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = 'Bearer ' + token;
    var res = await fetch(BASE + path, {
      method: method, headers: headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401 && path !== '/auth/login') { removeToken(); removeUser(); window.location.href = 'login.html'; return; }
    var data = await res.json().catch(function(){ return {}; });
    if (!res.ok) throw new Error(data.message || 'Błąd HTTP ' + res.status);
    return data;
  }

  /* ── Publiczne API ──────────────────────────────────────── */
  function request(method, path, body) {
    if (MOCK_MODE) return mockRequest(method, path, body);
    return realRequest(method, path, body);
  }

  return {
    get:  function(p)    { return request('GET',    p); },
    post: function(p, b) { return request('POST',   p, b); },
    put:  function(p, b) { return request('PUT',    p, b); },
    del:  function(p)    { return request('DELETE', p); },
    getToken: getToken, saveToken: saveToken, removeToken: removeToken,
    getUser: getUser, saveUser: saveUser, removeUser: removeUser,
  };
})();
