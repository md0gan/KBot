{{-- Otomatik yenileme: sayfayi periyodik yeniler. Kaydirma konumu korunur,
     sekme arka plandayken durur, tercih tarayicida saklanir. --}}
<div class="flex items-center gap-2 text-xs text-slate-500" id="kb-autorefresh">
    <label class="flex items-center gap-1.5 cursor-pointer select-none">
        <input type="checkbox" id="kb-ar-toggle" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
        <span>Otomatik yenile</span>
    </label>
    <select id="kb-ar-interval" class="rounded-md border-slate-300 text-xs py-1">
        <option value="10">10 sn</option>
        <option value="15">15 sn</option>
        <option value="30">30 sn</option>
        <option value="60">60 sn</option>
    </select>
    <span id="kb-ar-status" class="tabular-nums text-slate-400 w-10"></span>
</div>

<script>
(function () {
    var KEY = 'kb_autorefresh';
    var toggle = document.getElementById('kb-ar-toggle');
    var sel = document.getElementById('kb-ar-interval');
    var status = document.getElementById('kb-ar-status');
    if (!toggle || !sel) return;

    // Kayitli tercih (varsayilan: acik, 30 sn)
    var cfg = {};
    try { cfg = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {}
    toggle.checked = cfg.enabled !== false;
    sel.value = String(cfg.secs || 30);

    // Onceki yenilemeden kalan kaydirma konumunu geri yukle
    try {
        var y = sessionStorage.getItem('kb_ar_scroll');
        if (y !== null) { window.scrollTo(0, parseInt(y, 10)); sessionStorage.removeItem('kb_ar_scroll'); }
    } catch (e) {}

    var timer = null, remaining = 0;

    function save() {
        try { localStorage.setItem(KEY, JSON.stringify({ enabled: toggle.checked, secs: parseInt(sel.value, 10) })); } catch (e) {}
    }
    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
        if (status) status.textContent = toggle.checked ? '' : 'kapalı';
    }
    function tick() {
        remaining -= 1;
        if (status) status.textContent = remaining + ' sn';
        if (remaining <= 0) {
            try { sessionStorage.setItem('kb_ar_scroll', String(window.scrollY || window.pageYOffset || 0)); } catch (e) {}
            window.location.reload();
        }
    }
    function start() {
        stop();
        if (!toggle.checked) { if (status) status.textContent = 'kapalı'; return; }
        remaining = parseInt(sel.value, 10);
        if (status) status.textContent = remaining + ' sn';
        timer = setInterval(tick, 1000);
    }

    toggle.addEventListener('change', function () { save(); start(); });
    sel.addEventListener('change', function () { save(); start(); });
    // Sekme gizliyken sayma; geri donunce yeniden basla
    document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });

    start();
})();
</script>
