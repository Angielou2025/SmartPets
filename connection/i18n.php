<?php
/**
 * English / Tagalog para sa buong panig ng Adopter at Staff
 * (Staff: may dagdag na connection/i18n_dict_staff.php).
 *
 * Isinasama ng connection/chat_bubble.php (at ng ai_recommendation.php) sa
 * bawat Adopter page. Ang wika ay ang pinili ng adopter sa Account Settings
 * (users.language, tingnan ang sp_user_lang() sa database.php).
 *
 * Paano: pagka-load, at sa bawat bagong text na idinadagdag ng JavaScript,
 * pinapalitan ang mga kilalang text (connection/i18n_dict.php) - pati ang
 * placeholder, title at aria-label. Hindi ginagalaw ang value na ipinapasa ng
 * mga form, ang mga isinulat ng tao, at ang nasa loob ng [data-no-i18n].
 * window.spSetLang('en'|'tl') = agad na palitan ang wika ng buong page.
 */
if (!empty($GLOBALS['sp_i18n_rendered']) || !isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Adopter', 'Staff'], true)) {
    return;
}
$GLOBALS['sp_i18n_rendered'] = true;

$spI18nLang = (isset($pdo) && function_exists('sp_user_lang')) ? sp_user_lang($pdo, (int) $_SESSION['user_id']) : 'en';
$spI18nDict = require __DIR__ . '/i18n_dict.php';
// Staff: dagdag na mga text ng staff pages sa ibabaw ng karaniwang diksyunaryo
if (($_SESSION['role'] ?? '') === 'Staff') {
    $spI18nStaff = require __DIR__ . '/i18n_dict_staff.php';
    foreach (['pairs', 'en2tl', 'tl2en'] as $spI18nKey) {
        $spI18nDict[$spI18nKey] = array_merge($spI18nDict[$spI18nKey] ?? [], $spI18nStaff[$spI18nKey] ?? []);
    }
}
?>
<script>
(function () {
    var LANG = <?php echo json_encode($spI18nLang); ?>;
    var DICT = <?php echo json_encode($spI18nDict, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    function norm(s) { return s.replace(/\s+/g, ' ').trim(); }

    // Mapa ayon sa wikang gusto: tl = English -> Tagalog, en = Tagalog -> English
    var MAPS = { tl: new Map(), en: new Map() };
    DICT.pairs.forEach(function (p) {
        var en = norm(p[0]), tl = norm(p[1]);
        if (en !== tl) {
            MAPS.tl.set(en, tl);
            if (!MAPS.en.has(tl)) MAPS.en.set(tl, en);
        }
    });
    function compile(list) {
        return (list || []).map(function (p) { return [new RegExp(p[0]), p[1]]; });
    }
    var PATTERNS = { tl: compile(DICT.en2tl), en: compile(DICT.tl2en) };

    function translate(text) {
        var key = norm(text);
        if (!key || !/[A-Za-zÀ-ÿ]/.test(key)) return null;
        var hit = MAPS[LANG].get(key);
        if (hit !== undefined) return hit;
        var pats = PATTERNS[LANG];
        for (var i = 0; i < pats.length; i++) {
            if (pats[i][0].test(key)) return key.replace(pats[i][0], pats[i][1]);
        }
        return null;
    }
    // Pinapanatili ang espasyo sa unahan/hulihan para hindi magdikit ang mga salita
    function withSpaces(original, translated) {
        return original.match(/^\s*/)[0] + translated + original.match(/\s*$/)[0];
    }

    var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEXTAREA: 1, CODE: 1, PRE: 1, svg: 1, SVG: 1 };
    var ATTRS = ['placeholder', 'title', 'aria-label'];
    var origText = new WeakMap();   // text node -> orihinal na text (para sa pagpapalit ng wika)
    var origAttr = new WeakMap();   // element -> { attr: orihinal }
    var mine = new WeakMap();       // text node -> text na kami ang naglagay (para hindi ulitin)

    function skipped(el) {
        for (var e = el; e && e.nodeType === 1; e = e.parentNode) {
            if (SKIP_TAGS[e.tagName] || e.isContentEditable || (e.hasAttribute && e.hasAttribute('data-no-i18n'))) return true;
        }
        return false;
    }

    function doText(node) {
        var parent = node.parentNode;
        if (!parent || skipped(parent)) return;
        if (mine.get(node) === node.nodeValue) return;      // kami mismo ang naglagay nito
        var source = node.nodeValue;
        origText.set(node, source);
        // <option> na walang value: ang text mismo ang ipinapasa, kaya itabi muna bilang value
        if (parent.tagName === 'OPTION' && !parent.hasAttribute('value')) parent.setAttribute('value', norm(parent.textContent));
        var t = translate(source);
        var next = t === null ? source : withSpaces(source, t);
        if (next !== source) node.nodeValue = next;
        mine.set(node, next);
    }

    function doAttrs(el) {
        // Ang laman ng TEXTAREA ay hindi ginagalaw, pero ang placeholder/title nito ay isinasalin
        if (el.tagName === 'TEXTAREA' ? skipped(el.parentNode) : skipped(el)) return;
        var store = origAttr.get(el) || {};
        var names = ATTRS.slice();
        if (el.tagName === 'INPUT' && /^(button|submit|reset)$/i.test(el.type)) names.push('value');
        names.forEach(function (name) {
            if (!el.hasAttribute(name)) return;
            var cur = el.getAttribute(name);
            if (store['_' + name] === cur) return;           // kami ang naglagay
            store[name] = cur;
            var t = translate(cur);
            var next = t === null ? cur : t;
            if (next !== cur) el.setAttribute(name, next);
            store['_' + name] = next;
        });
        origAttr.set(el, store);
    }

    function walk(root) {
        if (!root) return;
        if (root.nodeType === 3) { doText(root); return; }
        if (root.nodeType !== 1) return;
        if (SKIP_TAGS[root.tagName]) { if (root.tagName === 'TEXTAREA') doAttrs(root); return; }
        doAttrs(root);
        var tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
        var n;
        while ((n = tw.nextNode())) {
            if (n.nodeType === 3) doText(n);
            else doAttrs(n);
        }
    }

    function doTitle() {
        var t = translate(document.title.replace(/^SMART PET - /, ''));
        if (t !== null) document.title = 'SMART PET - ' + t;
    }

    // Unang pagsasalin + bantay sa mga bagong text (gawa ng JavaScript)
    function start() {
        document.documentElement.lang = LANG === 'tl' ? 'tl' : 'en';
        walk(document.body);
        doTitle();
        new MutationObserver(function (records) {
            records.forEach(function (r) {
                if (r.type === 'characterData') doText(r.target);
                else if (r.type === 'attributes') doAttrs(r.target);
                else r.addedNodes.forEach(walk);
            });
        }).observe(document.body, {
            childList: true, subtree: true, characterData: true,
            attributes: true, attributeFilter: ['placeholder', 'title', 'aria-label', 'value']
        });
    }

    // Palitan ang wika ng buong page (hal. pagka-save sa Account Settings)
    window.spSetLang = function (lang) {
        if (lang !== 'en' && lang !== 'tl') return;
        LANG = lang;
        document.documentElement.lang = lang;
        var tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
        var n;
        while ((n = tw.nextNode())) {
            if (n.nodeType === 3) {
                if (origText.has(n) && mine.get(n) === n.nodeValue) n.nodeValue = origText.get(n);
                mine.delete(n);
                doText(n);
            } else {
                var store = origAttr.get(n);
                if (store) {
                    ATTRS.concat('value').forEach(function (name) {
                        if (name in store && store['_' + name] === n.getAttribute(name)) n.setAttribute(name, store[name]);
                        delete store['_' + name];
                    });
                }
                doAttrs(n);
            }
        }
        doTitle();
    };
    // Para sa JavaScript na gumagawa ng alert/confirm: window.spT('English text')
    window.spT = function (text) { var t = translate(text); return t === null ? text : t; };

    if (document.body) start();
    else document.addEventListener('DOMContentLoaded', start);
})();
</script>
