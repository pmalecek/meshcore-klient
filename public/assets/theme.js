// Motiv (světlý/tmavý) – volba se ukládá pro každé zařízení zvlášť.
// Režimy: light | dark | auto (podle systému) | sun (podle východu/západu slunce z HA)
(function () {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');

    function get(key, def) {
        try { var v = localStorage.getItem('mc.' + key); return v === null ? def : JSON.parse(v); } catch (e) { return def; }
    }

    // sun = {state: 'above_horizon'|'below_horizon', rising: ISO, setting: ISO}
    function sunIsDown(sun) {
        if (!sun || !sun.state || sun.state === 'unknown' || sun.state === 'unavailable') { return null; }
        var now = Date.now();
        var rising = sun.rising ? Date.parse(sun.rising) : NaN;
        var setting = sun.setting ? Date.parse(sun.setting) : NaN;
        // stav mohl mezitím přejít – rozhodnou časy dalšího východu/západu
        if (sun.state === 'below_horizon') { return !(rising && now >= rising); }
        return !!(setting && now >= setting);
    }

    function resolve() {
        var mode = get('theme', 'auto');
        if (mode === 'light' || mode === 'dark') { return mode; }
        if (mode === 'sun') {
            var down = sunIsDown(get('sun', null));
            if (down !== null) { return down ? 'dark' : 'light'; }
        }
        return mq.matches ? 'dark' : 'light';
    }

    function apply() {
        var t = resolve();
        document.documentElement.setAttribute('data-bs-theme', t);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) { meta.setAttribute('content', t === 'dark' ? '#212529' : '#0d6efd'); }
    }

    window.mcTheme = { apply: apply, resolve: resolve, sunIsDown: sunIsDown };
    apply();
    if (mq.addEventListener) { mq.addEventListener('change', apply); }
    setInterval(apply, 60000); // průběžná kontrola pro režim podle slunce
})();
