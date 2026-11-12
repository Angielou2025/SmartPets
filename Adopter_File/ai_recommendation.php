<?php
session_start();
require '../connection/database.php';
require '../connection/match_scoring.php';

// Siguraduhing naka-login ang adopter bago ma-access ang pahina
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$prefCheck = $pdo->prepare('SELECT 1 FROM adopter_preferences WHERE user_id = ?');
$prefCheck->execute([$user_id]);
$hasPreferences = (bool) $prefCheck->fetchColumn();

// Chat-triggered recommendations: when user asks for suggestions, JS calls
// this endpoint with ?refresh=1 to get the latest matches.
// ?exclude=1,2,3 = mga pet na naipakita na ("Show other pets")
if (isset($_GET['refresh'])) {
    header('Content-Type: application/json');
    // Walang preferences = walang recommendation
    if (!$hasPreferences) {
        echo json_encode(['pets' => [], 'reply' => 'Please fill up your preferences in "My preference" first before using the AI Recommendation.']);
        exit();
    }
    $exclude = array_filter(array_map('intval', explode(',', (string) ($_GET['exclude'] ?? ''))));
    echo json_encode(sp_adopter_recommendations($pdo, $user_id, $exclude));
    exit();
}

$sp_unread_count = sp_unread_message_count($pdo, (int) $_SESSION['user_id']);
$stmt = $pdo->prepare("SELECT username, email, recovery_email, profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - AI Recommendation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <link rel="stylesheet" href="ai_recommendation.css?v=<?php echo @filemtime(__DIR__ . '/ai_recommendation.css'); ?>">
</head>
<body style="background-color: #1b4d2e;">

    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="brand">
                <div class="logo-circle">
                    <img src="../Images/caloocan_logo.jpg" alt="City Logo">
                </div>
                <h2>SMART <span>PET</span></h2>
            </div>

            <nav class="nav-menu">
                <a href="adopter_dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a href="adopter_preferences.php" class="nav-item"><i class="fa-solid fa-clipboard-list"></i> My preference</a>
                <a href="ai_recommendation.php" class="nav-item active"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Recommendation</a>
                <a href="my_pets.php" class="nav-item"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="favorites.php" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="messages.php" class="nav-item"><i class="fa-solid fa-comment-dots"></i> Message<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?></a>
                <?php $sp_notif_count = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']); ?>
                <a href="payments.php" class="nav-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
            </nav>

            <div class="sidebar-illustration">
                <img src="../Images/bg_design_pets.png" alt="Pets Illustration" onerror="this.style.display='none'">
            </div>
        </aside>

        <main class="main-content">
            <header class="top-navbar">
                <div class="top-nav-links">
                    <a href="search_pets.php">Search Pets</a>
                    <a href="shelter.php">Location</a>
                    <a href="docs.php">Certificate</a>
                </div>
                <a href="edit_profile.php" class="user-profile-link" style="text-decoration: none; color: inherit;">
                    <div class="user-profile">
                        <div class="profile-avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                            <?php if (!empty($profile_image)): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
                            <?php endif; ?>
                        </div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </a>
            </header>

            <?php if (!$hasPreferences): ?>
            <?php // Hindi pa napupunan ang preferences: popup lang, hindi muna ipinapakita ang AI Recommendation ?>
            <div id="prefsRequiredPopup" role="dialog" aria-modal="true" aria-labelledby="prefsRequiredTitle"
                 style="position:fixed; inset:0; z-index:9999; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,0.55);">
                <div style="background:#fff; border-radius:16px; max-width:420px; width:100%; padding:32px 26px; text-align:center; box-shadow:0 12px 40px rgba(0,0,0,0.3);">
                    <i class="fa-solid fa-clipboard-list" style="font-size:42px; color:#f28b20; margin-bottom:14px;"></i>
                    <h2 id="prefsRequiredTitle" style="color:#1b4d2e; margin-bottom:12px;">Before you proceed</h2>
                    <p style="color:#555; margin-bottom:22px;">You need to fill up your preferences first before you can use the AI Recommendation.</p>
                    <a href="adopter_preferences.php" class="btn-orange">Fill Up Preferences</a>
                    <a href="adopter_dashboard.php" class="btn-text" style="display:block; margin-top:12px;">Back to Dashboard</a>
                </div>
            </div>
            <?php else: ?>
            <section class="featured-section">
                <div class="ai-chat-container">
                    <!-- Hamburger Menu Button -->
                    <button type="button" id="aiHistoryToggle" class="ai-history-toggle" aria-label="Toggle history">
                        <i class="fa-solid fa-bars"></i>
                    </button>

                    <!-- Main Chat Panel -->
                    <div class="ai-chat-panel-full">
                        <div id="aiHistoryBackdrop" class="ai-history-backdrop"></div>

                        <!-- History Drawer (Hamburger Menu) -->
                        <div id="aiHistoryDrawer" class="ai-history-drawer">
                            <div class="ai-history-drawer-header">
                                <h3>Conversations</h3>
                                <button type="button" id="aiNewChat" class="ai-new-chat-btn" title="Start new">
                                    <i class="fa-solid fa-plus"></i> New
                                </button>
                            </div>
                            <div id="aiHistoryList" class="ai-history-list">
                                <div class="ai-history-empty">
                                    <i class="fa-solid fa-comments"></i>
                                    <p>Start a new conversation</p>
                                </div>
                            </div>
                            <div class="ai-history-footer">
                                <button type="button" id="aiClearAll" class="ai-clear-all-btn" hidden>
                                    <i class="fa-solid fa-trash-can"></i> Clear all conversations
                                </button>
                            </div>
                        </div>

                        <div class="ai-chat-header">
                            <svg class="ai-chat-logo" viewBox="0 0 64 64" aria-hidden="true">
                                <ellipse cx="11" cy="26" rx="7" ry="9" transform="rotate(-24 11 26)"/>
                                <ellipse cx="24" cy="14" rx="6.5" ry="8.5" transform="rotate(-8 24 14)"/>
                                <ellipse cx="40" cy="14" rx="6.5" ry="8.5" transform="rotate(8 40 14)"/>
                                <ellipse cx="53" cy="26" rx="7" ry="9" transform="rotate(24 53 26)"/>
                                <path d="M32 30c8 0 16 7 16 16s-8 12-16 12-16-3-16-12 8-16 16-16z"/>
                            </svg>
                            <div class="ai-chat-heading">
                                <span class="ai-chat-title">
                                    <span class="ai-word-smart">SMART</span> <span class="ai-word-pet">PET</span> <span class="ai-word-rest">Assistant</span>
                                </span>
                                <span class="ai-chat-sub"><span class="ai-online-dot"></span> Matches pets to your lifestyle</span>
                            </div>
                            <?php // Telepono lang: isasara ang chat at bubuksan ang sidebar para pumili ng ibang page ?>
                            <button type="button" class="ai-exit-btn" id="aiExitBtn" title="Close and open menu" aria-label="Close and open menu">&times;</button>
                        </div>
                        <div id="aiChatMessages" class="ai-chat-messages-full"></div>
                        <div class="ai-chat-chips">
                            <button type="button" class="ai-chip" data-action="recommend">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Recommend now
                            </button>
                            <button type="button" class="ai-chip" data-action="help">
                                <i class="fa-solid fa-circle-question"></i> How does this work?
                            </button>
                        </div>
                        <div class="ai-chat-input-row">
                            <input type="text" id="aiChatInput" placeholder="Ask a question or request recommendations..." autocomplete="off">
                            <button type="button" id="aiChatSend" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($hasPreferences): ?>
    <!-- Bakit bagay sa adopter ang isang pet - modal na may AI-generated na paliwanag -->
    <div id="aiExplainOverlay" class="ai-explain-overlay" aria-hidden="true">
        <div class="ai-explain-modal">
            <div class="ai-explain-head">
                <h3 id="aiExplainName"></h3>
                <button type="button" id="aiExplainClose" class="ai-explain-close" aria-label="Close">&times;</button>
            </div>
            <?php // Maikling video kung paano na-rescue ang pet (in-upload ng staff) ?>
            <div class="ai-story" id="aiStory" hidden>
                <span class="ai-explain-tag ai-story-tag"><i class="fa-solid fa-heart"></i> Rescue story</span>
                <video id="aiStoryVideo" class="ai-story-video" controls playsinline preload="metadata"></video>
            </div>
            <div class="ai-explain-tagrow">
                <span class="ai-explain-tag">Veterinarian's assessment</span>
                <?php // Nakatago: ang wika ng paliwanag ay ang pinili sa Account Settings ?>
                <button type="button" id="aiExplainLang" class="ai-explain-lang" hidden>Tagalog</button>
            </div>
            <div id="aiExplainBody" class="ai-explain-body"></div>
        </div>
    </div>

    <!-- Bakit ganito ang match % - puntos ng bawat criterion -->
    <div id="aiScoreOverlay" class="ai-explain-overlay" aria-hidden="true">
        <div class="ai-explain-modal">
            <div class="ai-explain-head">
                <h3 id="aiScoreTitle"></h3>
                <button type="button" id="aiScoreClose" class="ai-explain-close" aria-label="Close">&times;</button>
            </div>
            <div id="aiScoreBody" class="ai-score-body"></div>
        </div>
    </div>

    <script>
    (function () {
        const EXPLAIN_URL = 'ai_explain_pet.php?pet_id=';
        const ADOPTER = {
            name: <?php echo json_encode($username); ?>,
            photo: <?php echo json_encode($profile_image ? '../uploads/' . $profile_image : ''); ?>
        };
        const overlay = document.getElementById('aiExplainOverlay');
        const nameEl = document.getElementById('aiExplainName');
        const bodyEl = document.getElementById('aiExplainBody');
        const langBtn = document.getElementById('aiExplainLang');
        const closeBtn = document.getElementById('aiExplainClose');
        const chatMessages = document.getElementById('aiChatMessages');
        const chatInput = document.getElementById('aiChatInput');
        const chatSend = document.getElementById('aiChatSend');
        const historyToggle = document.getElementById('aiHistoryToggle');
        const historyDrawer = document.getElementById('aiHistoryDrawer');
        const historyList = document.getElementById('aiHistoryList');
        const newChatBtn = document.getElementById('aiNewChat');
        const historyBackdrop = document.getElementById('aiHistoryBackdrop');
        const clearAllBtn = document.getElementById('aiClearAll');

        function setDrawer(open) {
            historyDrawer.classList.toggle('open', open);
            historyBackdrop.classList.toggle('open', open);
        }

        const cache = {};
        let currentPetId = null;
        // Wika ng paliwanag = wikang pinili ng adopter sa Account Settings
        let lang = <?php echo json_encode(function_exists('sp_user_lang') ? sp_user_lang($pdo, (int) $_SESSION['user_id']) : 'en'); ?>;
        const HAS_PREFERENCES = <?php echo json_encode($hasPreferences); ?>;
        let chatHistory = [];
        let chatBusy = false;
        let conversations = [];
        let currentConvId = null;

        window.addEventListener('sp:profile-updated', function (event) {
            const profile = event.detail || {};
            ADOPTER.name = profile.username || ADOPTER.name;
            if (profile.photo) { ADOPTER.photo = profile.photo; }

            document.querySelectorAll('.ai-paw-loader .ai-match-card:not(.ai-pet-side)').forEach(function (card) {
                const name = card.querySelector('.ai-avatar-name');
                const photo = card.querySelector('img');
                if (name) { name.textContent = ADOPTER.name; }
                if (photo && profile.photo) { photo.src = profile.photo; }
            });
        });

        function loadConversations() {
            try {
                const stored = localStorage.getItem('aiConversations');
                conversations = stored ? JSON.parse(stored) : [];
            } catch (e) {
                conversations = [];
            }
        }

        function saveConversations() {
            try {
                localStorage.setItem('aiConversations', JSON.stringify(conversations));
            } catch (e) {
                // silently fail if localStorage is not available
            }
        }

        function renderHistoryList() {
            historyList.innerHTML = '';
            clearAllBtn.hidden = !conversations.length;
            if (!conversations.length) {
                historyList.innerHTML = '<div class="ai-history-empty"><i class="fa-solid fa-comments"></i><p>Start a new conversation</p></div>';
                return;
            }
            conversations.slice().reverse().forEach(function (conv) {
                const item = document.createElement('div');
                item.className = 'ai-history-item' + (conv.id === currentConvId ? ' active' : '');
                const time = new Date(conv.timestamp);
                const timeStr = time.toLocaleDateString() + ' ' + time.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                const first = conv.messages.find(function (m) { return m.text; });
                const preview = first ? first.text.substring(0, 30) : 'Empty chat';
                item.innerHTML = '<div class="ai-history-text">' + preview + '</div>'
                    + '<div class="ai-history-time">' + timeStr + '</div>'
                    + '<button type="button" class="ai-history-delete" data-conv-id="' + conv.id + '" title="Delete conversation"><i class="fa-solid fa-trash"></i></button>';
                item.addEventListener('click', function (e) {
                    if (!e.target.closest('.ai-history-delete')) {
                        loadConversation(conv.id);
                        setDrawer(false);
                    }
                });
                historyList.appendChild(item);
            });
        }

        function loadConversation(convId) {
            const conv = conversations.find(function (c) { return c.id === convId; });
            if (!conv) { return; }
            currentConvId = convId;
            chatHistory = conv.messages.slice();
            chatMessages.innerHTML = '';
            chatHistory.forEach(function (msg) {
                if (msg.type === 'pets') { renderPets(msg.pets, msg.has_more); }
                else { addChatMessage(msg.role, msg.text); }
            });
            renderHistoryList();
        }

        function newConversation() {
            currentConvId = null;
            chatHistory = [];
            chatMessages.innerHTML = '';
            showWelcome();
            renderHistoryList();
        }

        // Panimulang screen ng bagong usapan - nawawala kapag may unang mensahe na
        function showWelcome() {
            const box = document.createElement('div');
            box.className = 'ai-welcome';
            box.innerHTML = '<div class="ai-welcome-icon"><i class="fa-solid fa-paw"></i></div>'
                + '<h2>Hi, <span class="ai-type-name"></span><span class="ai-type-caret"></span>!</h2>'
                + '<div class="ai-type-line"><span class="ai-type-text"></span></div>'
                + '<p>I compare every available pet with your preferences and lifestyle, then show the best matches first.</p>';
            chatMessages.appendChild(box);

            // Una: tinatype ang pangalan. Tapos: paulit-ulit na nagta-type at
            // nagbubura ng iba't ibang linya sa ilalim nito.
            const nameSpan = box.querySelector('.ai-type-name');
            const textSpan = box.querySelector('.ai-type-text');
            const caret = box.querySelector('.ai-type-caret');
            const name = String(ADOPTER.name || 'there');
            const LINES = [
                'Let\'s find your perfect pet.',
                'Looking for a playful pup?',
                'Or maybe a cuddly cat?',
                'Your new best friend is waiting.',
                'Every pet deserves a loving home.'
            ];
            let i = 0;
            let line = 0;

            function typeName() {
                if (!box.isConnected) { return; }
                nameSpan.textContent = name.slice(0, ++i);
                if (i < name.length) { setTimeout(typeName, 110); return; }
                // Lilipat ang cursor sa linya sa ilalim
                setTimeout(function () {
                    if (!box.isConnected) { return; }
                    textSpan.after(caret);
                    i = 0;
                    typeLine();
                }, 500);
            }

            function typeLine() {
                if (!box.isConnected) { return; }
                const text = LINES[line];
                textSpan.textContent = text.slice(0, ++i);
                if (i < text.length) { setTimeout(typeLine, 60); return; }
                setTimeout(eraseLine, 2200);
            }

            function eraseLine() {
                if (!box.isConnected) { return; }
                textSpan.textContent = LINES[line].slice(0, --i);
                if (i > 0) { setTimeout(eraseLine, 30); return; }
                line = (line + 1) % LINES.length;
                setTimeout(typeLine, 400);
            }

            setTimeout(typeName, 550);
        }

        function saveCurrentConversation() {
            if (!chatHistory.length) { return; }
            if (!currentConvId) {
                currentConvId = 'conv_' + Date.now();
            }
            let conv = conversations.find(function (c) { return c.id === currentConvId; });
            if (!conv) {
                conv = { id: currentConvId, timestamp: new Date().toISOString(), messages: [] };
                conversations.push(conv);
            }
            conv.messages = chatHistory.slice();
            conv.timestamp = new Date().toISOString();
            saveConversations();
            renderHistoryList();
        }

        function addChatMessage(role, text) {
            const welcome = chatMessages.querySelector('.ai-welcome');
            if (welcome) { welcome.remove(); }
            const bubble = document.createElement('div');
            bubble.className = 'ai-chat-msg ' + (role === 'user' ? 'user' : 'bot');
            bubble.textContent = text;
            chatMessages.appendChild(bubble);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            return bubble;
        }

        function showPreferencesRequired() {
            const reply = lang === 'tl'
                ? 'Kailangan mo munang punan at i-save ang iyong preferences sa "My preference" bago ako makapagbigay ng AI recommendation.'
                : 'Please complete and save your preferences in "My preference" before requesting an AI recommendation.';
            addChatMessage('bot', reply);
            chatHistory.push({ role: 'assistant', text: reply });
            saveCurrentConversation();
            chatBusy = false;
        }

        function showChatTyping() {
            const dots = document.createElement('div');
            dots.className = 'ai-chat-msg bot ai-chat-typing';
            dots.innerHTML = '<span></span><span></span><span></span>';
            chatMessages.appendChild(dots);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            return dots;
        }

        const PLACEHOLDER = 'https://via.placeholder.com/300x225?text=No+Photo';

        function petPhotoUrl(pet) {
            return pet.photo ? '../uploads/' + encodeURIComponent(pet.photo) : PLACEHOLDER;
        }

        function esc(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : value;
            return div.innerHTML;
        }

        // Lahat ng naipakitang pet (para sa breakdown ng match %)
        const petsById = {};

        function scoreClass(score) {
            return score >= 85 ? 'high' : (score >= 70 ? 'good' : 'close');
        }

        function renderPets(pets, hasMore) {
            if (!pets || !pets.length) { return null; }
            const grid = document.createElement('div');
            grid.className = 'ai-pets-grid';
            pets.forEach(function (pet, i) {
                petsById[pet.pet_id] = pet;
                const score = parseInt(pet.score, 10);
                const card = document.createElement('div');
                card.className = 'ai-rec-card' + (String(pet.rank) === '1' ? ' ai-rec-top' : '');
                card.style.animationDelay = (i * 0.12) + 's';
                card.innerHTML = '<span class="ai-rank-badge">Top ' + esc(pet.rank) + '</span>'
                    + '<div class="ai-pet-photo"><img src="' + petPhotoUrl(pet)
                    + '" alt="' + esc(pet.name) + '" onerror="this.src=\'' + PLACEHOLDER + '\'"></div>'
                    + '<div><div class="ai-name-row"><h3 class="ai-pet-name">' + esc(pet.name) + '</h3>'
                    + (isNaN(score) ? '' : '<button type="button" class="ai-match-pill ai-match-' + scoreClass(score)
                        + '" data-pet-id="' + esc(pet.pet_id) + '" title="Why ' + score + '%?">'
                        + score + '% <i class="fa-solid fa-circle-info"></i></button>')
                    + '</div>'
                    + '<p class="ai-pet-breed">' + esc(pet.breed) + '</p>'
                    + '<p class="ai-pet-meta">' + esc(pet.age_group) + '<br>' + esc(pet.waiting) + '</p></div>'
                    + '<div class="ai-card-actions">'
                    + '<button type="button" class="btn-text ai-explain-btn" data-pet-id="' + esc(pet.pet_id) + '" data-pet-name="' + esc(pet.name) + '">Why this pet?</button>'
                    + '<a href="pet_details.php?pet_id=' + encodeURIComponent(pet.pet_id) + '" class="btn-orange">View</a>'
                    + '</div>';
                grid.appendChild(card);
            });

            const wrap = document.createElement('div');
            wrap.className = 'ai-chat-pets';
            wrap.appendChild(grid);
            // Isang "Show other pets" lang - sa pinakahuling batch
            chatMessages.querySelectorAll('.ai-more-row').forEach(function (el) { el.remove(); });
            if (hasMore) {
                const moreRow = document.createElement('div');
                moreRow.className = 'ai-more-row';
                moreRow.innerHTML = '<span>Not your type?</span>'
                    + '<button type="button" class="ai-more-btn"><i class="fa-solid fa-shuffle"></i> Show other pets</button>';
                wrap.appendChild(moreRow);
            }
            chatMessages.appendChild(wrap);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            return wrap;
        }

        const AVATAR_FALLBACK = 'https://via.placeholder.com/120?text=%F0%9F%90%BE';
        const MATCH_DURATION = 20000;

        function bubblesMarkup() {
            let html = '<div class="ai-bubbles">';
            for (let i = 0; i < 16; i++) {
                const size = 7 + Math.random() * 13;
                html += '<i class="fa-solid fa-paw" style="left:' + (Math.random() * 94).toFixed(1) + '%;'
                    + 'font-size:' + size.toFixed(1) + 'px;'
                    + 'animation-duration:' + (5 + Math.random() * 5).toFixed(1) + 's;'
                    + 'animation-delay:' + (Math.random() * 6).toFixed(1) + 's;"></i>';
            }
            return html + '</div>';
        }

        function showPawLoader(duration) {
            duration = duration || MATCH_DURATION;
            const box = document.createElement('div');
            box.className = 'ai-chat-pets';
            box.innerHTML = '<div class="ai-paw-loader">'
                + bubblesMarkup()
                + '<div class="ai-match-row">'
                + '<div class="ai-match-card">'
                + '<div class="ai-avatar"><img src="' + (ADOPTER.photo || AVATAR_FALLBACK)
                + '" alt="" onerror="this.src=\'' + AVATAR_FALLBACK + '\'"></div>'
                + '<span class="ai-avatar-name">' + esc(ADOPTER.name) + '</span>'
                + '</div>'
                + '<span class="ai-match-link"></span>'
                + '<div class="ai-paw-wrap"><span class="ai-paw-shadow"></span><div class="ai-paw-3d"><div class="ai-paw"><span class="ai-paw-liquid"></span><span class="ai-paw-gloss"></span></div></div></div>'
                + '<span class="ai-match-link"></span>'
                + '<div class="ai-match-card ai-pet-side" id="aiScanCard">'
                + '<span class="ai-heart"><i class="fa-solid fa-heart"></i></span>'
                + '<div class="ai-avatar ai-avatar-pet"><img id="aiScanPhoto" src="' + AVATAR_FALLBACK
                + '" alt="" onerror="this.src=\'' + AVATAR_FALLBACK + '\'"><span class="ai-wave"></span></div>'
                + '<span class="ai-avatar-name" id="aiScanName">Searching</span>'
                + '</div>'
                + '</div>'
                + '<div class="ai-paw-status">Matching... 0%</div>'
                + '<div class="ai-paw-bar"><div class="ai-paw-fill"></div></div>'
                + '<div class="ai-paw-scan">Looking for your matches...</div>'
                + '</div>';

            chatMessages.appendChild(box);
            chatMessages.scrollTop = chatMessages.scrollHeight;

            const statusEl = box.querySelector('.ai-paw-status');
            const scanEl = box.querySelector('.ai-paw-scan');
            const fillEl = box.querySelector('.ai-paw-fill');
            const liquidEl = box.querySelector('.ai-paw-liquid');
            const petSide = box.querySelector('#aiScanCard');
            const photoEl = box.querySelector('#aiScanPhoto');
            const nameEl2 = box.querySelector('#aiScanName');

            const PHASES = [
                'Reading your preferences...',
                'Scanning available pets...',
                'Comparing temperament and energy...',
                'Checking size and living space...',
                'Weighing care needs and time at the shelter...',
                'Ranking your best matches...'
            ];

            let pool = [];
            const startedAt = Date.now();

            const progressTimer = setInterval(function () {
                const t = Math.min((Date.now() - startedAt) / (duration - 1500), 1);
                const pct = Math.round((1 - Math.pow(1 - t, 2.2)) * 96);
                fillEl.style.width = pct + '%';
                liquidEl.style.height = pct + '%';
                statusEl.textContent = 'Matching... ' + pct + '%';
                const phase = Math.min(Math.floor(t * PHASES.length), PHASES.length - 1);
                if (scanEl.dataset.phase !== String(phase)) {
                    scanEl.dataset.phase = String(phase);
                    scanEl.textContent = PHASES[phase];
                }
            }, 120);

            const cycleTimer = setInterval(function () {
                if (!pool.length) { return; }
                const pet = pool[Math.floor(Math.random() * pool.length)];
                photoEl.src = petPhotoUrl(pet);
                nameEl2.textContent = pet.name;
                petSide.classList.remove('swap');
                void petSide.offsetWidth;
                petSide.classList.add('swap');
            }, 520);

            return {
                setPool: function (pets) { pool = pets; },
                finish: function (done) {
                    clearInterval(progressTimer);
                    clearInterval(cycleTimer);
                    if (pool.length) {
                        photoEl.src = petPhotoUrl(pool[0]);
                        nameEl2.textContent = pool[0].name;
                    }
                    petSide.classList.add('matched');
                    fillEl.style.width = '100%';
                    liquidEl.style.height = '100%';
                    statusEl.textContent = 'Done! 100%';
                    statusEl.classList.add('done');
                    scanEl.textContent = pool.length ? 'Found your matches!' : 'No matches found.';
                    setTimeout(function () {
                        box.remove();
                        done();
                    }, 900);
                }
            };
        }

        // direct = true: galing sa "Recommend now" button - diretsong magre-recommend.
        // Ang ibang mensahe ay ipinapasa sa AI; siya ang nagpapasya (show_matches)
        // kung talagang humihingi ng pet matches - hindi na sa salitang "recommend" lang.
        // more = true: "Show other pets" - ang susunod na pinakabagay, hindi ang mga naipakita na
        function sendChat(text, direct, more) {
            text = text.trim();
            if (!text || chatBusy) { return; }
            chatBusy = true;
            chatInput.value = '';
            addChatMessage('user', text);
            chatHistory.push({ role: 'user', text: text });

            if (direct) {
                showMatches(more);
                return;
            }
            askAssistant(text);
        }

        // Mga pet_id na naipakita mula sa huling bagong "Recommend now"
        function shownPetIds() {
            const ids = [];
            for (let i = chatHistory.length - 1; i >= 0; i--) {
                const msg = chatHistory[i];
                if (msg.type !== 'pets') { continue; }
                (msg.pets || []).forEach(function (p) { ids.push(p.pet_id); });
                if (!msg.more) { break; }
            }
            return ids;
        }

        // Paw animation, tapos ang mga pet card na pinakabagay
        function showMatches(more) {
            if (!HAS_PREFERENCES) {
                showPreferencesRequired();
                return;
            }
            {
                const exclude = more ? shownPetIds() : [];
                more = exclude.length > 0;
                const duration = more ? 6000 : MATCH_DURATION;
                const paw = showPawLoader(duration);
                const started = Date.now();

                fetch('ai_recommendation.php?refresh=1' + (more ? '&exclude=' + exclude.join(',') : ''), { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (rec) {
                        const pets = rec.pets || [];
                        paw.setPool(pets);
                        const wait = Math.max(0, duration - 1200 - (Date.now() - started));
                        setTimeout(function () {
                            paw.finish(function () {
                                let reply;
                                if (pets.length) {
                                    if (pets[0].close) {
                                        reply = rec.reply + ' Tap the % on any card to see how it was scored.';
                                    } else if (more) {
                                        reply = 'Here are ' + pets.length + ' more pets that still fit you well, ranked best first.';
                                    } else {
                                        reply = 'Here are your top ' + pets.length + ' matches, ranked best first. Tap the % on any card to see how it was scored, or "Why this pet?" for the assessment.';
                                    }
                                    addChatMessage('bot', reply);
                                    renderPets(pets, rec.has_more);
                                    chatHistory.push({ role: 'assistant', text: reply });
                                    chatHistory.push({ role: 'assistant', type: 'pets', pets: pets, more: more, has_more: !!rec.has_more });
                                } else {
                                    if (more) {
                                        // Ubos na - wala nang "Show other pets"
                                        chatMessages.querySelectorAll('.ai-more-row').forEach(function (el) { el.remove(); });
                                        chatHistory.forEach(function (m) { if (m.type === 'pets') { m.has_more = false; } });
                                    }
                                    reply = rec.reply || 'No matching pets found right now.';
                                    addChatMessage('bot', reply);
                                    chatHistory.push({ role: 'assistant', text: reply });
                                }
                                saveCurrentConversation();
                                chatBusy = false;
                            });
                        }, wait);
                    })
                    .catch(function () {
                        paw.finish(function () {
                            const msg = 'Could not fetch recommendations. Please try again.';
                            addChatMessage('bot', msg);
                            chatHistory.push({ role: 'assistant', text: msg });
                            saveCurrentConversation();
                            chatBusy = false;
                        });
                    });
            }
        }

        function askAssistant(text) {
            const typing = showChatTyping();
            // Naka-base64 sa form field na "p" - kapareho ng chat bubble - para hindi
            // harangin ng firewall ng free hosting ang raw JSON ("Error 403")
            const chatPayload = JSON.stringify({
                message: text,
                context: 'recommend_page',
                history: chatHistory.filter(function (m) { return m.text; }).slice(-10)
            });
            fetch('../connection/assistant_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'p=' + encodeURIComponent(btoa(unescape(encodeURIComponent(chatPayload))))
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    typing.remove();
                    if (data.show_matches && !HAS_PREFERENCES) {
                        showPreferencesRequired();
                        return;
                    }
                    const reply = data.reply || 'Sorry, something went wrong. Please try again.';
                    addChatMessage('bot', reply);
                    chatHistory.push({ role: 'assistant', text: reply });
                    saveCurrentConversation();
                    // Humingi talaga ng pet matches (ayon sa AI): ipakita ang mga card
                    if (data.show_matches) {
                        showMatches(!!data.show_more);
                        return;
                    }
                    chatBusy = false;
                })
                .catch(function () {
                    typing.remove();
                    addChatMessage('bot', 'Could not reach the assistant right now. Please try again.');
                    chatHistory.push({ role: 'assistant', text: 'Could not reach the assistant right now. Please try again.' });
                    saveCurrentConversation();
                    chatBusy = false;
                });
        }

        // Sumusunod sa wikang pinili sa Account Settings (may mga bagong linya kaya hiwalay dito)
        const HELP_TEXT = lang === 'tl'
            ? 'Paano gumagana ang recommendation system:\n\n'
              + '1. I-set muna ang iyong preferences sa "My preference" — uri ng pet, laki, edad, energy level at tirahan.\n'
              + '2. I-type ang "recommend" dito (o pindutin ang "Recommend now") at itutugma kita sa mga available na pet.\n'
              + '3. Ang mga pet lang na may 70% o higit pang tugma sa iyong preferences ang ipinapakita, mula sa pinakabagay.\n'
              + '4. Pindutin ang "Why this pet?" sa kahit anong card para mabasa ang paliwanag kung bakit bagay ito sa iyo. Pindutin ang % para makita kung paano ito kinuwenta.\n'
              + '5. Hindi type ang mga lumabas? Pindutin ang "Show other pets" o sabihing "iba naman" para sa susunod na pinakabagay.\n'
              + '6. Magtanong ulit anumang oras para ma-refresh ang listahan kapag may bagong pet.'
            : 'How the recommendation system works:\n\n'
              + '1. Set your preferences first in "My preference" — pet type, size, age, energy level and living space.\n'
              + '2. Type "recommend" here (or tap "Recommend now") and I will match you with available pets.\n'
              + '3. Only pets that score 70% or higher against your preferences are shown, ranked best first.\n'
              + '4. Tap "Why this pet?" on any card to read the assessment explaining the match. It follows the language you chose in Account Settings. Tap the % to see how it was scored.\n'
              + '5. Not your type? Tap "Show other pets" or say "show me other pets" for the next best matches.\n'
              + '6. Ask again anytime to refresh the list as new pets arrive.';

        document.querySelector('.ai-chat-chips').addEventListener('click', function (e) {
            const chip = e.target.closest('.ai-chip');
            if (!chip) { return; }
            if (chip.dataset.action === 'help') {
                addChatMessage('bot', HELP_TEXT);
                chatHistory.push({ role: 'assistant', text: HELP_TEXT });
                saveCurrentConversation();
            } else {
                sendChat('Recommend pets for me', true);
            }
        });

        chatSend.addEventListener('click', function () { sendChat(chatInput.value); });
        chatInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { sendChat(chatInput.value); }
        });

        historyToggle.addEventListener('click', function () {
            setDrawer(!historyDrawer.classList.contains('open'));
        });

        historyBackdrop.addEventListener('click', function () { setDrawer(false); });

        newChatBtn.addEventListener('click', function () {
            newConversation();
            setDrawer(false);
        });

        // Burahin lahat ng naka-save na usapan sa browser na ito at magsimula ulit
        clearAllBtn.addEventListener('click', function () {
            if (!confirm('Clear all conversations? This cannot be undone.')) { return; }
            conversations = [];
            try { localStorage.removeItem('aiConversations'); } catch (e) {}
            newConversation();
        });

        historyList.addEventListener('click', function (e) {
            const delBtn = e.target.closest('.ai-history-delete');
            if (delBtn) {
                const convId = delBtn.dataset.convId;
                conversations = conversations.filter(function (c) { return c.id !== convId; });
                if (currentConvId === convId) {
                    newConversation();
                } else {
                    renderHistoryList();
                }
                saveConversations();
            }
        });

        showWelcome();

        function paintLangButton() {
            langBtn.textContent = lang === 'tl' ? 'English' : 'Tagalog';
        }

        function cacheKey(petId, l) { return petId + '|' + l; }

        function loadExplanation(petId) {
            const key = cacheKey(petId, lang);
            if (cache[key]) {
                bodyEl.textContent = cache[key];
                return;
            }
            bodyEl.innerHTML = '<div class="ai-explain-typing"><span></span><span></span><span></span></div>';
            langBtn.disabled = true;
            const langAtStart = lang;

            fetch(EXPLAIN_URL + petId + '&lang=' + lang, { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    langBtn.disabled = false;
                    if (currentPetId !== petId || lang !== langAtStart) { return; }
                    if (data.ok) {
                        cache[key] = data.explanation;
                        bodyEl.textContent = data.explanation;
                    } else {
                        bodyEl.textContent = data.error || 'The explanation could not be loaded right now.';
                    }
                })
                .catch(function () {
                    langBtn.disabled = false;
                    if (currentPetId !== petId || lang !== langAtStart) { return; }
                    bodyEl.textContent = 'The explanation could not be loaded right now. Please try again.';
                });
        }

        // Rescue Story Video ng bawat pet: pet_id => URL
        const STORY_VIDEOS = <?php
            require_once __DIR__ . '/../connection/pet_video.php';
            $storyVideos = [];
            foreach ($pdo->query("SELECT pet_id, rescue_video FROM pet_inventory WHERE rescue_video IS NOT NULL AND rescue_video <> ''") as $sv) {
                $u = sp_pet_video_url($sv['rescue_video']);
                if ($u !== '') { $storyVideos[(int) $sv['pet_id']] = $u; }
            }
            echo json_encode((object) $storyVideos, JSON_HEX_TAG | JSON_HEX_AMP);
        ?>;
        const storyBox = document.getElementById('aiStory');
        const storyVideo = document.getElementById('aiStoryVideo');

        function showStory(petId) {
            const url = STORY_VIDEOS[petId] || '';
            storyVideo.pause();
            if (url) {
                storyVideo.src = url;
                storyBox.hidden = false;
                // Kusang tumutugtog (walang tunog muna, gaya ng ibang app); puwedeng i-unmute
                storyVideo.muted = true;
                storyVideo.play().catch(function () {});
            } else {
                storyVideo.removeAttribute('src');
                storyVideo.load();
                storyBox.hidden = true;
            }
        }

        function openExplain(petId, petName) {
            currentPetId = petId;
            nameEl.textContent = petName;
            showStory(petId);
            paintLangButton();
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
            loadExplanation(petId);
        }

        function closeExplain() {
            storyVideo.pause();
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        // ---- Bakit ganito ang match % ----
        const scoreOverlay = document.getElementById('aiScoreOverlay');
        const scoreTitle = document.getElementById('aiScoreTitle');
        const scoreBody = document.getElementById('aiScoreBody');

        function fmtPts(n) {
            return (Math.round(n * 10) / 10).toString();
        }

        function openScore(petId) {
            const pet = petsById[petId];
            if (!pet) { return; }
            const bd = pet.breakdown;
            scoreTitle.textContent = pet.name + ' · ' + pet.score + '% match';

            if (!bd || !bd.rows) {
                scoreBody.innerHTML = '<p class="ai-score-note">The breakdown is not available for this older result. Tap "Recommend now" to get fresh matches with the full breakdown.</p>';
            } else {
                const groups = { 'Must-Have': [], 'Pet Preferences': [], 'Lifestyle Fit': [] };
                bd.rows.forEach(function (r) { (groups[r.group] = groups[r.group] || []).push(r); });

                let html = '<p class="ai-score-intro">How ' + esc(pet.name) + ' scored against your preferences. '
                    + 'Pet Preferences are worth 60 points and Lifestyle Fit 40 points.</p>';

                // Must-have: pasado o may bawas
                html += '<div class="ai-score-group"><div class="ai-score-group-head"><span>Must-Have</span><span>Safety checks</span></div>';
                groups['Must-Have'].forEach(function (r) {
                    html += '<div class="ai-score-row">'
                        + '<div class="ai-score-row-top"><span class="ai-score-label">'
                        + '<i class="fa-solid ' + (r.ok ? 'fa-circle-check ok' : 'fa-triangle-exclamation warn') + '"></i> ' + esc(r.label) + '</span>'
                        + '<span class="ai-score-pts">' + (r.ok ? 'Passed' : '×' + fmtPts(r.factor)) + '</span></div>'
                        + '<div class="ai-score-cmp">You: ' + esc(r.pref) + ' · ' + esc(pet.name) + ': ' + esc(r.pet) + '</div>'
                        + '</div>';
                });
                html += '</div>';

                ['Pet Preferences', 'Lifestyle Fit'].forEach(function (g) {
                    let got = 0, max = 0;
                    groups[g].forEach(function (r) { got += r.points; max += r.weight; });
                    html += '<div class="ai-score-group"><div class="ai-score-group-head"><span>' + g + '</span>'
                        + '<span>' + fmtPts(got) + ' / ' + fmtPts(max) + ' pts</span></div>';
                    groups[g].forEach(function (r) {
                        const pct = r.weight ? Math.round(r.points / r.weight * 100) : 0;
                        const cls = pct >= 100 ? 'full' : (pct > 0 ? 'part' : 'none');
                        html += '<div class="ai-score-row">'
                            + '<div class="ai-score-row-top"><span class="ai-score-label">' + esc(r.label) + '</span>'
                            + '<span class="ai-score-pts">' + fmtPts(r.points) + ' / ' + fmtPts(r.weight) + '</span></div>'
                            + '<div class="ai-score-bar"><span class="' + cls + '" style="width:' + pct + '%"></span></div>'
                            + '<div class="ai-score-cmp">You: ' + esc(r.pref) + ' · ' + esc(pet.name) + ': ' + esc(r.pet) + '</div>'
                            + '</div>';
                    });
                    html += '</div>';
                });

                html += '<div class="ai-score-total"><span>Total</span><span>' + bd.rule_score + '%</span></div>';
                if (bd.final_score !== bd.rule_score) {
                    html += '<p class="ai-score-note">The final ' + bd.final_score + '% is adjusted by the system\'s learning model, '
                        + 'which compares this pairing with past successful adoptions.</p>';
                }
                if (pet.close) {
                    html += '<p class="ai-score-note">This is a close match: below the 70% we normally recommend, shown because you asked for other pets.</p>';
                }
                scoreBody.innerHTML = html;
            }
            scoreOverlay.classList.add('open');
            scoreOverlay.setAttribute('aria-hidden', 'false');
        }

        function closeScore() {
            scoreOverlay.classList.remove('open');
            scoreOverlay.setAttribute('aria-hidden', 'true');
        }

        document.getElementById('aiScoreClose').addEventListener('click', closeScore);
        scoreOverlay.addEventListener('click', function (e) {
            if (e.target === scoreOverlay) { closeScore(); }
        });

        chatMessages.addEventListener('click', function (e) {
            const btn = e.target.closest('.ai-explain-btn');
            if (btn) { openExplain(btn.dataset.petId, btn.dataset.petName); return; }
            const pill = e.target.closest('.ai-match-pill');
            if (pill) { openScore(pill.dataset.petId); return; }
            if (e.target.closest('.ai-more-btn')) { sendChat('Show me other pets', true, true); }
        });

        closeBtn.addEventListener('click', closeExplain);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { closeExplain(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            if (scoreOverlay.classList.contains('open')) { closeScore(); }
            else if (overlay.classList.contains('open')) { closeExplain(); }
            else { setDrawer(false); }
        });
        langBtn.addEventListener('click', function () {
            lang = lang === 'tl' ? 'en' : 'tl';
            paintLangButton();
            if (currentPetId) { loadExplanation(currentPetId); }
        });

        loadConversations();
        renderHistoryList();

    })();

    // Telepono: × = lumabas sa chat at buksan ang sidebar (gaya ng ☰ ng ibang page)
    document.getElementById('aiExitBtn').addEventListener('click', function () {
        document.body.classList.add('sp-nav-open');
    });
    </script>
    <?php endif; ?>
    <?php // Walang chat bubble dito (ito mismo ang AI chat), kaya direktang isinasama ang responsive layer
    include '../connection/responsive.php';
    // at ang English/Tagalog ng site
    include '../connection/i18n.php';
    // Gamitin ang iisang Account Settings modal na ginagamit sa ibang adopter pages.
    if ($user) {
        $psUser = $user;
        $psMode = 'edit';
        include '_profile_setup_modal.php';
    }
    // Bell at menu ng profile (Account Settings, Logout) - wala nang Logout sa sidebar
    require_once '../connection/notif_bell.php'; ?>
</body>
</html>
