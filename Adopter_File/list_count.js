/**
 * Para sa mga listahan ng card (Dashboard, Search Pets, Favorites, My Applications):
 *   1) bilang sa tabi ng title, at  2) reveal habang nag-i-scroll (nasa ibaba).
 *
 * Bilang sa tabi ng title ng listahan (hal. "Available Pets  [22 pets]").
 *
 *   <section class="sp-scroll-host">
 *     <h2 class="sp-list-head">... <span class="sp-count" data-word="pet"></span></h2>
 *     <div class="pets-grid"> .pet-card ... </div>
 *   </section>
 *
 * Binibilang ang mga nakikitang .pet-card, at kusang nag-a-update kapag
 * nag-filter/nag-search (search_pets) o nagbago ang laman (favorites).
 */
(function () {
    function setup(host) {
        var pill = host.querySelector('.sp-count');
        var grid = host.querySelector('.pets-grid');
        if (!pill) return;
        var word = pill.getAttribute('data-word') || 'item';

        function update() {
            var n = 0;
            if (grid) {
                grid.querySelectorAll('.pet-card').forEach(function (c) {
                    if (c.style.display !== 'none' && !c.hidden) n++;
                });
            }
            pill.textContent = n + ' ' + word + (n === 1 ? '' : 's');
            pill.hidden = n === 0;
        }

        update();
        if (grid && window.MutationObserver) {
            new MutationObserver(update).observe(grid, {
                childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'hidden']
            });
        }
    }

    function init() { document.querySelectorAll('.sp-scroll-host').forEach(setup); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

/**
 * Reveal habang nag-i-scroll: ang card na lumilitaw mula sa ibaba ay umaangat at
 * lumilinaw (CSS transition sa dashboard.css, .sp-reveal / .is-in) - kusang
 * tumatakbo nang ~0.8s kaya smooth kahit paano mag-scroll, at buo nang malinaw
 * ang huling hanay pagdating sa dulo. Kapag bumaba ulit ang card (scroll pataas),
 * itinatago ulit para umangat ulit sa susunod.
 */
(function () {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    // Ginagamit ang totoong puwesto ng card (offsetTop), hindi ang nakikita, para hindi
    // malito habang nakababa pa ito (translate) dahil sa animation.
    function setup(grid) {
        var queued = false;

        function check() {
            queued = false;
            var g = grid.getBoundingClientRect();
            var gridScrolls = grid.scrollHeight > grid.clientHeight + 1 &&
                              /(auto|scroll)/.test(getComputedStyle(grid).overflowY);
            var visTop = gridScrolls ? Math.max(g.top, 0) : 0;
            var visBottom = gridScrolls ? Math.min(g.bottom, window.innerHeight) : window.innerHeight;

            var coming = [];
            grid.querySelectorAll('.pet-card').forEach(function (card) {
                if (!card.classList.contains('sp-reveal')) card.classList.add('sp-reveal');
                if (card.offsetParent === null) return;           // nakatago (filter)
                var top = g.top + card.offsetTop - grid.scrollTop;
                var bottom = top + card.offsetHeight;
                if (top < visBottom - 24 && bottom > visTop) {
                    if (!card.classList.contains('is-in')) coming.push(card);
                } else if (top >= visBottom) {
                    // Nasa ibaba ulit (nag-scroll pataas): itago para umangat ulit mamaya
                    card.classList.remove('is-in');
                }
            });

            // Sabay na lumilitaw: sunod-sunod nang kaunti (kaliwa pakanan, itaas pababa)
            coming.forEach(function (card, i) {
                card.style.transitionDelay = Math.min(i, 5) * 70 + 'ms';
                card.classList.add('is-in');
                setTimeout(function () { card.style.transitionDelay = ''; }, 900 + i * 70);
            });
        }
        function schedule() {
            if (!queued) { queued = true; requestAnimationFrame(check); }
        }

        grid.style.position = grid.style.position || 'relative';  // para sa offsetTop ng card
        check();
        grid.addEventListener('scroll', schedule, { passive: true });
        document.addEventListener('scroll', schedule, { passive: true, capture: true });  // scroll ng page (mobile)
        window.addEventListener('resize', schedule);
        window.addEventListener('load', schedule);
        // Favorites (ginagawa ng JS ang card) at search/filter (pinapakita/tinatago)
        if (window.MutationObserver) {
            new MutationObserver(schedule).observe(grid, {
                childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'hidden']
            });
        }
    }

    function init() { document.querySelectorAll('.sp-scroll-host > .pets-grid').forEach(setup); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
