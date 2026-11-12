/* 서비스 폼: 주관부서에 따라 현업 IT 담당자 후보를 걸러낸다(서버가 최종 검증). */
(function () {
  'use strict';
  var sel = document.getElementById('biz_dept_id');
  var box = document.getElementById('biz-it-box');
  var msg = document.getElementById('biz-it-msg');
  if (!sel || !box || !msg) { return; }
  var items = box.querySelectorAll('.form-check');

  function apply() {
    var dept = sel.value;
    var shown = 0;
    items.forEach(function (it) {
      var ok = dept !== '' && it.getAttribute('data-dept') === dept;
      it.classList.toggle('d-none', !ok);
      if (!ok) { it.querySelector('input').checked = false; } else { shown++; }
    });
    msg.textContent = dept === '' ? box.getAttribute('data-no-dept') : (shown === 0 ? box.getAttribute('data-no-user') : '');
  }

  sel.addEventListener('change', apply);
  apply();
})();
