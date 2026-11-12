// Paglipat sa pagitan ng Login at Sign Up nang walang reload.
// Iisang page lang ang dalawa (_auth_page.php): pinapalitan lang ang class ng
// card, kaya tuloy-tuloy ang pag-dulas ng welcome panel. Pinapalitan din ang
// URL (login.php <-> register.php) para gumana ang Back button at refresh.
(function () {
    var card = document.getElementById('authCard');
    if (!card) return;

    var paneLogin = card.querySelector('.pane-login');
    var paneRegister = card.querySelector('.pane-register');
    var introLogin = card.querySelector('.intro-login');
    var introRegister = card.querySelector('.intro-register');
    var URLS = { login: 'login.php', register: 'register.php' };
    var TITLES = { login: 'Smart Pets - Login', register: 'Smart Pets - Sign Up' };

    function currentMode() {
        return card.classList.contains('show-register') ? 'register' : 'login';
    }

    function setMode(mode, push) {
        if (mode === currentMode()) return;
        var toRegister = mode === 'register';

        card.classList.toggle('show-register', toRegister);

        // Hindi maaabot ng Tab ang form na nakatago
        paneRegister.inert = !toRegister;
        paneLogin.inert = toRegister;
        introRegister.setAttribute('aria-hidden', String(!toRegister));
        introLogin.setAttribute('aria-hidden', String(toRegister));

        document.title = TITLES[mode];
        if (push) history.pushState({ authMode: mode }, '', URLS[mode]);
    }

    document.querySelectorAll('a[data-auth-swap]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            setMode(link.dataset.authSwap, true);
        });
    });

    history.replaceState({ authMode: currentMode() }, '');

    window.addEventListener('popstate', function (e) {
        var mode = e.state && e.state.authMode
            ? e.state.authMode
            : (/register\.php$/.test(location.pathname) ? 'register' : 'login');
        setMode(mode, false);
    });
})();
