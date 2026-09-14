/* Nexus Edge CRM — vanilla JS. No build step. */
(function () {
  'use strict';

  // ---------- Dial screen ----------
  var dialForm = document.getElementById('dial-form');
  if (dialForm) {
    var outcomeInput = dialForm.querySelector('input[name="outcome"]');
    var buttons = Array.prototype.slice.call(dialForm.querySelectorAll('.outcome'));
    var cbField = document.getElementById('callback-field');
    var cbInput = document.getElementById('callback_at');
    var noteInput = document.getElementById('note');
    var needCallback = (dialForm.getAttribute('data-need-callback') || '').split('|');

    function select(value) {
      outcomeInput.value = value;
      buttons.forEach(function (b) {
        b.classList.toggle('selected', b.getAttribute('data-outcome') === value);
      });
      var needs = needCallback.indexOf(value) !== -1;
      cbField.classList.toggle('hidden', !needs);
      cbInput.required = needs;
      if (needs) {
        if (!cbInput.value) {
          var d = new Date(Date.now() + 24 * 3600 * 1000);
          d.setSeconds(0, 0);
          cbInput.value = toLocalValue(d);
        }
        cbInput.focus();
      }
    }

    function toLocalValue(d) {
      function p(n) { return (n < 10 ? '0' : '') + n; }
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    buttons.forEach(function (b) {
      b.addEventListener('click', function () { select(b.getAttribute('data-outcome')); });
    });
    if (outcomeInput.value) { select(outcomeInput.value); }

    dialForm.addEventListener('submit', function (ev) {
      if (!outcomeInput.value) {
        ev.preventDefault();
        alert('Pick an outcome first (keys 1-8).');
        return;
      }
      if (cbInput.required && !cbInput.value) {
        ev.preventDefault();
        cbInput.focus();
        alert('Set a callback date and time for this outcome.');
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.ctrlKey || ev.metaKey || ev.altKey) { return; }
      var tag = (ev.target.tagName || '').toLowerCase();
      var typing = tag === 'textarea' || tag === 'input' || tag === 'select';
      if (ev.key >= '1' && ev.key <= '8' && !typing) {
        var idx = parseInt(ev.key, 10) - 1;
        if (buttons[idx]) { ev.preventDefault(); select(buttons[idx].getAttribute('data-outcome')); }
        return;
      }
      if (ev.key === 'Enter') {
        // Enter saves, except a plain Enter inside the notes textarea inserts a newline.
        if (tag === 'textarea' && !ev.shiftKey && !ev.ctrlKey) { return; }
        if (tag === 'button' || tag === 'a') { return; }
        ev.preventDefault();
        if (dialForm.requestSubmit) { dialForm.requestSubmit(); } else { dialForm.submit(); }
      }
    });
    // Ctrl/Cmd+Enter inside notes also saves.
    if (noteInput) {
      noteInput.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) {
          ev.preventDefault();
          if (dialForm.requestSubmit) { dialForm.requestSubmit(); } else { dialForm.submit(); }
        }
      });
    }
  }

  // ---------- Row links ----------
  Array.prototype.forEach.call(document.querySelectorAll('tr.row-link'), function (tr) {
    tr.addEventListener('click', function (ev) {
      var t = ev.target;
      if (t.closest('a, button, input, label, select')) { return; }
      var href = tr.getAttribute('data-href');
      if (href) { window.location.href = href; }
    });
  });

  // ---------- Bulk select ----------
  var selAll = document.getElementById('select-all');
  if (selAll) {
    var boxes = document.querySelectorAll('input[name="ids[]"]');
    var bulkCount = document.getElementById('bulk-count');
    function refresh() {
      var n = 0;
      Array.prototype.forEach.call(boxes, function (b) { if (b.checked) { n++; } });
      if (bulkCount) { bulkCount.textContent = n + ' selected'; }
    }
    selAll.addEventListener('change', function () {
      Array.prototype.forEach.call(boxes, function (b) { b.checked = selAll.checked; });
      refresh();
    });
    Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('change', refresh); });
    var bulkForm = document.getElementById('bulk-form');
    if (bulkForm) {
      bulkForm.addEventListener('submit', function (ev) {
        var n = 0;
        Array.prototype.forEach.call(boxes, function (b) { if (b.checked) { n++; } });
        if (n === 0) { ev.preventDefault(); alert('Select at least one lead.'); return; }
        var action = bulkForm.querySelector('select[name="bulk_action"]').value;
        if (action === 'delete' && !confirm('Delete ' + n + ' lead(s) and their call history? This cannot be undone.')) {
          ev.preventDefault();
        }
      });
    }
  }

  // ---------- Confirm buttons ----------
  Array.prototype.forEach.call(document.querySelectorAll('[data-confirm]'), function (el) {
    el.addEventListener('click', function (ev) {
      if (!confirm(el.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  });

  // ---------- Import progress ----------
  var imp = document.getElementById('import-progress');
  if (imp) {
    var token = imp.getAttribute('data-token');
    var csrf = imp.getAttribute('data-csrf');
    var bar = imp.querySelector('.progress > div');
    var status = document.getElementById('import-status');
    var summary = document.getElementById('import-summary');

    function step() {
      var body = new FormData();
      body.append('_csrf', csrf);
      body.append('token', token);
      body.append('action', 'chunk');
      fetch('import.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.error) { status.textContent = 'Error: ' + d.error; status.className = 'flash flash-err'; return; }
          var pct = d.total > 0 ? Math.round(d.processed / d.total * 100) : 100;
          bar.style.width = pct + '%';
          status.textContent = 'Processed ' + d.processed + ' of ' + d.total + ' rows (' + pct + '%)';
          if (d.done) {
            window.location.href = 'import.php?token=' + encodeURIComponent(token) + '&step=done';
          } else {
            step();
          }
        })
        .catch(function (err) {
          status.textContent = 'Import stopped: ' + err + '. Reload this page to resume.';
          status.className = 'flash flash-err';
        });
    }
    step();
  }
})();
