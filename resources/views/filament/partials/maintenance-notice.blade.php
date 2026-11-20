<div id="lux-maintenance-notice" role="status" style="display:none; position:fixed; z-index:1000; top:0; left:0; right:0; padding:.65rem 1rem; background:#b91c1c; color:white; text-align:center; font-size:.875rem;"></div>
<script>
    (() => {
        const banner = document.getElementById('lux-maintenance-notice');
        const check = async () => {
            try {
                const response = await fetch(@js(route('maintenance.status')), {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
                if (!response.ok) return;
                const status = await response.json();
                if (status.signed_out || status.active) {
                    window.location.assign(@js(route('maintenance.notice')));
                    return;
                }
                banner.style.display = status.enabled ? 'block' : 'none';
                if (status.enabled) banner.textContent = `LuxQuote will be undergoing scheduled maintenance and updates at: ${status.start}. If you are logged in at that time, you will be logged out. You should be able to log back in at: ${status.end}.`;
            } catch (_) {}
        };
        check();
        setInterval(check, 10000);
    })();
</script>
