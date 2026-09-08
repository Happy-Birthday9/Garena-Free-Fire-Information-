<?php

// ============================================================================
// 🚀 Runtime Guard & Constants
// ============================================================================

if (!defined('BOT_TOKEN') || !defined('BOT_USERNAME')) {
    http_response_code(503);
    exit('⚠️ Runtime credentials unavailable');
}

define('ADMIN_ID', 0); // Optional: set your Telegram numeric chat ID
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN);
define('DATA_DIR', __DIR__ . '/data');
define('HISTORY_FILE', DATA_DIR . '/history.json');
define('FAVORITES_FILE', DATA_DIR . '/favorites.json');

// API Keys (Rotating) - DO NOT hard-code secrets in a public repository.
// Set FF_API_KEYS in your hosting environment as a comma-separated list.
$envKeys = getenv('FF_API_KEYS') ?: '';
$apiKeys = array_values(array_filter(array_map('trim', explode(',', $envKeys))));
if (empty($apiKeys)) {
    $apiKeys = [];
}
$currentKeyIndex = 0;

// Create data directory
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}


// ============================================================================
// 📊 Data Functions
// ============================================================================

function readJsonFile($file, $default = []) {
    if (!file_exists($file)) return $default;
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

function writeJsonFile($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($file, $json, LOCK_EX);
}

function getUserHistory($userId) {
    $data = readJsonFile(HISTORY_FILE, ['users' => []]);
    if (!isset($data['users'][$userId])) {
        $data['users'][$userId] = [
            'searches' => [],
            'total' => 0,
            'joined' => date('Y-m-d H:i:s')
        ];
    }
    return $data['users'][$userId];
}

function saveUserHistory($userId, $history) {
    $data = readJsonFile(HISTORY_FILE, ['users' => []]);
    $data['users'][$userId] = $history;
    return writeJsonFile(HISTORY_FILE, $data);
}

function addToHistory($userId, $uid, $playerData) {
    $history = getUserHistory($userId);
    $history['searches'][] = [
        'uid' => $uid,
        'name' => $playerData['AccountInfo']['AccountName'] ?? 'Unknown',
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $playerData
    ];
    $history['total'] = count($history['searches']);
    if (count($history['searches']) > 50) {
        $history['searches'] = array_slice($history['searches'], -50);
    }
    return saveUserHistory($userId, $history);
}

function getFavorites($userId) {
    $data = readJsonFile(FAVORITES_FILE, ['users' => []]);
    if (!isset($data['users'][$userId])) {
        $data['users'][$userId] = ['favorites' => []];
    }
    return $data['users'][$userId]['favorites'];
}

function saveFavorites($userId, $favorites) {
    $data = readJsonFile(FAVORITES_FILE, ['users' => []]);
    $data['users'][$userId]['favorites'] = $favorites;
    return writeJsonFile(FAVORITES_FILE, $data);
}

function addFavorite($userId, $uid, $playerData) {
    $favorites = getFavorites($userId);
    foreach ($favorites as $fav) {
        if ($fav['uid'] == $uid) return false;
    }
    $favorites[] = [
        'uid' => $uid,
        'name' => $playerData['AccountInfo']['AccountName'] ?? 'Unknown',
        'added' => date('Y-m-d H:i:s'),
        'data' => $playerData
    ];
    return saveFavorites($userId, $favorites);
}

function removeFavorite($userId, $uid) {
    $favorites = getFavorites($userId);
    $newFavorites = [];
    foreach ($favorites as $fav) {
        if ($fav['uid'] != $uid) $newFavorites[] = $fav;
    }
    return saveFavorites($userId, $newFavorites);
}

function isFavorite($userId, $uid) {
    $favorites = getFavorites($userId);
    foreach ($favorites as $fav) {
        if ($fav['uid'] == $uid) return true;
    }
    return false;
}

function getApiKey() {
    global $apiKeys, $currentKeyIndex;
    if (empty($apiKeys)) return '';
    $key = $apiKeys[$currentKeyIndex % count($apiKeys)];
    $currentKeyIndex++;
    return $key;
}

// ============================================================================
// 📨 Telegram API Functions
// ============================================================================

function sendMessage($chatId, $text, $parseMode = 'HTML', $extra = []) {
    if ($parseMode === 'HTML') {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/\\*\\*(.*?)\\*\\*/s', '<b>$1</b>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    }
    $payload = array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
    ], $extra);
    return apiRequest('sendMessage', $payload);
}

function deleteMessage($chatId, $messageId) {
    return apiRequest('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ]);
}

function answerCallbackQuery($callbackId, $text = '', $alert = false) {
    return apiRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert
    ]);
}

function editMessageText($chatId, $messageId, $text, $parseMode = 'HTML', $extra = []) {
    $payload = array_merge([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => $parseMode,
    ], $extra);
    return apiRequest('editMessageText', $payload);
}

function apiRequest($method, $data) {
    $result = apiRequestRaw($method, $data);
    return isset($result['ok']) && $result['ok'] === true;
}

function apiRequestRaw($method, $data) {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }
    return ['ok' => false];
}

// ============================================================================
// 🔥 Free Fire API Functions
// ============================================================================

function getPlayerInfo($uid) {
    $url = 'https://api.gameskinbo.com/ff-info/get?uid=' . urlencode($uid);
    $apiKey = getApiKey();
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_filter([
            $apiKey !== '' ? 'x-api-key: ' . $apiKey : null,
            'Accept: application/json'
        ])
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (!isset($data['error'])) {
            return ['success' => true, 'data' => $data];
        }
        return ['success' => false, 'error' => $data['error'] ?? 'Player not found'];
    }
    
    return ['success' => false, 'error' => 'API request failed (HTTP: ' . $httpCode . ')'];
}

function formatPlayerInfo($playerData) {
    if (empty($playerData)) {
        return "❌ No player data found.";
    }
    
    $message = "🔥 **Player Information** 🔥\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    
    // Account Info
    if (isset($playerData['AccountInfo'])) {
        $acc = $playerData['AccountInfo'];
        $message .= "👤 **Nickname:** " . ($acc['AccountName'] ?? 'Unknown') . "\n";
        $message .= "🆔 **UID:** " . ($acc['AccountID'] ?? 'N/A') . "\n";
        $message .= "🏆 **Level:** " . ($acc['AccountLevel'] ?? 'N/A') . "\n";
        $message .= "📊 **XP:** " . (isset($acc['AccountEXP']) ? number_format($acc['AccountEXP']) : 'N/A') . "\n";
        $message .= "❤️ **Likes:** " . ($acc['AccountLikes'] ?? '0') . "\n";
        $message .= "🌍 **Region:** " . ($acc['AccountRegion'] ?? 'N/A') . "\n";
        $message .= "📅 **Created:** " . formatDate($acc['AccountCreateTime'] ?? null) . "\n";
        $message .= "🕐 **Last Login:** " . formatDate($acc['AccountLastLogin'] ?? null) . "\n";
        $message .= "📱 **Version:** " . ($acc['ReleaseVersion'] ?? 'N/A') . "\n\n";
    }
    
    // Profile Info (Ranks)
    if (isset($playerData['AccountProfileInfo'])) {
        $profile = $playerData['AccountProfileInfo'];
        $message .= "🏅 **Ranking Stats**\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🎯 **BR Max Rank:** " . ($profile['BrMaxRank'] ?? 'N/A') . "\n";
        $message .= "📊 **BR Points:** " . ($profile['BrRankPoint'] ?? '0') . "\n";
        $message .= "🔫 **CS Max Rank:** " . ($profile['CsMaxRank'] ?? 'N/A') . "\n";
        $message .= "📊 **CS Points:** " . ($profile['CsRankPoint'] ?? '0') . "\n\n";
    }
    
    // Credit Score
    if (isset($playerData['CreditScoreInfo'])) {
        $message .= "⭐ **Credit Score:** " . ($playerData['CreditScoreInfo']['creditScore'] ?? 'N/A') . "\n\n";
    }
    
    // Social Info
    if (isset($playerData['SocialInfo'])) {
        $social = $playerData['SocialInfo'];
        $message .= "👤 **Social Info**\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🌐 **Language:** " . ($social['language'] ?? 'N/A') . "\n";
        $message .= "📝 **Signature:** " . ($social['signature'] ?? 'N/A') . "\n";
        $message .= "⏰ **Active Time:** " . ($social['timeActive'] ?? 'N/A') . "\n";
        $message .= "🎮 **Preferred Mode:** " . ($social['modePrefer'] ?? 'N/A') . "\n\n";
    }
    
    // Guild Info
    if (isset($playerData['GuildInfo'])) {
        $guild = $playerData['GuildInfo'];
        $message .= "🏰 **Guild Information**\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "📛 **Name:** " . ($guild['GuildName'] ?? 'N/A') . "\n";
        $message .= "🆔 **ID:** " . ($guild['GuildID'] ?? 'N/A') . "\n";
        $message .= "📊 **Level:** " . ($guild['GuildLevel'] ?? 'N/A') . "\n";
        $message .= "👥 **Members:** " . ($guild['GuildMember'] ?? '0') . "/" . ($guild['GuildCapacity'] ?? '0') . "\n";
        
        if (isset($playerData['GuildOwnerInfo'])) {
            $owner = $playerData['GuildOwnerInfo'];
            $message .= "👑 **Leader:** " . ($owner['nickname'] ?? 'N/A') . "\n";
        }
        $message .= "\n";
    }
    
    // Pet Info
    if (isset($playerData['PetInfo'])) {
        $pet = $playerData['PetInfo'];
        $message .= "🐾 **Pet Information**\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🆔 **Pet ID:** " . ($pet['id'] ?? 'N/A') . "\n";
        $message .= "📊 **Level:** " . ($pet['level'] ?? 'N/A') . "\n\n";
    }
    
    // Equipped Items
    if (isset($playerData['EquippedItemsInfo'])) {
        $eq = $playerData['EquippedItemsInfo'];
        $message .= "🎒 **Equipped Items**\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🏅 **BP Badges:** " . ($eq['EquippedBPBadges'] ?? 'N/A') . "\n";
        $message .= "🖼️ **Avatar ID:** " . ($eq['EquippedAvatarId'] ?? 'N/A') . "\n";
        $message .= "📸 **Banner ID:** " . ($eq['EquippedBannerId'] ?? 'N/A') . "\n\n";
    }
    
    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "🔄 Last Updated: " . date('Y-m-d H:i:s');
    
    return $message;
}

function formatDate($timestamp) {
    if (!$timestamp) return 'N/A';
    return date('Y-m-d', intval($timestamp));
}

// ============================================================================
// ⌨️ Inline Keyboards
// ============================================================================

function getMainKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '🔍 Search UID', 'callback_data' => 'search']],
            [['text' => '⭐ Favorites', 'callback_data' => 'favorites'], ['text' => '📋 History', 'callback_data' => 'history']],
            [['text' => '📊 Stats', 'callback_data' => 'stats'], ['text' => '🆘 Help', 'callback_data' => 'help']],
            [['text' => 'ℹ️ About', 'callback_data' => 'about']]
        ]
    ];
}

function getPlayerKeyboardWithContext($uid, $userId) {
    $isFav = isFavorite($userId, $uid);
    $favText = $isFav ? '⭐ Remove Favorite' : '⭐ Add Favorite';
    $favAction = $isFav ? 'remove_fav_' : 'add_fav_';
    
    return [
        'inline_keyboard' => [
            [['text' => '🔄 Refresh', 'callback_data' => 'refresh_' . $uid]],
            [['text' => $favText, 'callback_data' => $favAction . $uid]],
            [['text' => '⬅️ Back to Main', 'callback_data' => 'back_main']]
        ]
    ];
}

function getFavoritesKeyboard($favorites) {
    $keyboard = ['inline_keyboard' => []];
    
    if (empty($favorites)) {
        $keyboard['inline_keyboard'][] = [['text' => '📭 No favorites yet', 'callback_data' => 'noop']];
    } else {
        foreach ($favorites as $fav) {
            $name = strlen($fav['name']) > 20 ? substr($fav['name'], 0, 20) . '...' : $fav['name'];
            $keyboard['inline_keyboard'][] = [
                ['text' => '👤 ' . $name, 'callback_data' => 'view_fav_' . $fav['uid']]
            ];
        }
    }
    
    $keyboard['inline_keyboard'][] = [['text' => '🔙 Back to Main', 'callback_data' => 'back_main']];
    return $keyboard;
}

function getHistoryKeyboard($history, $page = 1) {
    $perPage = 5;
    $total = count($history['searches']);
    $totalPages = ceil($total / $perPage);
    $start = ($page - 1) * $perPage;
    $items = array_slice($history['searches'], $start, $perPage);
    
    $keyboard = ['inline_keyboard' => []];
    
    if (empty($items)) {
        $keyboard['inline_keyboard'][] = [['text' => '📭 No history yet', 'callback_data' => 'noop']];
    } else {
        foreach ($items as $item) {
            $name = strlen($item['name']) > 20 ? substr($item['name'], 0, 20) . '...' : $item['name'];
            $keyboard['inline_keyboard'][] = [
                ['text' => '👤 ' . $name, 'callback_data' => 'view_history_' . $item['uid']]
            ];
        }
    }
    
    if ($totalPages > 1) {
        $pagination = [];
        if ($page > 1) {
            $pagination[] = ['text' => '◀️', 'callback_data' => 'history_page_' . ($page - 1)];
        }
        $pagination[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'noop'];
        if ($page < $totalPages) {
            $pagination[] = ['text' => '▶️', 'callback_data' => 'history_page_' . ($page + 1)];
        }
        $keyboard['inline_keyboard'][] = $pagination;
    }
    
    $keyboard['inline_keyboard'][] = [
        ['text' => '🗑️ Clear History', 'callback_data' => 'clear_history'],
        ['text' => '🔙 Back', 'callback_data' => 'back_main']
    ];
    
    return $keyboard;
}

function getSearchKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '🔍 Enter UID', 'callback_data' => 'search_uid']],
            [['text' => '🔙 Back to Main', 'callback_data' => 'back_main']]
        ]
    ];
}

function getHelpKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '🔍 Search', 'callback_data' => 'search'], ['text' => '⭐ Favorites', 'callback_data' => 'favorites']],
            [['text' => '📋 History', 'callback_data' => 'history'], ['text' => '📊 Stats', 'callback_data' => 'stats']],
            [['text' => '🔙 Back to Main', 'callback_data' => 'back_main']]
        ]
    ];
}

// ============================================================================
// 💬 Command & Message Handlers
// ============================================================================

function handleStart($chatId, $userId, $firstName, $username = '') {
    $message = "🔥 **Free Fire UID Info Bot** 🔥\n\n" .
               "👋 হ্যালো {$firstName}!\n\n" .
               "📊 I provide complete Free Fire player information!\n\n" .
               "⚡ **Features:**\n" .
               "• 🔍 Search by UID\n" .
               "• ⭐ Add to Favorites\n" .
               "• 📋 Search History\n" .
               "• 📊 Player Statistics\n" .
               "• 🏆 BR & Ranked Stats\n" .
               "• 🔫 Clash Squad Stats\n" .
               "• 🏰 Guild Information\n" .
               "• 🐾 Pet Information\n\n" .
               "📌 **How to Use:**\n" .
               "1️⃣ Enter any Free Fire UID\n" .
               "2️⃣ Or use Search button\n" .
               "3️⃣ Get complete player info!\n\n" .
               "🔽 **Select an option below:**";
    
    $keyboard = getMainKeyboard();
    sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
}

function handleHelp($chatId) {
    $message = "🆘 **Help & Commands**\n\n" .
               "📌 **Commands:**\n" .
               "/start - Start the bot\n" .
               "/search [UID] - Search player\n" .
               "/favorites - View favorites\n" .
               "/history - View history\n" .
               "/stats - Your bot stats\n" .
               "/help - Show this help\n" .
               "/about - About this bot\n\n" .
               "🔍 **How to Search:**\n" .
               "• Send a valid Free Fire UID\n" .
               "• Use /search UID\n" .
               "• Click Search button\n\n" .
               "⭐ **Favorites:**\n" .
               "• Save players to favorites\n" .
               "• Quick access later\n\n" .
               "📊 **Player Info Includes:**\n" .
               "• Basic Info (Name, Level, XP)\n" .
               "• BR & Ranked Stats\n" .
               "• Clash Squad Stats\n" .
               "• Guild Information\n" .
               "• Pet Information\n" .
               "• Equipped Items\n\n" .
               "👑 **Bot Owner:** YOUR CHANNEL USERNAME";
    
    $keyboard = getHelpKeyboard();
    sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
}

function handleAbout($chatId) {
    $message = "ℹ️ **About This Bot**\n\n" .
               "🔥 **Free Fire UID Info Bot**\n" .
               "👑 **Owner:** YOUR CHANNEL USERNAME\n" .
               "📡 **API:** YOUR CHANNEL USERNAME\n" .
               "🔧 **Version:** 2.0\n\n" .
               "⚡ **Features:**\n" .
               "• ✅ UID Search\n" .
               "• ✅ Full Player Stats\n" .
               "• ✅ Favorites System\n" .
               "• ✅ Search History\n" .
               "• ✅ Beautiful UI\n" .
               "• ✅ Inline Keyboard\n\n" .
               "📊 **Stats Available:**\n" .
               "• BR, Ranked, Clash Squad\n" .
               "• K/D Ratio, Win Rate\n" .
               "• Level Progress\n" .
               "• Account Info\n" .
               "• Guild Info\n" .
               "• Pet Info\n\n" .
               "🔒 **Privacy:**\n" .
               "Your searches are private\n" .
               "Only you can view your history\n\n" .
               "💝 **Made with ❤️ by YOUR CHANNEL USERNAME**";
    
    $keyboard = getMainKeyboard();
    sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
}

function handleStats($chatId, $userId) {
    $history = getUserHistory($userId);
    $favorites = getFavorites($userId);
    
    $message = "📊 **Your Bot Stats**\n\n" .
               "🔍 **Total Searches:** " . $history['total'] . "\n" .
               "⭐ **Favorites:** " . count($favorites) . "\n" .
               "📅 **Joined:** " . $history['joined'] . "\n\n";
    
    if (!empty($history['searches'])) {
        $lastSearch = end($history['searches']);
        $message .= "🕐 **Last Search:**\n";
        $message .= "• UID: " . ($lastSearch['uid'] ?? 'N/A') . "\n";
        $message .= "• Name: " . ($lastSearch['name'] ?? 'N/A') . "\n";
        $message .= "• Time: " . ($lastSearch['timestamp'] ?? 'N/A') . "\n\n";
    }
    
    $message .= "🏆 **Status:** " . ($history['total'] > 100 ? "🌟 Pro User" : ($history['total'] > 50 ? "⭐ Active User" : "🆕 New User"));
    
    $keyboard = getMainKeyboard();
    sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
}

function handleSearch($chatId, $userId, $uid) {
    // Validate UID
    if (!preg_match('/^\d{10,12}$/', $uid)) {
        sendMessage($chatId, "❌ **Invalid UID!**\n\nUID must be 10-12 digits.\nExample: `1234567890`", 'HTML');
        return;
    }
    
    sendMessage($chatId, "🔍 **Searching for UID: " . htmlspecialchars($uid) . "**\n\n⏳ Please wait...", 'HTML');
    
    // API Call
    $result = getPlayerInfo($uid);
    
    if (!$result['success']) {
        $errorMsg = "❌ **Search Failed!**\n\n";
        $errorMsg .= "🔍 UID: `{$uid}`\n";
        $errorMsg .= "⚠️ Error: " . ($result['error'] ?? 'Unknown error') . "\n\n";
        $errorMsg .= "💡 Try again with a valid UID.";
        
        sendMessage($chatId, $errorMsg, 'HTML');
        return;
    }
    
    $playerData = $result['data'];
    
    // Add to history
    addToHistory($userId, $uid, $playerData);
    
    // Format response
    $infoText = formatPlayerInfo($playerData);
    
    // Get keyboard with context
    $keyboard = getPlayerKeyboardWithContext($uid, $userId);
    
    sendMessage($chatId, $infoText, 'HTML', ['reply_markup' => json_encode($keyboard)]);
}

function handleFavorites($chatId, $userId) {
    $favorites = getFavorites($userId);
    
    if (empty($favorites)) {
        $message = "⭐ **Your Favorites**\n\n" .
                   "📭 You don't have any favorites yet.\n\n" .
                   "💡 Search a player and add them to favorites!";
        
        $keyboard = getMainKeyboard();
        sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
        return;
    }
    
    $message = "⭐ **Your Favorites (" . count($favorites) . ")**\n\n";
    foreach ($favorites as $index => $fav) {
        $num = $index + 1;
        $message .= "{$num}. 👤 **{$fav['name']}**\n";
        $message .= "   🆔 UID: `{$fav['uid']}`\n";
        $message .= "   🕐 Added: {$fav['added']}\n\n";
    }
    $message .= "💡 Click a player below to view their info.";
    
    $keyboard = getFavoritesKeyboard($favorites);
    sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
}

function handleHistory($chatId, $userId, $page = 1) {
    $history = getUserHistory($userId);
    
    if (empty($history['searches'])) {
        $message = "📋 **Your History**\n\n" .
                   "📭 You haven't searched for any player yet.\n\n" .
                   "💡 Start searching for players now!";
        
        $keyboard = getMainKeyboard();
        sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
        return;
    }
    
    $message = "📋 **Your Search History**\n\n";
    $message .= "📊 Total: " . $history['total'] . " searches\n\n";
    
    $perPage = 5;
    $start = ($page - 1) * $perPage;
    $items = array_slice($history['searches'], $start, $perPage);
    
    foreach ($items as $item) {
        $message .= "👤 **{$item['name']}**\n";
        $message .= "   🆔 UID: `{$item['uid']}`\n";
        $message .= "   🕐 {$item['timestamp']}\n\n";
    }
    
    $keyboard = getHistoryKeyboard($history, $page);
    sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
}

function handleClearHistory($chatId, $userId) {
    $history = getUserHistory($userId);
    $history['searches'] = [];
    $history['total'] = 0;
    saveUserHistory($userId, $history);
    
    sendMessage($chatId, "🗑️ **History Cleared!**\n\nAll your search history has been cleared.", 'HTML');
    handleStart($chatId, $userId, 'User');
}

function handleSearchRequest($chatId) {
    $message = "🔍 **Search Player**\n\n" .
               "📌 Enter the Free Fire UID:\n" .
               "• UID must be 10-12 digits\n" .
               "• Example: `1234567890`\n\n" .
               "📊 You'll get complete player info including:\n" .
               "• Basic Info\n" .
               "• BR & Ranked Stats\n" .
               "• Clash Squad Stats\n" .
               "• Guild Info\n" .
               "• Pet Info\n\n" .
               "💡 Send the UID directly or use the button below.";
    
    $keyboard = getSearchKeyboard();
    sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
}

// ============================================================================
// 🔄 Callback Query Handler
// ============================================================================

function handleCallbackQuery($callback) {
    $callbackId = $callback['id'];
    $data = $callback['data'] ?? '';
    $chatId = $callback['message']['chat']['id'] ?? null;
    $messageId = $callback['message']['message_id'] ?? null;
    $userId = $callback['from']['id'] ?? null;
    $firstName = $callback['from']['first_name'] ?? 'User';
    $username = $callback['from']['username'] ?? '';
    
    if (!$chatId || !$messageId || !$userId) {
        return;
    }
    
    answerCallbackQuery($callbackId, '✅ Processing...');
    
    if ($data === 'search') {
        handleSearchRequest($chatId);
    } elseif ($data === 'search_uid') {
        sendMessage($chatId, "🔍 **Enter UID:**\n\nPlease send a valid Free Fire UID (10-12 digits).", 'HTML');
        editMessageText($chatId, $messageId, "🔍 Enter UID to search", 'HTML');
    } elseif ($data === 'favorites') {
        handleFavorites($chatId, $userId);
    } elseif ($data === 'history') {
        handleHistory($chatId, $userId);
    } elseif ($data === 'stats') {
        handleStats($chatId, $userId);
    } elseif ($data === 'help') {
        handleHelp($chatId);
    } elseif ($data === 'about') {
        handleAbout($chatId);
    } elseif ($data === 'back_main') {
        handleStart($chatId, $userId, $firstName, $username);
    } elseif ($data === 'clear_history') {
        handleClearHistory($chatId, $userId);
    } elseif ($data === 'noop') {
        answerCallbackQuery($callbackId, '🔘 No action', false);
    } elseif (strpos($data, 'history_page_') === 0) {
        $page = (int)str_replace('history_page_', '', $data);
        handleHistory($chatId, $userId, $page);
    } elseif (strpos($data, 'view_history_') === 0) {
        $uid = str_replace('view_history_', '', $data);
        handleSearch($chatId, $userId, $uid);
    } elseif (strpos($data, 'view_fav_') === 0) {
        $uid = str_replace('view_fav_', '', $data);
        handleSearch($chatId, $userId, $uid);
    } elseif (strpos($data, 'refresh_') === 0) {
        $uid = str_replace('refresh_', '', $data);
        handleSearch($chatId, $userId, $uid);
    } elseif (strpos($data, 'add_fav_') === 0) {
        $uid = str_replace('add_fav_', '', $data);
        $result = getPlayerInfo($uid);
        if ($result['success'] && addFavorite($userId, $uid, $result['data'])) {
            answerCallbackQuery($callbackId, '⭐ Added to favorites!', false);
            handleSearch($chatId, $userId, $uid);
        } else {
            answerCallbackQuery($callbackId, '❌ Already in favorites', true);
        }
    } elseif (strpos($data, 'remove_fav_') === 0) {
        $uid = str_replace('remove_fav_', '', $data);
        removeFavorite($userId, $uid);
        answerCallbackQuery($callbackId, '⭐ Removed from favorites', false);
        handleSearch($chatId, $userId, $uid);
    }
}

// ============================================================================
// 🚀 Main Webhook Handler
// ============================================================================

$input = file_get_contents('php://input');
if (!$input) {
    http_response_code(200);
    exit('OK');
}

$update = json_decode($input, true);
if (!is_array($update)) {
    http_response_code(200);
    exit('OK');
}

// Handle callback queries
if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
    http_response_code(200);
    exit('OK');
}

// Handle messages
$message = $update['message'] ?? null;
if (!$message) {
    http_response_code(200);
    exit('OK');
}

$chatId = $message['chat']['id'] ?? 0;
$chatType = $message['chat']['type'] ?? '';
$messageId = $message['message_id'] ?? 0;
$userId = $message['from']['id'] ?? 0;
$firstName = $message['from']['first_name'] ?? 'User';
$username = $message['from']['username'] ?? '';
$text = $message['text'] ?? '';

// Private chat only
if ($chatType !== 'private') {
    sendMessage($chatId, "❌ Please use me in private chat only.", 'HTML');
    http_response_code(200);
    exit('OK');
}

// Commands
if (!empty($text) && strpos($text, '/') === 0) {
    $command = strtolower(trim($text));
    $botUsername = BOT_USERNAME;
    if (strpos($command, '@' . $botUsername) !== false) {
        $command = str_replace('@' . $botUsername, '', $command);
    }
    $command = trim($command);
    
    if ($command === '/start') {
        handleStart($chatId, $userId, $firstName, $username);
    } elseif ($command === '/help') {
        handleHelp($chatId);
    } elseif ($command === '/about') {
        handleAbout($chatId);
    } elseif ($command === '/stats') {
        handleStats($chatId, $userId);
    } elseif ($command === '/history') {
        handleHistory($chatId, $userId);
    } elseif ($command === '/favorites') {
        handleFavorites($chatId, $userId);
    } elseif (strpos($command, '/search') === 0) {
        $uid = trim(substr($command, 7));
        if (!empty($uid)) {
            handleSearch($chatId, $userId, $uid);
        } else {
            handleSearchRequest($chatId);
        }
    } elseif (strpos($command, '/') === 0) {
        sendMessage($chatId, "❌ Unknown command. Use /help for available commands.", 'HTML');
    }
    
    http_response_code(200);
    exit('OK');
}

// Text message (could be UID)
if (!empty($text)) {
    if (preg_match('/^\d{10,12}$/', trim($text))) {
        handleSearch($chatId, $userId, trim($text));
    } else {
        $message = "❌ **Invalid Input!**\n\n" .
                   "Please enter a valid Free Fire UID (10-12 digits).\n\n" .
                   "Example: `1234567890`\n\n" .
                   "🔽 Use the buttons below:";
        
        $keyboard = getMainKeyboard();
        sendMessage($chatId, $message, 'HTML', ['reply_markup' => json_encode($keyboard)]);
    }
    
    http_response_code(200);
    exit('OK');
}

http_response_code(200);
exit('OK');
?>