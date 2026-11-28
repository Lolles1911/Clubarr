// Clubarr: QR code and share for invitation links. The QR code is made here,
// in the browser (qrcode.js), so the link is never sent anywhere to draw it.
(function () {
  window.clubarrQr = function (text) {
    var qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr.createSvgTag({ cellSize: 6, margin: 3, scalable: true });
  };
  window.clubarrQrDownload = function (text, name) {
    var svg = window.clubarrQr(text).replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ');
    var url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    var a = document.createElement('a');
    a.href = url; a.download = (name || 'invitation') + '.svg';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  };
  window.clubarrShare = function (title, text, url) {
    if (navigator.share) {
      navigator.share({ title: title, text: text, url: url }).catch(function () {});
      return true;
    }
    return false;
  };
})();
