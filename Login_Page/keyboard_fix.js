// Sa telepono, natatakpan ng keyboard ang input na sinusulatan. Kapag may
// pinindot na input, hihintayin munang lumabas ang keyboard, saka ili-scroll
// ang input sa gitna ng natitirang nakikitang bahagi ng screen.
(function () {
    function isField(el) {
        return el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT');
    }

    function revealActive() {
        var el = document.activeElement;
        if (isField(el)) {
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    document.addEventListener('focusin', function (e) {
        if (isField(e.target)) {
            setTimeout(revealActive, 300);
        }
    });

    // Lumiit ang nakikitang bahagi ng screen = lumabas ang keyboard
    if (window.visualViewport) {
        var lastHeight = window.visualViewport.height;
        window.visualViewport.addEventListener('resize', function () {
            var h = window.visualViewport.height;
            if (h < lastHeight - 100) {
                revealActive();
            }
            lastHeight = h;
        });
    }
})();
