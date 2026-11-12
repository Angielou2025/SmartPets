<?php
/**
 * Pagination ng LAHAT ng table sa mga Admin page - walang reload.
 * Isinasama ng connection/chat_bubble.php (ang iisang include na nasa bawat page),
 * kaya hindi na kailangang baguhin ang bawat page.
 *
 * - 10 row bawat page (palitan sa table: data-per-page="5"; data-no-paginate = huwag)
 * - Sumasabay sa search/filter ng page: ang mga row na itinago ng filter
 *   (style="display:none" o hidden) ay hindi binibilang, at bumabalik sa page 1
 *   kapag nagbago ang filter.
 * - Lumalabas lang ang pagination kapag lampas sa isang page ang mga row.
 */
?>
<style>
    tr.sp-pg-off { display: none !important; }

    /* May pagination na: wala nang scroll sa loob ng lalagyan ng table (dating may
       max-height at scrollbar). Kapag mas malapad ang table, puwede pa ring i-swipe
       pahalang pero walang nakikitang scrollbar. */
    .sp-pg-wrap {
        max-height: none !important;
        overflow-y: visible !important;
        overflow-x: auto !important;
        scrollbar-width: none;
    }
    .sp-pg-wrap::-webkit-scrollbar { display: none; }

    .sp-pager {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px 16px;
        padding: 12px 4px 2px;
    }
    .sp-pager[hidden] { display: none; }
    .sp-pager-info { font-size: 13px; color: #6b7280; }
    .sp-pager-nav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
    .sp-pager-nav button,
    .sp-pager-nav .sp-pager-gap {
        min-width: 32px;
        height: 32px;
        padding: 0 8px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        font-family: inherit;
        font-size: 13px;
        font-weight: 700;
    }
    .sp-pager-nav button {
        border: 1px solid #dfe6e2;
        background: #fff;
        color: #2d6a4f;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease;
    }
    .sp-pager-nav button:not(:disabled):not(.current):hover { background: #eef5f0; }
    .sp-pager-nav button.current { background: #2d6a4f; border-color: #2d6a4f; color: #fff; cursor: default; }
    .sp-pager-nav button:disabled:not(.current) { color: #cbd5e1; border-color: #eef0f2; cursor: default; }
    .sp-pager-nav .sp-pager-gap { color: #9ca3af; min-width: 20px; padding: 0; }

    tbody.sp-pg-anim tr:not(.sp-pg-off) { animation: spPgIn 0.25s ease-out; }
    @keyframes spPgIn {
        from { opacity: 0; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @media (prefers-reduced-motion: reduce) {
        tbody.sp-pg-anim tr:not(.sp-pg-off) { animation: none; }
    }
</style>
<script>
(function () {
    var DEFAULT_PER_PAGE = 10;

    function pageList(current, pages) {
        // Hal. 1 … 4 5 6 … 20 kapag marami ang page
        if (pages <= 7) {
            var all = [];
            for (var i = 1; i <= pages; i++) all.push(i);
            return all;
        }
        var list = [1];
        var from = Math.max(2, current - 1);
        var to = Math.min(pages - 1, current + 1);
        if (current <= 3) { from = 2; to = 4; }
        if (current >= pages - 2) { from = pages - 3; to = pages - 1; }
        if (from > 2) list.push('…');
        for (var p = from; p <= to; p++) list.push(p);
        if (to < pages - 1) list.push('…');
        list.push(pages);
        return list;
    }

    function setup(table) {
        var tbody = table.tBodies[0];
        if (!tbody || table.dataset.spPaged) return;
        table.dataset.spPaged = '1';
        var per = parseInt(table.dataset.perPage, 10) || DEFAULT_PER_PAGE;
        var current = 1;

        var pager = document.createElement('div');
        pager.className = 'sp-pager';
        pager.hidden = true;
        pager.innerHTML = '<span class="sp-pager-info"></span><nav class="sp-pager-nav" aria-label="Table pages"></nav>';
        var info = pager.querySelector('.sp-pager-info');
        var nav = pager.querySelector('.sp-pager-nav');
        // Sa ilalim ng lalagyan ng table (para hindi sumama sa scroll nito)
        var anchor = table.closest('.table-container, .table-wrapper, .table-responsive, .payment-table-wrap') || table;
        anchor.insertAdjacentElement('afterend', pager);
        if (anchor !== table) anchor.classList.add('sp-pg-wrap');

        // Kasama sa bilang: hindi itinago ng filter ng page at hindi "walang laman" na row
        function eligible(row) {
            if (row.hasAttribute('hidden') || row.style.display === 'none') return false;
            return !(row.cells.length === 1 && row.cells[0].colSpan > 1);
        }

        function button(label, page, opts) {
            var b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = label;
            if (opts.aria) b.setAttribute('aria-label', opts.aria);
            if (opts.current) { b.className = 'current'; b.setAttribute('aria-current', 'page'); }
            b.disabled = !!(opts.disabled || opts.current);
            b.addEventListener('click', function () {
                current = page;
                render(true);
                // Manatiling kita ang table kapag mahaba ang page
                var top = table.getBoundingClientRect().top;
                if (top < 0) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            return b;
        }

        function render(animate) {
            var rows = Array.prototype.slice.call(tbody.rows);
            var list = rows.filter(eligible);
            var pages = Math.max(1, Math.ceil(list.length / per));
            current = Math.min(Math.max(1, current), pages);
            var start = (current - 1) * per;
            var end = start + per;

            rows.forEach(function (r) { r.classList.remove('sp-pg-off'); });
            if (list.length > per) {
                list.forEach(function (r, i) {
                    if (i < start || i >= end) r.classList.add('sp-pg-off');
                });
            }

            tbody.classList.remove('sp-pg-anim');
            if (animate) { void tbody.offsetWidth; tbody.classList.add('sp-pg-anim'); }

            pager.hidden = list.length <= per;
            if (pager.hidden) return;
            info.textContent = 'Showing ' + (start + 1) + '–' + Math.min(end, list.length) + ' of ' + list.length;
            nav.innerHTML = '';
            nav.appendChild(button('<i class="fa-solid fa-chevron-left"></i>', current - 1, { aria: 'Previous page', disabled: current === 1 }));
            pageList(current, pages).forEach(function (p) {
                if (p === '…') {
                    var gap = document.createElement('span');
                    gap.className = 'sp-pager-gap';
                    gap.textContent = '…';
                    nav.appendChild(gap);
                } else {
                    nav.appendChild(button(String(p), p, { current: p === current }));
                }
            });
            nav.appendChild(button('<i class="fa-solid fa-chevron-right"></i>', current + 1, { aria: 'Next page', disabled: current === pages }));
        }

        // Kapag nag-filter/search ang page (o nagdagdag/nagbura ng row): balik sa page 1
        var queued = false;
        new MutationObserver(function (mutations) {
            var relevant = mutations.some(function (m) {
                return m.type === 'childList' || m.attributeName === 'style' || m.attributeName === 'hidden';
            });
            if (!relevant || queued) return;
            queued = true;
            requestAnimationFrame(function () {
                queued = false;
                current = 1;
                render(false);
            });
        }).observe(tbody, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'hidden'] });

        render(false);
    }

    function init() {
        document.querySelectorAll('.main-wrapper table, .modal table, [class*="modal"] table').forEach(function (t) {
            if (t.closest('[data-no-paginate]') || t.hasAttribute('data-no-paginate')) return;
            setup(t);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
