{{-- Floating "เลือกแล้ว n รายการ" bar + the POST that streams the selection as one zip.
     Checkboxes anywhere on the page: <input class="dx-sel" data-kind="labour|document" value="id" data-bytes="N" data-files="N">
     $action = zip route, $maxFiles / $maxBytes = limits shown to the user. --}}
<div class="dx-selbar d-none" id="dxSelBar" role="region" aria-label="รายการที่เลือก">
    <form method="POST" action="{{ $action }}" id="dxSelForm" data-no-loader>
        @csrf
        <span class="n"><i class="mdi mdi-checkbox-marked"></i> เลือกแล้ว <b id="dxSelN">0</b> รายการ · <span id="dxSelFiles">0</span> ไฟล์ · ~<span id="dxSelSize">0 KB</span></span>
        <span class="warn text-warning small d-none" id="dxSelWarn"></span>
        <span class="actions">
            <button type="button" class="btn btn-soft btn-sm" id="dxSelAll"><i class="mdi mdi-check-all"></i> เลือกทั้งหมด</button>
            <button type="submit" class="btn btn-primary btn-sm" id="dxSelZip"><i class="mdi mdi-zip-box"></i> ดาวน์โหลด zip</button>
            <button type="button" class="btn btn-soft btn-sm" id="dxSelClear" aria-label="ยกเลิกการเลือก"><i class="mdi mdi-close"></i></button>
        </span>
        <span id="dxSelInputs"></span>
    </form>
</div>

@push('scripts')
<script>
(function () {
    var bar = document.getElementById('dxSelBar');
    if (!bar) return;
    var MAX_FILES = {{ (int) $maxFiles }}, MAX_BYTES = {{ (int) $maxBytes }};
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    function human(b) { return b >= 1073741824 ? (b / 1073741824).toFixed(1) + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
    function key(b) { return b.dataset.kind + ':' + b.value; }
    // The grid and the list show the same items, so count each (kind, id) once.
    function selected() {
        var seen = {}, out = [];
        $$('.dx-sel').forEach(function (b) { if (b.checked && !seen[key(b)]) { seen[key(b)] = 1; out.push(b); } });
        return out;
    }
    function update() {
        var sel = selected(), files = 0, bytes = 0;
        sel.forEach(function (b) { files += parseInt(b.dataset.files || '1', 10); bytes += parseInt(b.dataset.bytes || '0', 10); });
        bar.classList.toggle('d-none', sel.length === 0);
        document.getElementById('dxSelN').textContent = sel.length;
        document.getElementById('dxSelFiles').textContent = files;
        document.getElementById('dxSelSize').textContent = human(bytes);
        var warn = document.getElementById('dxSelWarn'), msg = '';
        if (files > MAX_FILES) msg = 'เกิน ' + MAX_FILES + ' ไฟล์ต่อ zip กรุณาแบ่งเป็นหลายครั้ง';
        else if (bytes > MAX_BYTES) msg = 'เกิน ' + human(MAX_BYTES) + ' ต่อ zip กรุณาแบ่งเป็นหลายครั้ง';
        warn.textContent = msg; warn.classList.toggle('d-none', msg === '');
        document.getElementById('dxSelZip').disabled = msg !== '' || files === 0;
        $$('.dx-sel').forEach(function (b) { var host = b.closest('.dx-folder, .dx-file, tr'); if (host) host.classList.toggle('selected', b.checked); });
    }
    document.addEventListener('change', function (e) {
        var b = e.target;
        if (!b.classList || !b.classList.contains('dx-sel')) return;
        $$('.dx-sel').forEach(function (x) { if (x !== b && key(x) === key(b)) x.checked = b.checked; });
        update();
    });
    // A checkbox sits on a clickable tile: keep the click from opening the folder / preview.
    document.addEventListener('click', function (e) { if (e.target.closest('.dx-check')) e.stopPropagation(); }, true);
    document.getElementById('dxSelAll').addEventListener('click', function () {
        $$('.dx-sel').forEach(function (b) { var host = b.closest('.dx-folder, .dx-file, tr'); if (!host || !host.classList.contains('d-none')) b.checked = true; });
        update();
    });
    document.getElementById('dxSelClear').addEventListener('click', function () { $$('.dx-sel').forEach(function (b) { b.checked = false; }); update(); });
    document.getElementById('dxSelForm').addEventListener('submit', function () {
        var box = document.getElementById('dxSelInputs'); box.innerHTML = '';
        selected().forEach(function (b) {
            var i = document.createElement('input'); i.type = 'hidden';
            i.name = (b.dataset.kind === 'labour' ? 'labours' : 'documents') + '[]'; i.value = b.value; box.appendChild(i);
        });
        // The download happens in the background; re-enable the button after the browser has taken the file.
        var btn = document.getElementById('dxSelZip');
        setTimeout(function () { btn.disabled = false; btn.classList.remove('disabled'); }, 3000);
    });
    update();
})();
</script>
@endpush
