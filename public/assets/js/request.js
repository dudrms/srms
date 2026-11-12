/* 요청 등록 화면: Quill 에디터 초기화/동기화, 서비스×유형에 따른 프로세스 흐름 미리보기 */
(function () {
  var form = document.getElementById('request-form');
  var editorEl = document.getElementById('editor');
  var source = document.getElementById('content_html');
  var quill = null;

  if (editorEl && typeof Quill !== 'undefined') {
    quill = new Quill(editorEl, {
      theme: 'snow',
      modules: {
        toolbar: [
          [{ header: [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ list: 'ordered' }, { list: 'bullet' }],
          ['blockquote', 'code-block', 'link'],
          ['clean']
        ]
      }
    });
    if (source && source.value) {
      quill.clipboard.dangerouslyPasteHTML(source.value);
    }
  }

  if (form && source) {
    form.addEventListener('submit', function () {
      if (quill) {
        source.value = quill.getText().trim() === '' ? '' : quill.root.innerHTML;
      }
    });
  }

  var box = document.getElementById('flow-preview');
  var body = document.getElementById('flow-body');
  var service = document.getElementById('service_id');
  var type = document.getElementById('maint_type');
  if (!box || !body || !service || !type) {
    return;
  }

  function el(tag, text, cls) {
    var n = document.createElement(tag);
    if (text !== undefined) { n.textContent = text; }
    if (cls) { n.className = cls; }
    return n;
  }

  function render(res) {
    body.textContent = '';
    if (!res.ok) {
      body.appendChild(el('div', res.error, 'text-danger'));
      return;
    }
    body.appendChild(el('div', res.process + ' (v' + res.version + ')', 'fw-semibold mb-2 text-body'));
    var ol = el('ol', '', 'ps-3 mb-0 text-body');
    res.steps.forEach(function (s) {
      var li = document.createElement('li');
      li.appendChild(el('strong', s.name));
      li.appendChild(el('span', ' · ' + s.type + ' · ' + s.actor, 'text-muted'));
      ol.appendChild(li);
    });
    body.appendChild(ol);
  }

  function refresh() {
    if (!service.value || !type.value) {
      body.textContent = box.dataset.empty;
      return;
    }
    var q = new URLSearchParams({ service_id: service.value, maint_type: type.value });
    fetch(box.dataset.url + '?' + q.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function () { body.textContent = box.dataset.empty; });
  }

  service.addEventListener('change', refresh);
  type.addEventListener('change', refresh);
  refresh();
})();
