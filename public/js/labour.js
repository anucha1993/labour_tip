/* Labour App shared behaviours (vanilla JS + jQuery only where Select2 needs it). */
(function () {
  'use strict';

  var THEME_KEY = 'labour-theme';
  var SIDEBAR_KEY = 'labour-sidebar';

  // ------------------------------------------------------------ helpers
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }

  function store(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* private mode */ } }
  function read(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }

  // Day difference between today and an ISO date (yyyy-mm-dd). Null when invalid.
  function daysLeft(iso) {
    if (!iso) return null;
    var parts = iso.split('-');
    if (parts.length !== 3) return null;
    var target = new Date(+parts[0], +parts[1] - 1, +parts[2]);
    if (isNaN(target.getTime())) return null;
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((target - today) / 86400000);
  }

  function daysLeftLabel(d) {
    if (d === null) return { text: '-', cls: 'pill-none', icon: 'mdi-calendar' };
    if (d < 0) return { text: 'หมดอายุแล้ว ' + Math.abs(d) + ' วัน', cls: 'pill-expired', icon: 'mdi-alert-circle' };
    if (d === 0) return { text: 'หมดอายุวันนี้', cls: 'pill-expired', icon: 'mdi-alert-circle' };
    if (d <= 7) return { text: 'เหลือ ' + d + ' วัน', cls: 'pill-critical', icon: 'mdi-clock-alert' };
    if (d <= 30) return { text: 'เหลือ ' + d + ' วัน', cls: 'pill-warning', icon: 'mdi-clock-alert' };
    return { text: 'เหลือ ' + d + ' วัน', cls: 'pill-ok', icon: 'mdi-check-circle' };
  }

  function ageText(iso) {
    if (!iso) return '';
    var p = iso.split('-');
    if (p.length !== 3) return '';
    var b = new Date(+p[0], +p[1] - 1, +p[2]);
    if (isNaN(b.getTime())) return '';
    var now = new Date();
    var y = now.getFullYear() - b.getFullYear();
    var m = now.getMonth() - b.getMonth();
    if (now.getDate() < b.getDate()) m--;
    if (m < 0) { y--; m += 12; }
    if (y < 0) return '';
    return y + ' ปี ' + m + ' เดือน';
  }

  // ------------------------------------------------------------ theme
  function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    $$('[data-theme-toggle] i').forEach(function (i) {
      i.className = theme === 'dark' ? 'mdi mdi-weather-sunny' : 'mdi mdi-weather-night';
    });
  }
  function initTheme() {
    applyTheme(document.documentElement.getAttribute('data-bs-theme') || 'light');
    $$('[data-theme-toggle]').forEach(function (btn) {
      on(btn, 'click', function () {
        var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        store(THEME_KEY, next);
      });
    });
  }

  // ------------------------------------------------------------ sidebar
  function initSidebar() {
    var body = document.body;
    var mq = window.matchMedia('(min-width: 992px)');

    // The head script may have put the class on <html> to avoid a flash; move it to <body>.
    if (read(SIDEBAR_KEY) === 'collapsed') body.classList.add('sidebar-collapsed');
    document.documentElement.classList.remove('sidebar-collapsed');

    $$('[data-sidebar-toggle]').forEach(function (btn) {
      on(btn, 'click', function () {
        if (mq.matches) {
          body.classList.toggle('sidebar-collapsed');
          store(SIDEBAR_KEY, body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open');
        } else {
          body.classList.toggle('sidebar-open');
        }
      });
    });
    on($('#sidebarBackdrop'), 'click', function () { body.classList.remove('sidebar-open'); });
    on(document, 'keydown', function (e) { if (e.key === 'Escape') body.classList.remove('sidebar-open'); });
  }

  // ------------------------------------------------------------ confirm dialogs
  function initConfirm() {
    var modalEl = $('#confirmModal');
    if (!modalEl || !window.bootstrap) return;
    var modal = new bootstrap.Modal(modalEl);
    var pendingForm = null;

    on(document, 'submit', function (e) {
      var form = e.target;
      if (!form.matches('form[data-confirm]') || form.dataset.confirmed === '1') return;
      e.preventDefault();
      pendingForm = form;
      $('#confirmModalText').textContent = form.dataset.confirm || 'ยืนยันการทำรายการนี้?';
      var title = $('#confirmModalTitle');
      if (title) title.textContent = form.dataset.confirmTitle || 'ยืนยันการทำรายการ';
      var okBtn = $('#confirmModalOk');
      okBtn.className = 'btn ' + (form.dataset.confirmClass || 'btn-danger');
      okBtn.textContent = form.dataset.confirmOk || 'ยืนยัน';
      modal.show();
    });

    on($('#confirmModalOk'), 'click', function () {
      if (!pendingForm) return;
      pendingForm.dataset.confirmed = '1';
      modal.hide();
      pendingForm.requestSubmit ? pendingForm.requestSubmit() : pendingForm.submit();
    });
    on(modalEl, 'hidden.bs.modal', function () { pendingForm = null; });
  }

  // ------------------------------------------------------------ select2
  function initSelect2() {
    if (!window.jQuery || !jQuery.fn.select2) return;
    jQuery('.js-select2').each(function () {
      var $el = jQuery(this);
      if ($el.data('select2')) return;
      $el.select2({
        width: '100%',
        placeholder: $el.data('placeholder') || 'เลือก...',
        allowClear: $el.data('allow-clear') !== undefined,
        language: { noResults: function () { return 'ไม่พบข้อมูล'; }, searching: function () { return 'กำลังค้นหา...'; } }
      });
    });
  }

  // ------------------------------------------------------------ small UX bits
  function initAlerts() {
    $$('.alert[data-autodismiss]').forEach(function (el) {
      setTimeout(function () {
        if (window.bootstrap && bootstrap.Alert) bootstrap.Alert.getOrCreateInstance(el).close();
        else el.remove();
      }, +el.dataset.autodismiss || 6000);
    });
  }

  function initPasswordToggles() {
    $$('[data-toggle-password]').forEach(function (btn) {
      on(btn, 'click', function () {
        var input = $(btn.dataset.togglePassword);
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        var i = btn.querySelector('i');
        if (i) i.className = show ? 'mdi mdi-eye-off' : 'mdi mdi-eye';
      });
    });
  }

  // Elements with data-days-left-for="#input" show a live countdown pill.
  function initDateHints() {
    $$('[data-days-left-for]').forEach(function (out) {
      var input = $(out.dataset.daysLeftFor);
      if (!input) return;
      var render = function () {
        var info = daysLeftLabel(daysLeft(input.value));
        out.className = 'pill ' + info.cls;
        out.innerHTML = '<i class="mdi ' + info.icon + '"></i>' + info.text;
      };
      on(input, 'change', render);
      on(input, 'input', render);
      render();
    });
    $$('[data-age-for]').forEach(function (out) {
      var input = $(out.dataset.ageFor);
      if (!input) return;
      var render = function () { out.value = ageText(input.value) || ''; };
      on(input, 'change', render);
      render();
    });
    // The browser shows <input type="date"> in its own locale (often mm/dd/yyyy), so spell the
    // date out in Thai and show the Worker App password (ddmmyyyy) that follows from it.
    var thMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    $$('[data-birthday-hint]').forEach(function (out) {
      var input = $(out.dataset.birthdayHint);
      if (!input) return;
      var render = function () {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(input.value || '');
        if (!m) { out.textContent = ''; return; }
        out.innerHTML = 'เกิดวันที่ <b>' + parseInt(m[3], 10) + ' ' + thMonths[parseInt(m[2], 10) - 1] + ' ' + m[1] + '</b>' +
          ' · รหัสผ่าน Worker App: <code>' + m[3] + m[2] + m[1] + '</code>';
      };
      on(input, 'change', render);
      on(input, 'input', render);
      render();
    });
  }

  // Labour form: show the resign/escape date box that matches the chosen status.
  function initStatusJob() {
    var sel = $('#labour_status_job');
    if (!sel) return;
    var render = function () {
      $$('[data-status-box]').forEach(function (box) {
        var active = box.dataset.statusBox === sel.value;
        box.hidden = !active;
        $$('input', box).forEach(function (i) { i.disabled = !active; });
      });
    };
    on(sel, 'change', render);
    render();
  }

  // Labour index: mass update panel + select-all.
  function initMassUpdate() {
    var toggle = $('#massToggle');
    var bar = $('#massBar');
    if (!toggle || !bar) return;
    var all = $('#checkAll');
    var boxes = $$('input[name="labour_ids[]"]');
    var counter = $('#massCount');
    var submit = $('#massSubmit');

    var refresh = function () {
      var n = boxes.filter(function (b) { return b.checked; }).length;
      if (counter) counter.textContent = n;
      if (submit) submit.disabled = n === 0;
      if (all) all.indeterminate = n > 0 && n < boxes.length;
    };
    on(toggle, 'change', function () {
      bar.classList.toggle('show', toggle.checked);
      if (!toggle.checked) { boxes.forEach(function (b) { b.checked = false; }); if (all) all.checked = false; refresh(); }
    });
    on(all, 'change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); refresh(); });
    boxes.forEach(function (b) { on(b, 'change', refresh); });
    refresh();
  }

  // Dashboard tabs: remember the open tab across pagination clicks.
  function initTabMemory() {
    var tabs = $$('[data-bs-toggle="tab"][data-tab-memory]');
    if (!tabs.length || !window.bootstrap) return;
    var hash = location.hash.replace('#', '');
    tabs.forEach(function (t) {
      var target = t.getAttribute('data-bs-target') || t.getAttribute('href');
      if (hash && target === '#' + hash) bootstrap.Tab.getOrCreateInstance(t).show();
      on(t, 'shown.bs.tab', function () { history.replaceState(null, '', target); });
    });
    $$('.tab-pane').forEach(function (pane) {
      $$('a.page-link', pane).forEach(function (a) {
        if (a.getAttribute('href') && a.getAttribute('href').indexOf('#') === -1) a.href += '#' + pane.id;
      });
    });
  }

  // Submit a GET filter form when a select marked data-autosubmit changes.
  function initAutoSubmit() {
    $$('[data-autosubmit]').forEach(function (el) {
      on(el, 'change', function () { if (el.form) el.form.submit(); });
    });
  }

  // Disable submit buttons after the first click to avoid double posts.
  function initSubmitGuard() {
    on(document, 'submit', function (e) {
      var form = e.target;
      if (form.matches('form[data-confirm]') && form.dataset.confirmed !== '1') return;
      $$('button[type="submit"]', form).forEach(function (b) {
        setTimeout(function () { b.disabled = true; b.classList.add('disabled'); }, 0);
      });
      setTimeout(function () {
        $$('button[type="submit"]', form).forEach(function (b) { b.disabled = false; b.classList.remove('disabled'); });
      }, 8000);
    });
  }


  // Structured forms: highlight the section currently in view in the sticky section nav.
  function initFormNav() {
    var links = $$('.form-nav a[href^="#"]');
    if (!links.length || !('IntersectionObserver' in window)) return;
    var map = {};
    links.forEach(function (a) { var el = $(a.getAttribute('href')); if (el) map[el.id] = a; });
    var visible = {};
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { visible[e.target.id] = e.isIntersecting ? e.intersectionRatio : 0; });
      var best = null, bestRatio = 0;
      Object.keys(visible).forEach(function (id) { if (visible[id] > bestRatio) { bestRatio = visible[id]; best = id; } });
      if (!best) return;
      links.forEach(function (a) { a.classList.toggle('active', a === map[best]); });
      if (map[best] && map[best].scrollIntoView) map[best].scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, { rootMargin: '-120px 0px -55% 0px', threshold: [0, 0.25, 0.5, 1] });
    Object.keys(map).forEach(function (id) { io.observe($('#' + id)); });
    links.forEach(function (a) {
      on(a, 'click', function (e) {
        var el = $(a.getAttribute('href'));
        if (!el) return;
        e.preventDefault();
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.replaceState(null, '', a.getAttribute('href'));
      });
    });
  }


  // Labour form: unlimited document rows (name + file).
  function initDocRows() {
    var wrap = $('#docRows'), tpl = $('#docRowTpl'), add = $('#addDocRow');
    if (!wrap || !tpl || !add) return;
    var next = +wrap.dataset.nextIndex || 0;
    function rows() { return $$('.doc-row:not(.doc-row-head)', wrap); }
    function renumber() {
      var list = rows();
      list.forEach(function (r, i) { var n = $('.doc-no', r); if (n) n.textContent = i + 1; });
      var head = $('.doc-row-head', wrap);
      if (head) head.hidden = list.length === 0; // column headings only make sense with rows under them
    }
    function bind(row) {
      on($('[data-doc-remove]', row), 'click', function () { row.remove(); renumber(); });
      var file = $('input[type="file"]', row), name = $('input[type="text"]', row);
      on(file, 'change', function () {
        // Suggest a document name from the file name when the user has not typed one.
        if (name && !name.value && file.files[0]) {
          var base = file.files[0].name.replace(/\.[^.]+$/, '');
          var guess = /pass/i.test(base) ? 'Passport' : /visa/i.test(base) ? 'Visa' : /work/i.test(base) ? 'Work Permit' : /90/.test(base) ? '90-Day Report' : '';
          if (guess) name.value = guess;
        }
      });
    }
    function addRow() {
      var holder = document.createElement('div');
      holder.innerHTML = tpl.innerHTML.replace(/__i__/g, next++).trim();
      var row = holder.firstElementChild;
      wrap.appendChild(row);
      bind(row);
      renumber();
      var name = $('input[type="text"]', row); if (name) name.focus();
      return row;
    }
    rows().forEach(bind);
    renumber();
    on(add, 'click', addRow);
    // Drop empty rows before submitting so they do not count against the per-save limit.
    var form = wrap.closest('form');
    on(form, 'submit', function () {
      rows().forEach(function (r) {
        var f = $('input[type="file"]', r), n = $('input[type="text"]', r);
        if (f && !f.files.length && n && !n.value.trim()) r.remove();
      });
    });
  }


  // ------------------------------------------------------------ loading UX (progress bar + skeleton)
  var loader = (function () {
    var bar = null, skeleton = null, timer = null, safety = null, pct = 0, tick = null, active = false;
    function start(withSkeleton) {
      if (active) return;
      active = true;
      bar = bar || $('#pageProgress'); skeleton = skeleton || $('#pageSkeleton');
      if (bar) {
        bar.classList.remove('done'); bar.style.width = '8%'; bar.classList.add('active'); pct = 8;
        tick = setInterval(function () { pct = Math.min(90, pct + (90 - pct) * 0.12); bar.style.width = pct + '%'; }, 220);
      }
      // Delay the skeleton a little so instant navigations do not flash.
      if (withSkeleton && skeleton) timer = setTimeout(function () { skeleton.classList.add('show'); }, 140);
      // Never leave the page stuck (e.g. navigation cancelled by the browser).
      safety = setTimeout(stop, 10000);
    }
    function stop() {
      active = false;
      clearTimeout(timer); clearTimeout(safety); clearInterval(tick);
      if (bar) { bar.classList.add('done'); setTimeout(function () { bar.classList.remove('active', 'done'); bar.style.width = '0'; }, 500); }
      if (skeleton) skeleton.classList.remove('show');
      $$('.btn.is-loading').forEach(function (b) { b.classList.remove('is-loading'); b.disabled = false; });
    }
    return { start: start, stop: stop };
  })();

  function isInternalNavLink(a) {
    if (!a || a.hasAttribute('data-no-loader') || a.hasAttribute('download') || a.target === '_blank') return false;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return false;
    if (a.hasAttribute('data-bs-toggle')) return false;
    try { return new URL(a.href, location.href).origin === location.origin; } catch (e) { return false; }
  }

  function initLoading() {
    on(document, 'click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest('a[href]');
      if (!isInternalNavLink(a)) return;
      var u = new URL(a.href, location.href);
      if (u.pathname === location.pathname && u.search === location.search && u.hash) return; // same page, hash only
      loader.start(true);
    });
    on(document, 'submit', function (e) {
      var form = e.target;
      if (e.defaultPrevented || form.target === '_blank' || form.hasAttribute('data-no-loader')) return;
      if (form.matches('form[data-confirm]') && form.dataset.confirmed !== '1') return;
      var btn = e.submitter || $('button[type="submit"]', form);
      if (btn) btn.classList.add('is-loading');
      loader.start((form.method || 'get').toLowerCase() === 'get');
    });
    // Back/forward cache restores the old DOM: clear any leftover loading state.
    on(window, 'pageshow', function () { loader.stop(); });
    on(window, 'pagehide', function () { loader.stop(); });
  }

  // ------------------------------------------------------------ boot
  document.addEventListener('DOMContentLoaded', function () {
    initTheme();
    initSidebar();
    initConfirm();
    initSelect2();
    initAlerts();
    initPasswordToggles();
    initDateHints();
    initStatusJob();
    initMassUpdate();
    initTabMemory();
    initAutoSubmit();
    initSubmitGuard();
    initFormNav();
    initDocRows();
    initLoading();
  });

  window.LabourApp = { loader: loader, daysLeft: daysLeft, daysLeftLabel: daysLeftLabel, ageText: ageText, initSelect2: initSelect2 };
})();
