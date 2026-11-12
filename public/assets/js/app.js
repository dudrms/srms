/* data-confirm: 인라인 스크립트 없이(CSP) 삭제 확인 처리 */
document.addEventListener('submit', function (ev) {
  var f = ev.target;
  if (f.dataset && f.dataset.confirm && !window.confirm(f.dataset.confirm)) {
    ev.preventDefault();
  }
});
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('button[data-confirm]');
  if (b && !window.confirm(b.dataset.confirm)) {
    ev.preventDefault();
  }
});

/* select[data-filter]: 앞에 검색 입력칸을 붙여(한 줄 input-group) 이름/ID/부서로 옵션을 걸러 보여 준다(서버 값은 select 그대로 전송). */
document.addEventListener('DOMContentLoaded', function () {
  Array.prototype.forEach.call(document.querySelectorAll('select[data-filter]'), function (sel) {
    var group = document.createElement('div');
    group.className = 'input-group filter-group' + (sel.classList.contains('form-select-sm') ? ' input-group-sm' : '');
    var box = document.createElement('input');
    box.type = 'search';
    box.autocomplete = 'off';
    box.className = 'form-control filter-input';
    box.placeholder = sel.dataset.filterShort || sel.dataset.filterPh || '';
    box.title = sel.dataset.filterPh || '';
    box.setAttribute('aria-label', sel.dataset.filterPh || box.placeholder);
    sel.parentNode.insertBefore(group, sel);
    group.appendChild(box);
    group.appendChild(sel);

    var original = Array.prototype.map.call(sel.children, function (n) { return n.cloneNode(true); });
    var empty = sel.dataset.filterEmpty || '';

    function matches(text, q) { return text.toLowerCase().indexOf(q) !== -1; }

    function render() {
      var q = box.value.trim().toLowerCase();
      var current = sel.value;
      var out = [];
      original.forEach(function (n) {
        if (n.tagName === 'OPTION') {
          if (q === '' || n.value === '' || matches(n.textContent, q)) { out.push(n.cloneNode(true)); }
          return;
        }
        var groupHit = q === '' || matches(n.label, q);
        var g = n.cloneNode(false);
        Array.prototype.forEach.call(n.children, function (o) {
          if (groupHit || matches(o.textContent, q)) { g.appendChild(o.cloneNode(true)); }
        });
        if (g.children.length) { out.push(g); }
      });
      while (sel.firstChild) { sel.removeChild(sel.firstChild); }
      out.forEach(function (n) { sel.appendChild(n); });
      var real = sel.querySelectorAll('option:not([value=""])');
      if (q !== '' && real.length === 0) {
        var none = document.createElement('option');
        none.textContent = empty;
        none.disabled = true;
        none.value = '';
        sel.appendChild(none);
        sel.selectedIndex = sel.options.length - 1;
        return;
      }
      sel.value = current;
      if (real.length && (sel.value !== current || (q !== '' && current === ''))) { real[0].selected = true; }
    }

    box.addEventListener('input', render);
    box.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); }
    });
  });
});

/* input[data-file-list]: 선택한 파일 이름/크기 목록과 합계를 보여 주고, 개별 파일을 선택에서 뺄 수 있게 한다. */
document.addEventListener('DOMContentLoaded', function () {
  function size(n) {
    if (n < 1024) { return n + ' B'; }
    if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
    return (n / 1048576).toFixed(1) + ' MB';
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[type="file"][data-file-list]'), function (input) {
    var ul = document.createElement('ul');
    ul.className = 'list-unstyled small mb-1 mt-2';
    var anchor = input.closest('.input-group') || input;
    anchor.parentNode.insertBefore(ul, anchor.nextSibling);

    function render() {
      while (ul.firstChild) { ul.removeChild(ul.firstChild); }
      var files = Array.prototype.slice.call(input.files || []);
      var total = 0;
      files.forEach(function (f, i) {
        total += f.size;
        var li = document.createElement('li');
        li.className = 'd-flex align-items-center gap-2';
        var name = document.createElement('span');
        name.textContent = f.name;
        var sz = document.createElement('span');
        sz.className = 'text-muted';
        sz.textContent = size(f.size);
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'btn btn-sm btn-link text-danger p-0';
        rm.title = input.dataset.fileRemove || '';
        rm.setAttribute('aria-label', rm.title);
        rm.textContent = '\u00d7';
        rm.addEventListener('click', function () {
          var dt = new DataTransfer();
          files.forEach(function (x, j) { if (j !== i) { dt.items.add(x); } });
          input.files = dt.files;
          render();
        });
        li.appendChild(name);
        li.appendChild(sz);
        li.appendChild(rm);
        ul.appendChild(li);
      });
      if (files.length) {
        var sum = document.createElement('li');
        sum.className = 'text-muted';
        sum.textContent = (input.dataset.fileSummary || '{n} / {size}').replace('{n}', String(files.length)).replace('{size}', size(total));
        ul.appendChild(sum);
      }
    }
    input.addEventListener('change', render);
  });
});
