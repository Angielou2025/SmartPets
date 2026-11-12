<?php
// Global search bar para sa Admin at Adopter. Isinasama ito ng chat_bubble.php,
// na nasa bawat page nila, kaya kusang lumalabas ang search bar sa header
// (.top-header sa Admin, .top-navbar sa Adopter) nang hindi binabago ang bawat page.
require_once __DIR__ . '/app_url.php';
$spGsEndpoint = sp_base_url() . '/connection/global_search_data.php';
$spGsPlaceholder = (($_SESSION['role'] ?? '') === 'Admin')
    ? 'Search pets, applicants, staff, or pages...'
    : 'Search pets, my applications, or pages...';
?>
<style>
.sp-gsearch { position: relative; flex: 1 1 320px; max-width: 520px; min-width: 0; margin-right: auto; }
.sp-gsearch input {
    width: 100%; box-sizing: border-box; padding: 10px 14px 10px 38px;
    border-radius: 22px; border: 1px solid #e6e1d8; background: #fff;
    font: inherit; font-size: 14px; color: #102a2a; outline: none;
}
.sp-gsearch input:focus { border-color: #9cc5ac; box-shadow: 0 0 0 3px rgba(46, 91, 50, 0.12); }
.sp-gsearch .sp-gs-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #9ca3af; pointer-events: none; }
.sp-gs-results {
    position: absolute; top: calc(100% + 6px); left: 0; right: 0; max-height: 420px; overflow-y: auto;
    background: #fff; border: 1px solid #e6e1d8; border-radius: 14px;
    box-shadow: 0 18px 50px rgba(2, 6, 23, 0.18); z-index: 9000; padding: 6px; display: none; text-align: left;
}
.sp-gs-results.show { display: block; }
.sp-gs-group { padding: 8px 10px 4px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; color: #9ca3af; }
.sp-gs-item { display: flex; gap: 10px; align-items: flex-start; padding: 9px 10px; border-radius: 10px; text-decoration: none; color: #102a2a; }
.sp-gs-item:hover, .sp-gs-item.active { background: #f0f7f2; }
.sp-gs-item .ico { font-size: 16px; line-height: 1.3; }
.sp-gs-item .t { font-size: 13.5px; font-weight: 700; }
.sp-gs-item .d { font-size: 12px; color: #6b7280; margin-top: 1px; }
.sp-gs-empty { padding: 16px 12px; font-size: 13px; color: #6b7280; text-align: center; }
@media (max-width: 700px) {
    .sp-gsearch { flex-basis: 100%; max-width: none; order: 10; margin: 8px 0 0; }
}
</style>
<script>
(function () {
    const ENDPOINT = <?php echo json_encode($spGsEndpoint); ?>;
    const PLACEHOLDER = <?php echo json_encode($spGsPlaceholder); ?>;

    // Galing sa search (?q=...): punan at patakbuhin ang filter ng page, kung meron
    function applyPageFilter() {
        const q = new URLSearchParams(window.location.search).get('q');
        if (!q) return;
        const filter = document.getElementById('searchInput') || document.getElementById('staffSearchInput');
        if (!filter) return;
        filter.value = q;
        filter.dispatchEvent(new Event('input', { bubbles: true }));
        filter.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
    }

    function init() {
        const header = document.querySelector('.top-header') || document.querySelector('.top-navbar');
        if (!header || header.querySelector('.sp-gsearch')) { applyPageFilter(); return; }
        header.style.flexWrap = 'wrap';
        header.style.gap = header.style.gap || '12px';

        const wrap = document.createElement('div');
        wrap.className = 'sp-gsearch';
        // Simpleng outline na icon (hindi emoji), kasingkulay ng placeholder
        wrap.innerHTML = '<svg class="sp-gs-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="20" y1="20" x2="16.65" y2="16.65"></line></svg>'
            + '<input type="search" autocomplete="off" aria-label="Search">'
            + '<div class="sp-gs-results" role="listbox"></div>';
        const input = wrap.querySelector('input');
        const box = wrap.querySelector('.sp-gs-results');
        input.placeholder = PLACEHOLDER;

        // Adopter: pagkatapos ng mga link sa kaliwa; Admin: sa unahan ng header
        const links = header.querySelector('.top-nav-links');
        if (links) {
            links.insertAdjacentElement('afterend', wrap);
            wrap.style.marginLeft = '12px';
        } else {
            header.insertBefore(wrap, header.firstChild);
        }

        let timer = null;
        let lastQuery = '';
        let items = [];
        let active = -1;

        function hide() { box.classList.remove('show'); active = -1; }

        function setActive(i) {
            items.forEach(function (a, n) { a.classList.toggle('active', n === i); });
            active = i;
            if (items[i]) items[i].scrollIntoView({ block: 'nearest' });
        }

        function render(groups, q) {
            box.textContent = '';
            items = [];
            active = -1;
            if (!groups.length) {
                const empty = document.createElement('div');
                empty.className = 'sp-gs-empty';
                empty.textContent = 'No results for "' + q + '".';
                box.appendChild(empty);
            }
            groups.forEach(function (g) {
                const head = document.createElement('div');
                head.className = 'sp-gs-group';
                head.textContent = g.label;
                box.appendChild(head);
                g.items.forEach(function (item) {
                    const a = document.createElement('a');
                    a.className = 'sp-gs-item';
                    a.href = item.url;
                    a.setAttribute('role', 'option');
                    const ico = document.createElement('span');
                    ico.className = 'ico';
                    ico.textContent = item.icon;
                    const body = document.createElement('div');
                    const t = document.createElement('div');
                    t.className = 't';
                    t.textContent = item.title;
                    const d = document.createElement('div');
                    d.className = 'd';
                    d.textContent = item.detail;
                    body.appendChild(t);
                    body.appendChild(d);
                    a.appendChild(ico);
                    a.appendChild(body);
                    box.appendChild(a);
                    items.push(a);
                });
            });
            box.classList.add('show');
        }

        function search() {
            const q = input.value.trim();
            if (q === '') { hide(); return; }
            lastQuery = q;
            fetch(ENDPOINT + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : { groups: [] }; })
                .then(function (data) { if (q === lastQuery) render(data.groups || [], q); })
                .catch(function () { /* offline - walang ipapakita */ });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 250);
        });
        input.addEventListener('focus', function () {
            if (input.value.trim() !== '' && box.childElementCount) box.classList.add('show');
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); setActive(Math.min(active + 1, items.length - 1)); }
            else if (e.key === 'ArrowUp' && items.length) { e.preventDefault(); setActive(Math.max(active - 1, 0)); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                const target = items[active] || items[0];
                if (target) window.location.href = target.href;
            } else if (e.key === 'Escape') { hide(); }
        });
        document.addEventListener('mousedown', function (e) { if (!wrap.contains(e.target)) hide(); });

        applyPageFilter();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
