/**
 * Mga tab sa loob ng iisang container - iisa lang ang bukas.
 *
 *   <div data-sp-tabs>
 *     <button data-tab="a">A</button> <button data-tab="b">B</button>
 *     <div data-tab-empty>Pumili ng tab</div>
 *     <div data-pane="a" hidden>...</div> <div data-pane="b" hidden>...</div>
 *   </div>
 *
 * Walang bukas sa simula. Ang pagpili ng bagong tab ay nagsasara ng nakabukas;
 * ang pagpindot ulit sa bukas na tab (o sa [data-tab-close] sa loob ng pane)
 * ay nagsasara nito. Kapag may Chart.js chart sa pane, sinusukat itong muli
 * pagkabukas dahil nakatago ito noong ginawa.
 */
(function () {
    function setup(root) {
        const tabs = root.querySelectorAll('[data-tab]');
        const panes = root.querySelectorAll('[data-pane]');
        const empty = root.querySelector('[data-tab-empty]');
        let current = null;

        function show(name) {
            current = (current === name) ? null : name;

            tabs.forEach(function (tab) {
                const on = tab.dataset.tab === current;
                tab.classList.toggle('active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panes.forEach(function (pane) {
                const on = pane.dataset.pane === current;
                pane.hidden = !on;
                if (on && window.Chart && typeof Chart.getChart === 'function') {
                    pane.querySelectorAll('canvas').forEach(function (canvas) {
                        const chart = Chart.getChart(canvas);
                        if (chart) { requestAnimationFrame(function () { chart.resize(); }); }
                    });
                }
            });
            if (empty) { empty.hidden = current !== null; }
        }

        tabs.forEach(function (tab) {
            tab.setAttribute('role', 'tab');
            tab.addEventListener('click', function () { show(tab.dataset.tab); });
        });
        root.querySelectorAll('[data-tab-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const pane = btn.closest('[data-pane]');
                if (pane && pane.dataset.pane === current) { show(current); }
            });
        });
    }

    /**
     * Rectangle na nagiging buong panel - hiwalay ang bawat isa, kaya puwedeng
     * sabay-sabay bukas:
     *
     *   <section data-sp-expand>
     *     <button data-expand-open>Rectangle</button>
     *     <div data-expand-panel hidden> ... <button data-expand-close>×</button> </div>
     *   </section>
     *
     * Kapag pinindot ang rectangle, nawawala ito at ang panel ang lumalabas sa
     * parehong puwesto. Ang × lang ang nagbabalik sa rectangle.
     */
    function setupExpand(item) {
        const openBtn = item.querySelector('[data-expand-open]');
        const panel = item.querySelector('[data-expand-panel]');
        if (!openBtn || !panel) { return; }

        function setOpen(open) {
            openBtn.hidden = open;
            openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
            item.classList.toggle('is-open', open);

            // Kapag may bukas na item na may data-expand-side, nilalagyan ng
            // .has-side-open ang grupo nito ([data-sp-expand-group]) - dito
            // nakabatay ang CSS para ibalik ang panel sa dating puwesto nito.
            const group = item.closest('[data-sp-expand-group]');
            if (group) {
                group.classList.toggle('has-side-open',
                    !!group.querySelector('[data-sp-expand][data-expand-side].is-open'));
                group.classList.toggle('has-any-open',
                    !!group.querySelector('[data-sp-expand].is-open'));
            }

            if (open && window.Chart && typeof Chart.getChart === 'function') {
                panel.querySelectorAll('canvas').forEach(function (canvas) {
                    const chart = Chart.getChart(canvas);
                    if (chart) { requestAnimationFrame(function () { chart.resize(); }); }
                });
            }
            // Ibalik ang focus sa napindot na bagay para sa keyboard users
            if (open) {
                const closeBtn = panel.querySelector('[data-expand-close]');
                if (closeBtn) { closeBtn.focus({ preventScroll: true }); }
            } else {
                openBtn.focus({ preventScroll: true });
            }
        }

        openBtn.addEventListener('click', function () { setOpen(true); });
        panel.querySelectorAll('[data-expand-close]').forEach(function (btn) {
            btn.addEventListener('click', function () { setOpen(false); });
        });
    }

    function init() {
        document.querySelectorAll('[data-sp-tabs]').forEach(setup);
        document.querySelectorAll('[data-sp-expand]').forEach(setupExpand);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
