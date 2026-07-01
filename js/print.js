var Print = (function () {

  var TAGS = [
    { tag: '{{imie_nazwisko}}',    label: 'Imię i nazwisko',           isBarcodeTag: false },
    { tag: '{{nr_rejestracyjny}}', label: 'Numer rejestracyjny',       isBarcodeTag: false },
    { tag: '{{data_przyjecia}}',   label: 'Data przyjęcia',            isBarcodeTag: false },
    { tag: '{{data_wydania}}',     label: 'Data wydania',              isBarcodeTag: false },
    { tag: '{{rozmiar_kol}}',      label: 'Rozmiar kół',               isBarcodeTag: false },
    { tag: '{{lokalizacja}}',      label: 'Lokalizacja',               isBarcodeTag: false },
    { tag: '{{status}}',           label: 'Status',                    isBarcodeTag: false },
    { tag: '{{id_pozycji}}',       label: 'ID pozycji',                isBarcodeTag: false },
    { tag: '{{id_klienta}}',       label: 'ID klienta',                isBarcodeTag: false },
    { tag: '{{telefon}}',          label: 'Numer telefonu',            isBarcodeTag: false },
    { tag: '{{uwagi}}',            label: 'Uwagi',                     isBarcodeTag: false },
    { tag: '{{kod_qr}}',           label: 'Kod QR (link do wpisu)',    isBarcodeTag: true  },
    { tag: '{{kod_kreskowy}}',     label: 'Kod kreskowy (ID pozycji)', isBarcodeTag: true  },
  ];

  function formatTireSize(w, p, d, y) {
    var s = w + '/' + p + ' R' + d;
    if (y) s += ' · ' + y;
    return s;
  }

  function fillTextTags(html, tire) {
    var size = formatTireSize(tire.tireWidth, tire.tireProfile, tire.tireDiameter, tire.tireYear);
    return html
      .replace(/\{\{imie_nazwisko\}\}/g,    tire.fullName     || '')
      .replace(/\{\{nr_rejestracyjny\}\}/g, tire.licensePlate || '')
      .replace(/\{\{data_przyjecia\}\}/g,   tire.dateIn       || '')
      .replace(/\{\{data_wydania\}\}/g,     tire.dateOut      || '—')
      .replace(/\{\{rozmiar_kol\}\}/g,      size)
      .replace(/\{\{lokalizacja\}\}/g,      tire.location     || '')
      .replace(/\{\{status\}\}/g,           tire.status       || '')
      .replace(/\{\{id_pozycji\}\}/g,       String(tire.id    != null ? tire.id : ''))
      .replace(/\{\{id_klienta\}\}/g,       String(tire.customerId != null ? tire.customerId : ''))
      .replace(/\{\{telefon\}\}/g,          tire.phone        || '—')
      .replace(/\{\{uwagi\}\}/g,            tire.notes        || '—');
  }

  // Synchroniczna — podgląd w edytorze (kody zastąpione zaślepkami)
  function fillTemplate(html, tire) {
    return fillTextTags(html, tire)
      .replace(/\{\{kod_qr\}\}/g,
        '<span style="display:inline-flex;align-items:center;justify-content:center;width:80px;height:80px;background:#f0f0f0;border:1px dashed #aaa;font-size:9px;color:#888;text-align:center;">Kod QR</span>')
      .replace(/\{\{kod_kreskowy\}\}/g,
        '<span style="display:inline-flex;align-items:center;justify-content:center;width:140px;height:50px;background:#f0f0f0;border:1px dashed #aaa;font-size:9px;color:#888;text-align:center;">Kod kreskowy</span>');
  }

  function generateBarcode(value) {
    var canvas = document.createElement('canvas');
    JsBarcode(canvas, String(value), {
      format: 'CODE128', width: 2.2, height: 60,
      displayValue: true, fontSize: 13, margin: 6,
      background: '#ffffff', lineColor: '#000000',
    });
    return canvas.toDataURL('image/png');
  }

  async function generateQR(text) {
    return new Promise(function (resolve, reject) {
      QRCode.toDataURL(text, {
        width: 150, margin: 1, errorCorrectionLevel: 'M',
        color: { dark: '#000000', light: '#ffffff' },
      }, function (err, url) { if (err) reject(err); else resolve(url); });
    });
  }

  async function fillTemplateForPrint(html, tire) {
    var filled = fillTextTags(html, tire);

    if (/\{\{kod_qr\}\}/.test(filled)) {
      var qrUrl  = window.location.origin + '/tires.html?wpis=' + tire.id;
      var qrData = await generateQR(qrUrl);
      filled = filled.replace(/\{\{kod_qr\}\}/g,
        '<img src="' + qrData + '" style="width:120px;height:120px;" alt="QR">');
    }

    if (/\{\{kod_kreskowy\}\}/.test(filled)) {
      var barcodeData = generateBarcode(String(tire.id));
      filled = filled.replace(/\{\{kod_kreskowy\}\}/g,
        '<img src="' + barcodeData + '" style="max-width:220px;" alt="Barcode">');
    }

    return filled;
  }

  async function printTire(htmlTemplate, tire, pageSize) {
    var filled = await fillTemplateForPrint(htmlTemplate, tire);
    var ps = (pageSize || 'A4').toLowerCase();
    var pageCss = '@page { size: ' + ps + '; margin: 8mm; }';
    var doc = '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
      '<style>' + pageCss + ' body{margin:0;padding:0;} *{box-sizing:border-box;}</style>' +
      '</head><body>' + filled + '</body></html>';

    var blob   = new Blob([doc], { type: 'text/html' });
    var url    = URL.createObjectURL(blob);
    var iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;';
    document.body.appendChild(iframe);
    iframe.onload = function () {
      try { iframe.contentWindow.focus(); iframe.contentWindow.print(); }
      finally { setTimeout(function () { document.body.removeChild(iframe); URL.revokeObjectURL(url); }, 1000); }
    };
    iframe.src = url;
  }

  // Druk zbiorczy — jeden dokument, etykiety rozdzielone podziałem strony
  async function printTires(htmlTemplate, tiresArr, pageSize) {
    if (!tiresArr || !tiresArr.length) return;
    var parts = [];
    for (var i = 0; i < tiresArr.length; i++) {
      var filled = await fillTemplateForPrint(htmlTemplate, tiresArr[i]);
      var brk = i < tiresArr.length - 1 ? 'page-break-after:always;' : '';
      parts.push('<div style="' + brk + '">' + filled + '</div>');
    }
    var ps = (pageSize || 'A4').toLowerCase();
    var pageCss = '@page { size: ' + ps + '; margin: 8mm; }';
    var doc = '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
      '<style>' + pageCss + ' body{margin:0;padding:0;} *{box-sizing:border-box;}</style>' +
      '</head><body>' + parts.join('') + '</body></html>';

    var blob   = new Blob([doc], { type: 'text/html' });
    var url    = URL.createObjectURL(blob);
    var iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;';
    document.body.appendChild(iframe);
    iframe.onload = function () {
      try { iframe.contentWindow.focus(); iframe.contentWindow.print(); }
      finally { setTimeout(function () { document.body.removeChild(iframe); URL.revokeObjectURL(url); }, 1000); }
    };
    iframe.src = url;
  }

  return { TAGS: TAGS, fillTemplate: fillTemplate, printTire: printTire, printTires: printTires, formatTireSize: formatTireSize };
})();
