// inni front helpers (reserved)
document.documentElement.classList.add('js');

(function () {
  function debounce(fn, ms) {
    var timer = 0;
    return function () {
      var ctx = this;
      var args = arguments;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        fn.apply(ctx, args);
      }, ms);
    };
  }

  function yearsInput(box) {
    var form = box.closest('form');
    if (!form) {
      return null;
    }
    return form.querySelector('[name="useful_life_years"]');
  }

  function renderList(box, items) {
    var wrap = box.querySelector('.life-suggest');
    var list = box.querySelector('.life-suggest-list');
    if (!list) {
      return;
    }
    list.textContent = '';
    if (!items || !items.length) {
      if (wrap) {
        wrap.hidden = true;
      }
      return;
    }
    items.forEach(function (hit) {
      var li = document.createElement('li');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-ghost life-suggest-accept';
      btn.setAttribute('data-years', String(hit.years));
      btn.setAttribute('data-source', hit.source || '');
      btn.appendChild(document.createTextNode(hit.name + ' · ' + hit.years + '년 '));
      var meta = document.createElement('span');
      meta.className = 'muted';
      meta.textContent = hit.class_number || '';
      btn.appendChild(meta);
      li.appendChild(btn);
      list.appendChild(li);
    });
    if (wrap) {
      wrap.hidden = false;
    }
  }

  function queryName(box) {
    var from = box.getAttribute('data-name-from') || '';
    if (from) {
      var scope = box.closest('form') || document;
      var input = scope.querySelector('[name="' + from + '"]');
      if (input && 'value' in input) {
        return String(input.value || '');
      }
    }
    return box.getAttribute('data-name-value') || '';
  }

  document.querySelectorAll('[data-life-suggest]').forEach(function (box) {
    var url = box.getAttribute('data-suggest-url');
    var classInput = box.querySelector('[data-life-class]');
    var sourceEl = box.querySelector('.life-suggest-source');
    if (!url) {
      return;
    }

    var refresh = debounce(function () {
      var name = queryName(box).trim();
      var klass = classInput ? String(classInput.value || '').trim() : '';
      if (name.length < 2 && klass.replace(/\D+/g, '').length < 4) {
        renderList(box, []);
        return;
      }
      var endpoint;
      try {
        endpoint = new URL(url, window.location.href);
      } catch (err) {
        return;
      }
      endpoint.searchParams.set('name', name);
      endpoint.searchParams.set('class_number', klass);
      fetch(endpoint.toString(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
        .then(function (res) {
          return res.ok ? res.json() : { suggestions: [] };
        })
        .then(function (data) {
          renderList(box, (data && data.suggestions) || []);
        })
        .catch(function () {
          renderList(box, []);
        });
    }, 220);

    var nameFrom = box.getAttribute('data-name-from') || '';
    if (nameFrom) {
      var form = box.closest('form') || document;
      var nameInput = form.querySelector('[name="' + nameFrom + '"]');
      if (nameInput) {
        nameInput.addEventListener('input', refresh);
      }
    }
    if (classInput) {
      classInput.addEventListener('input', refresh);
    }

    box.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.life-suggest-accept');
      if (!btn || !box.contains(btn)) {
        return;
      }
      var years = btn.getAttribute('data-years') || '';
      var source = btn.getAttribute('data-source') || '';
      var input = yearsInput(box);
      if (input) {
        input.value = years;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (sourceEl) {
        sourceEl.hidden = false;
        sourceEl.textContent = source;
      }
    });
  });
})();

