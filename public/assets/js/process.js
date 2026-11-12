/* 프로세스 단계 드래그 정렬: 서버에 새 순서를 저장하고 새로고침한다. */
(function () {
  var list = document.getElementById('step-list');
  if (!list || !list.dataset.orderUrl || typeof Sortable === 'undefined') {
    return;
  }
  Sortable.create(list, {
    handle: '.drag-handle',
    animation: 150,
    onEnd: function () {
      var ids = Array.prototype.map.call(list.querySelectorAll(':scope > li'), function (li) {
        return li.dataset.id;
      }).join(',');
      var body = new URLSearchParams();
      body.set('ids', ids);
      fetch(list.dataset.orderUrl, {
        method: 'POST',
        headers: { 'X-CSRF-Token': list.dataset.csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) { window.alert(res.error || 'error'); }
        window.location.reload();
      }).catch(function () { window.location.reload(); });
    }
  });
})();
