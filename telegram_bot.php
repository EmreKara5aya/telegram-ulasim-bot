<?php
// Telegram bot that can greet, expose latest updates, and guide users through route planning.
// Stores the most recent update in latest_update.json for debugging purposes and
// uses belediye rotalama servisini to fetch route suggestions between shared locations.

require_once __DIR__ . '/auth_storage.php';
require_once __DIR__ . '/places_storage.php';

date_default_timezone_set('Europe/Istanbul');

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '8130150979:AAEouP9Mi71vha-uek7CianT1PFZ16wF-HQ';

const TRACK_REQUEST_TTL = 60; // seconds
const TRACK_MAX_CONCURRENT = 3;

if (PHP_SAPI === 'cli') {
    $argv = $_SERVER['argv'] ?? [];
    $command = $argv[1] ?? '';
    if ($command === 'track-worker' && isset($argv[2], $argv[3])) {
        $chatId = $argv[2];
        $trackingToken = $argv[3];
        runTrackingLoop($botToken, $chatId, $trackingToken, true);
        exit;
    }
}

if (isset($_GET['track_worker']) && $_GET['track_worker'] !== '' && isset($_GET['chat_id'], $_GET['token'])) {
    $workerChatId = $_GET['chat_id'];
    $workerToken = $_GET['token'];

    if ($workerChatId === '' || $workerToken === '') {
        http_response_code(400);
        echo 'Invalid worker payload';
        return;
    }

    echo 'OK';
    runTrackingLoop($botToken, $workerChatId, $workerToken, false);
    releaseTrackingWorker($workerToken);
    return;
}

$rawUpdate = file_get_contents('php://input');
if ($rawUpdate === false || $rawUpdate === '') {
    http_response_code(400);
    echo 'No update payload received.';
    exit;
}

$update = json_decode($rawUpdate, true);
if (!is_array($update)) {
    http_response_code(400);
    echo 'Invalid update payload.';
    exit;
}

logUpdate($update);

$callbackQuery = $update['callback_query'] ?? null;
if ($callbackQuery) {
    $postCallback = handleCallbackQuery($botToken, $callbackQuery);
    echo 'OK';
    if (is_callable($postCallback)) {
        $postCallback();
    }
    return;
}

$message = $update['message'] ?? null;
$chatId = $message['chat']['id'] ?? null;
$text = isset($message['text']) ? trim($message['text']) : '';
$location = $message['location'] ?? null;

if (!$chatId) {
    http_response_code(200);
    echo 'Nothing to do.';
    exit;
}

$chatIdString = (string)$chatId;

if ($text === '/start') {
    clearChatState($chatId);
    sendMainMenu($botToken, $chatId);
    echo 'OK';
    exit;
}

if ($text !== '' && handleRegistrationCommand($botToken, $chatId, $chatIdString, $text)) {
    echo 'OK';
    exit;
}

$state = loadChatState($chatId);
$isRegistered = authIsUserRegistered($chatIdString);

if (!$isRegistered) {
    clearChatState($chatId);
    if ($text !== '/start') {
        sendMainMenu($botToken, $chatId);
    }
    echo 'OK';
    exit;
}

if ($text === '✨ Menüye Dön') {
    clearChatState($chatId);
    sendMainMenu($botToken, $chatId);
    echo 'OK';
    exit;
}

if (($state['step'] ?? '') === 'place_add_wait_location') {
    if (is_array($location)) {
        $state['pending_place'] = [
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
        ];
        $state['step'] = 'place_add_wait_name';
        saveChatState($chatId, $state);
        sendTelegramMessage($botToken, $chatId, 'Konumu aldım. Şimdi bu yere bir isim ver (örn. Ev, İş, Okul).', [
            'reply_markup' => [
                'keyboard' => [
                    [
                        ['text' => '✨ Menüye Dön'],
                    ],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false,
            ],
        ]);
    } else {
        promptForLocation($botToken, $chatId, 'Konumu alamadım. Lütfen yer kaydı için bulunduğun konumu paylaş.', true);
    }
    echo 'OK';
    exit;
}

if (($state['step'] ?? '') === 'place_add_wait_name') {
    if ($text === '✨ Menüye Dön') {
        clearChatState($chatId);
        sendMainMenu($botToken, $chatId);
        echo 'OK';
        exit;
    }

    if ($text !== '') {
        $pending = $state['pending_place'] ?? null;
        if (!is_array($pending) || !isset($pending['latitude'], $pending['longitude'])) {
            clearChatState($chatId);
            sendTelegramMessage($botToken, $chatId, 'Beklenmeyen bir hata oluştu. Lütfen yeniden deneyin.');
            sendPlacesMenu($botToken, $chatId, $chatIdString);
            echo 'OK';
            exit;
        }

        $name = placesSanitizeName($text);
        if ($name === '') {
            sendTelegramMessage($botToken, $chatId, 'Geçerli bir ad yazmalısın. Örneğin: Ev, İş, Okul');
            echo 'OK';
            exit;
        }

        $result = placesAdd($chatIdString, $name, (float)$pending['latitude'], (float)$pending['longitude']);
        if (($result['status'] ?? '') === 'created') {
            sendTelegramMessage($botToken, $chatId, '📌 "' . $name . '" kaydedildi.');
        } else {
            sendTelegramMessage($botToken, $chatId, 'Yer kaydedilirken hata oluştu.');
        }

        clearChatState($chatId);
        sendPlacesMenu($botToken, $chatId, $chatIdString);
        sendMainMenu($botToken, $chatId);
        echo 'OK';
        exit;
    }

    echo 'OK';
    exit;
}

if ($text === '📌 Kayıtlı Yerler') {
    sendPlacesMenu($botToken, $chatId, $chatIdString);
    echo 'OK';
    exit;
}

if ($text === '🕒 Hareket Saatleri') {
    $linesData = loadBusLines();
    if (!$linesData['ok']) {
        sendTelegramMessage($botToken, $chatId, 'Hat listesi şu anda alınamadı: ' . $linesData['error']);
    } else {
        $messageId = sendBusLinesMenuMessage($botToken, $chatId, $linesData['lines'], 0);
        if ($messageId !== null) {
            saveChatState($chatId, [
                'step' => 'bus_lines',
                'page' => 0,
                'mode' => 'list',
                'query' => '',
                'message_id' => $messageId,
            ]);
        }
    }
    echo 'OK';
    exit;
}

if (($state['step'] ?? '') === 'bus_lines' && $text !== '') {
    $linesData = loadBusLines();
    if (!$linesData['ok']) {
        sendTelegramMessage($botToken, $chatId, 'Hat listesi okunamadı: ' . $linesData['error']);
        clearChatState($chatId);
    } else {
        $messageId = $state['message_id'] ?? null;
        $messageId = sendBusLinesSearchMessage($botToken, $chatId, $linesData['lines'], $text, $messageId);
        $state['step'] = 'bus_lines';
        $state['mode'] = 'search';
        $state['query'] = $text;
        if ($messageId !== null) {
            $state['message_id'] = $messageId;
        }
        saveChatState($chatId, $state);
    }
    echo 'OK';
    exit;
}

// Handle button flow first so expectations take priority.
if ($text === '😊 Yeni Rota Planla') {
    saveChatState($chatId, ['step' => 'awaiting_origin']);
    promptForLocation($botToken, $chatId, 'Lütfen bulunduğunuz konumu paylaşın.');
    sendSavedPlacesOptions($botToken, $chatId, $chatIdString, 'origin');
    echo 'OK';
    exit;
}

if (($state['step'] ?? null) === 'awaiting_origin') {
    if (is_array($location)) {
        $state['origin'] = $location;
        $state['step'] = 'awaiting_destination';
        saveChatState($chatId, $state);
        promptForLocation(
            $botToken,
            $chatId,
            'Harika! Şimdi gitmek istediğiniz konumu harita üzerinden paylaşın. Telegram’da ataş ikonuna dokunup "Konum" > "Konum seç" adımlarını kullanabilirsin.',
            false
        );
        sendSavedPlacesOptions($botToken, $chatId, $chatIdString, 'destination');
    } else {
        promptForLocation($botToken, $chatId, 'Konumu alamadım. Lütfen bulunduğunuz konumu paylaş butonunu kullanarak gönder.', true);
        sendSavedPlacesOptions($botToken, $chatId, $chatIdString, 'origin');
    }
    echo 'OK';
    exit;
}

if (($state['step'] ?? null) === 'awaiting_destination') {
    if (is_array($location)) {
        $state['destination'] = $location;
        summarizeRoutePlanning($botToken, $chatId, $state);
        clearChatState($chatId);
    } else {
        promptForLocation(
            $botToken,
            $chatId,
            'Hedef konumu alamadım. Lütfen harita üzerinden gitmek istediğiniz konumu seçerek paylaş.',
            false
        );
        sendSavedPlacesOptions($botToken, $chatId, $chatIdString, 'destination');
    }
    echo 'OK';
    exit;
}

// General commands and responses.
if ($text !== '' && strcasecmp($text, 'Merhaba') === 0) {
    sendTelegramMessage($botToken, $chatId, 'Merhaba');
}

echo 'OK';

function sendTelegramMessage(string $token, $chatId, string $text, array $extraPayload = []): ?array
{
    if (!$token || $token === 'REPLACE_WITH_REAL_TOKEN') {
        return null;
    }

    $payload = array_merge($extraPayload, [
        'chat_id' => $chatId,
        'text'    => $text,
    ]);

    // Telegram expects reply_markup as JSON string when present.
    if (isset($payload['reply_markup']) && is_array($payload['reply_markup'])) {
        $payload['reply_markup'] = json_encode($payload['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return sendTelegramApi($token, 'sendMessage', $payload);
}

function sendTelegramApi(string $token, string $method, array $payload): ?array
{
    if (!$token || $token === 'REPLACE_WITH_REAL_TOKEN') {
        return null;
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 10,
        ],
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function answerCallbackQuery(string $token, string $callbackId, string $text = '', bool $showAlert = false): void
{
    if ($callbackId === '') {
        return;
    }

    $payload = ['callback_query_id' => $callbackId];
    if ($text !== '') {
        $payload['text'] = $text;
    }
    if ($showAlert) {
        $payload['show_alert'] = true;
    }

    sendTelegramApi($token, 'answerCallbackQuery', $payload);
}

function editMessageText(string $token, $chatId, $messageId, string $text, array $extraPayload = []): ?array
{
    if (!$token || $token === 'REPLACE_WITH_REAL_TOKEN') {
        return null;
    }

    $payload = array_merge($extraPayload, [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'text'       => $text,
    ]);

    if (isset($payload['reply_markup']) && is_array($payload['reply_markup'])) {
        $payload['reply_markup'] = json_encode($payload['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return sendTelegramApi($token, 'editMessageText', $payload);
}

function sendMainMenu(string $token, $chatId): void
{
    $isRegistered = authIsUserRegistered((string)$chatId);

    if (!$isRegistered) {
        $lines = [
            '✨ <b>Mersin Ulaşım Asistanı</b>',
            '',
            '🔒 Bu bot yalnızca yetkilendirilmiş kullanıcılar tarafından kullanılabilir.',
            'Lütfen erişim için yönetici ile iletişime geçin.',
        ];

        sendTelegramMessage($token, $chatId, implode("\n", $lines), [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => ['remove_keyboard' => true],
        ]);
        return;
    }

    $keyboard = [
        'keyboard' => [
            [
                ['text' => '😊 Yeni Rota Planla'],
            ],
            [
                ['text' => '📌 Kayıtlı Yerler'],
            ],
            [
                ['text' => '🕒 Hareket Saatleri'],
            ],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
    ];

    $lines = [
        '✨ <b>Mersin Ulaşım Asistanı</b>',
        '',
        '📍 <b>Yeni rota planla:</b> Konumunu paylaş, belediye verileriyle en uygun hatları listeleyeyim.',
        '🚌 <b>Canlı takip:</b> Seçtiğin hattı dakikası dakikasına izleyebilirsin.',
        '🕒 <b>Hareket saatleri:</b> Yetkili hatların kalkış zamanlarını inceleyebilirsin.',
        '👋 Hoş geldin! Dilediğin zaman rota planlamaya başlayabilirsin.',
        'ℹ️ Her zaman <code>/start</code> yazarak bu menüye dönebilirsin.',
        '',
        'Hazırsan aşağıdaki seçeneklere dokun ⬇️',
    ];

    sendTelegramMessage($token, $chatId, implode("\n", $lines), [
        'reply_markup' => $keyboard,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
}

function sendPlacesMenu(string $token, $chatId, string $chatIdString): void
{
    $places = placesLoad($chatIdString);
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '➕ Yeni Yer Kaydet', 'callback_data' => 'places:add'],
            ],
        ],
    ];

    if ($places) {
        $keyboard['inline_keyboard'][] = [
            ['text' => '📚 Kayıtlı Yerleri Göster', 'callback_data' => 'places:list'],
        ];
        $keyboard['inline_keyboard'][] = [
            ['text' => '🗑 Yer Sil', 'callback_data' => 'places:delete-menu'],
        ];
    }

    $lines = [
        '📌 <b>Kayıtlı Yerler</b>',
        '',
        $places
            ? 'Kayıtlı yerlerini aşağıdaki seçeneklerden yönetebilirsin.'
            : 'Henüz kayıtlı yerin yok. Hemen yeni bir yer ekleyebilirsin.',
    ];

    sendTelegramMessage($token, $chatId, implode("\n", $lines), [
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => $keyboard,
    ]);
}

function sendPlacesList(string $token, $chatId, string $chatIdString): void
{
    $places = placesLoad($chatIdString);
    if (!$places) {
        sendTelegramMessage($token, $chatId, 'Henüz kayıtlı yer bulunmuyor.');
        return;
    }

    sendTelegramMessage($token, $chatId, '📚 <b>Kayıtlı Yerler</b>', [
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);

    foreach ($places as $place) {
        if (!isset($place['latitude'], $place['longitude'])) {
            continue;
        }

        $caption = '<b>' . h($place['name'] ?? 'İsimsiz Yer') . '</b>';

        sendTelegramApi($token, 'sendLocation', [
            'chat_id' => $chatId,
            'latitude' => (float)$place['latitude'],
            'longitude' => (float)$place['longitude'],
            'horizontal_accuracy' => 20,
            'live_period' => 0,
            'reply_markup' => ['inline_keyboard' => [[['text' => '📍 Google Maps', 'url' => buildGoogleMapsUrl((float)$place['latitude'], (float)$place['longitude'], $place['name'] ?? '')]]]],
        ]);

        sendTelegramMessage($token, $chatId, $caption, [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }
}

function sendPlacesDeleteMenu(string $token, $chatId, string $chatIdString): void
{
    $places = placesLoad($chatIdString);
    if (!$places) {
        sendTelegramMessage($token, $chatId, 'Silinecek kayıtlı yer bulunamadı.');
        return;
    }

    $rows = [];
    foreach ($places as $place) {
        $rows[] = [
            [
                'text' => '🗑 ' . $place['name'],
                'callback_data' => 'places:delete|' . $place['id'],
            ],
        ];
    }

    sendTelegramMessage($token, $chatId, 'Silmek istediğin yeri seç:', [
        'reply_markup' => ['inline_keyboard' => $rows],
        'disable_web_page_preview' => true,
    ]);
}

function sendSavedPlacesOptions(string $token, $chatId, string $chatIdString, string $mode): void
{
    $places = placesLoad($chatIdString);
    if (!$places) {
        return;
    }

    $rows = [];
    foreach (array_slice($places, 0, 12) as $place) {
        $rows[] = [
            [
                'text' => '🏷 ' . $place['name'],
                'callback_data' => 'places:use|' . $mode . '|' . $place['id'],
            ],
        ];
    }

    $title = $mode === 'origin'
        ? '📌 Biniş noktası için kayıtlı yerler:'
        : '📌 İniş noktası için kayıtlı yerler:';

    sendTelegramMessage($token, $chatId, $title, [
        'reply_markup' => ['inline_keyboard' => $rows],
        'disable_web_page_preview' => true,
    ]);
}

function loadBusLines(): array
{
    $path = __DIR__ . '/state/bus_lines.json';
    if (!is_readable($path)) {
        return ['ok' => false, 'error' => 'Hat listesi dosyası bulunamadı.'];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Hat listesi okunamadı.'];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !isset($decoded['lines']) || !is_array($decoded['lines'])) {
        return ['ok' => false, 'error' => 'Hat listesi verisi geçersiz.'];
    }

    return ['ok' => true, 'lines' => $decoded['lines']];
}

function sendBusLinesMenuMessage(string $token, $chatId, array $lines, int $page = 0, ?int $messageId = null): ?int
{
    $pageData = paginateBusLines($lines, $page);
    $textLines = [
        '🕒 <b>Hareket Saatleri</b>',
        '',
        'Hat seçmek için aşağıdaki butonları kullanabilirsin.',
        'Aramak istersen hat numarasını veya hattın adını mesaj olarak yaz (örn. <code>22M</code> veya <code>Çarşı</code>).',
        '',
        sprintf('Sayfa %d/%d', $pageData['page'] + 1, $pageData['total_pages']),
    ];

    $keyboard = buildBusLinesPageKeyboard($pageData);
    $payload = [
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => $keyboard,
    ];

    if ($messageId === null) {
        $response = sendTelegramMessage($token, $chatId, implode("\n", $textLines), $payload);
        return $response['result']['message_id'] ?? null;
    }

    editMessageText($token, $chatId, $messageId, implode("\n", $textLines), $payload);
    return $messageId;
}

function sendBusLinesSearchMessage(string $token, $chatId, array $lines, string $query, ?int $messageId = null): ?int
{
    $results = filterBusLines($lines, $query);
    $heading = '🔍 <b>Arama sonuçları</b>';
    if (!$results) {
        $text = $heading . "\n\n" . 'Eşleşen hat bulunamadı. Başka bir ifade deneyebilirsin veya "⬅️ Listeye dön" butonu ile tüm listeye dönebilirsin.';
        $keyboard = ['inline_keyboard' => [[['text' => '⬅️ Listeye dön', 'callback_data' => 'bus:mode|list']]]];
    } else {
        $textLines = [$heading, '', 'Arama: <code>' . h($query) . '</code>', sprintf('%d sonuç bulundu.', count($results))];
        $keyboard = buildBusLinesSearchKeyboard($results);
        $text = implode("\n", $textLines);
    }

    $payload = [
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => $keyboard,
    ];

    if ($messageId === null) {
        $response = sendTelegramMessage($token, $chatId, $text, $payload);
        return $response['result']['message_id'] ?? null;
    }

    editMessageText($token, $chatId, $messageId, $text, $payload);
    return $messageId;
}

function paginateBusLines(array $lines, int $page, int $pageSize = 8): array
{
    $total = max(0, count($lines));
    $totalPages = max(1, (int)ceil($total / $pageSize));
    $page = max(0, min($page, $totalPages - 1));
    $offset = $page * $pageSize;

    return [
        'items' => array_slice($lines, $offset, $pageSize),
        'page' => $page,
        'total_pages' => $totalPages,
        'has_prev' => $page > 0,
        'has_next' => $page < $totalPages - 1,
    ];
}

function buildBusLinesPageKeyboard(array $pageData): array
{
    $rows = [];
    foreach ($pageData['items'] as $line) {
        if (!isset($line['hat_no'], $line['hat_yon'])) {
            continue;
        }

        $rows[] = [[
            'text' => busLineLabel($line),
            'callback_data' => 'bus:line|' . ($line['post'] ?? ($line['hat_no'] . '-' . $line['hat_yon'])),
        ]];
    }

    $navRow = [];
    if ($pageData['has_prev']) {
        $navRow[] = ['text' => '⬅️ Önceki', 'callback_data' => 'bus:page|' . ($pageData['page'] - 1)];
    }
    $navRow[] = ['text' => '🔍 Filtrele', 'callback_data' => 'bus:search|prompt'];
    if ($pageData['has_next']) {
        $navRow[] = ['text' => '➡️ Sonraki', 'callback_data' => 'bus:page|' . ($pageData['page'] + 1)];
    }

    $rows[] = $navRow;
    return ['inline_keyboard' => $rows];
}

function buildBusLinesSearchKeyboard(array $results): array
{
    $rows = [];
    foreach (array_slice($results, 0, 12) as $line) {
        $rows[] = [[
            'text' => busLineLabel($line),
            'callback_data' => 'bus:line|' . ($line['post'] ?? ($line['hat_no'] . '-' . $line['hat_yon'])),
        ]];
    }

    $rows[] = [
        ['text' => '⬅️ Listeye dön', 'callback_data' => 'bus:mode|list'],
    ];

    return ['inline_keyboard' => $rows];
}

function filterBusLines(array $lines, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $lower = busLower($query);
    $matched = [];
    foreach ($lines as $line) {
        $haystack = busLower(($line['hat_no'] ?? '') . ' ' . ($line['hat_adi'] ?? '') . ' ' . ($line['bolge'] ?? ''));
        if (strpos($haystack, $lower) !== false) {
            $matched[] = $line;
        }
    }

    return $matched;
}

function busLineLabel(array $line): string
{
    $parts = [];
    $parts[] = ($line['hat_no'] ?? '???') . '-' . ($line['hat_yon'] ?? '');
    if (!empty($line['hat_adi'])) {
        $parts[] = $line['hat_adi'];
    }
    if (!empty($line['bolge'])) {
        $parts[] = '(' . $line['bolge'] . ')';
    }

    return implode(' ', array_filter($parts, static fn($item) => $item !== ''));
}

function findBusLineByPost(array $lines, string $post): ?array
{
    foreach ($lines as $line) {
        $linePost = $line['post'] ?? (($line['hat_no'] ?? '') . '-' . ($line['hat_yon'] ?? ''));
        if ($linePost === $post) {
            return $line;
        }
    }

    return null;
}

function busLower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function fetchBusSchedule(string $post): array
{
    $url = 'https://ulasim.mersin.bel.tr/ajax/bilgi.php';
    $payload = http_build_query([
        'hat_no' => $post,
        'tipi'   => 'tarifeler',
    ], '', '&', PHP_QUERY_RFC3986);

    $headers = [
        'Host: ulasim.mersin.bel.tr',
        'User-Agent: Mozilla/5.0 (TelegramBot)',
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://ulasim.mersin.bel.tr',
        'Referer: https://ulasim.mersin.bel.tr/tarifeler.php',
    ];

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Sunucuda cURL bulunamadı.'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL başlatılamadı.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch) ?: 'cURL hatası';
        curl_close($ch);
        return ['ok' => false, 'error' => $error];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'HTTP durum: ' . $status];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Geçersiz JSON'];
    }

    $grouped = normalizeBusSchedule($decoded);
    if (!$grouped) {
        return ['ok' => false, 'error' => 'Bu hat için tarifeler bulunamadı.'];
    }

    return ['ok' => true, 'schedule' => $grouped];
}

function normalizeBusSchedule(array $items): array
{
    $grouped = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $day = extractScalar($item['tarife_gun'] ?? null);
        $time = extractScalar($item['saat'] ?? null);
        if ($time === '') {
            continue;
        }

        $title = extractScalar($item['baslik'] ?? null);
        $note = extractScalar($item['tarife_not'] ?? null);
        $desc = extractScalar($item['aciklama'] ?? null);

        $dayKey = $day !== '' ? $day : 'Diğer';
        $grouped[$dayKey][] = [
            'time'  => $time,
            'title' => $title,
            'note'  => $note,
            'desc'  => $desc,
        ];
    }

    foreach ($grouped as &$entries) {
        usort($entries, static function (array $a, array $b): int {
            return strcmp($a['time'], $b['time']);
        });
    }

    return $grouped;
}

function sendBusScheduleMessages(string $token, $chatId, array $line, array $schedule): void
{
    $titleParts = [];
    $titleParts[] = '🕒 <b>' . h($line['hat_no'] ?? '') . '-' . h($line['hat_yon'] ?? '') . '</b>';
    if (!empty($line['hat_adi'])) {
        $titleParts[] = h($line['hat_adi']);
    }
    if (!empty($line['bolge'])) {
        $titleParts[] = '(' . h($line['bolge']) . ')';
    }

    $selection = selectScheduleForToday($schedule);
    $dayLabel = $selection['label'];
    $entries = $selection['entries'];

    $lines = [];
    $lines[] = implode(' ', $titleParts);
    $lines[] = '';
    $lines[] = '📅 <b>' . h($dayLabel) . '</b> — ' . h(date('d.m.Y'));
    $lines[] = '──────────────';

    if (!$entries) {
        $lines[] = '⚠️ Bugün için tarife bilgisi bulunamadı.';
    } else {
        foreach ($entries as $entry) {
            $lines[] = formatBusScheduleLine($entry);
        }
    }

    $lines[] = '──────────────';
    $lines[] = '🔁 Diğer günler için menüden yeniden hat seçebilirsin.';

    sendTelegramMessage($token, $chatId, implode("\n", $lines), [
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
}

function formatBusScheduleLine(array $entry): string
{
    $time = '<b>' . h($entry['time'] ?? '') . '</b>';
    $title = trim((string)($entry['title'] ?? ''));
    $note = trim((string)($entry['note'] ?? ''));
    $desc = trim((string)($entry['desc'] ?? ''));

    $emoji = scheduleStatusEmoji($title, $note);
    $noteLower = normalizeScheduleKeyword($note);
    $titleLower = normalizeScheduleKeyword($title);
    $isOutOfService = strpos($titleLower, 'servisdis') !== false || ($noteLower !== '' && strpos($noteLower, 'servisdis') !== false);

    $fragments = [$time];
    if ($title !== '') {
        if ($isOutOfService) {
            $fragments[] = '<b>⚠️ ' . h($title) . '</b>';
        } elseif (strpos($titleLower, 'gec') !== false) {
            $fragments[] = '⏰ <b>' . h($title) . '</b>';
        } else {
            $fragments[] = h($title);
        }
    }
    if ($note !== '') {
        if ($isOutOfService) {
            $fragments[] = '<b>⚠️ Servis Dışı</b>';
        } else {
            $fragments[] = '<i>' . h($note) . '</i>';
        }
    }
    if ($desc !== '') {
        $fragments[] = '(' . h($desc) . ' dk)';
    }

    $line = $emoji . ' ' . implode(' – ', array_filter($fragments, static fn($part) => $part !== ''));

    if ($isOutOfService) {
        $line .= "\n" . '   <b>⚠️ Servis Dışı:</b> Araç bu saat için serviste değildir.';
    }

    return $line;
}

function scheduleStatusEmoji(string $title, string $note): string
{
    $titleNorm = normalizeScheduleKeyword($title);
    $noteNorm = normalizeScheduleKeyword($note);

    if ($titleNorm !== '' && strpos($titleNorm, 'servisdis') !== false) {
        return '🚫';
    }

    if ($titleNorm !== '' && (strpos($titleNorm, 'gec') !== false || strpos($titleNorm, 'geç') !== false)) {
        return '⏰';
    }

    if ($noteNorm !== '') {
        return '📍';
    }

    return '🚌';
}

function selectScheduleForToday(array $schedule): array
{
    $items = [];
    foreach ($schedule as $label => $entries) {
        $items[] = [
            'label' => $label,
            'entries' => is_array($entries) ? $entries : [],
            'normalized' => normalizeDayKey((string)$label),
        ];
    }

    $today = (int)date('N');
    $targets = [];
    if ($today >= 1 && $today <= 5) {
        $targets[] = ['label' => 'Haftaiçi', 'patterns' => ['haftaici']];
    } elseif ($today === 6) {
        $targets[] = ['label' => 'Cumartesi', 'patterns' => ['cumartesi']];
    } else {
        $targets[] = ['label' => 'Pazar', 'patterns' => ['pazar']];
    }
    $targets[] = ['label' => 'Cumartesi', 'patterns' => ['cumartesi']];
    $targets[] = ['label' => 'Pazar', 'patterns' => ['pazar']];
    $targets[] = ['label' => 'Haftaiçi', 'patterns' => ['haftaici']];

    foreach ($targets as $target) {
        $match = collectScheduleByPatterns($items, $target['patterns']);
        if ($match !== null) {
            return [
                'label' => combineScheduleLabels($match['labels'], $target['label']),
                'entries' => dedupeScheduleEntries($match['entries']),
            ];
        }
    }

    $first = reset($items);
    if ($first === false) {
        return ['label' => 'Tarife', 'entries' => []];
    }

    return [
        'label' => $first['label'],
        'entries' => dedupeScheduleEntries($first['entries']),
    ];
}

function collectScheduleByPatterns(array $items, array $patterns): ?array
{
    $labels = [];
    $entries = [];
    foreach ($items as $item) {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (strpos($item['normalized'], $pattern) !== false) {
                $labels[] = $item['label'];
                $entries = array_merge($entries, $item['entries']);
                break;
            }
        }
    }

    if (!$entries) {
        return null;
    }

    return ['labels' => $labels, 'entries' => $entries];
}

function combineScheduleLabels(array $labels, string $fallback): string
{
    $labels = array_values(array_unique(array_filter(array_map('trim', $labels), static fn($label) => $label !== '')));
    if (!$labels) {
        return $fallback;
    }
    if (count($labels) === 1) {
        return $labels[0];
    }
    return $fallback . ' (' . implode(', ', $labels) . ')';
}

function dedupeScheduleEntries(array $entries): array
{
    $unique = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = ($entry['time'] ?? '') . '|' . ($entry['title'] ?? '') . '|' . ($entry['note'] ?? '') . '|' . ($entry['desc'] ?? '');
        $unique[$key] = $entry;
    }

    $result = array_values($unique);
    usort($result, static function (array $a, array $b): int {
        return strcmp($a['time'] ?? '', $b['time'] ?? '');
    });

    return $result;
}

function normalizeDayKey(string $value): string
{
    return normalizeScheduleKeyword($value);
}

function normalizeScheduleKeyword(string $value): string
{
    $map = ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ç' => 'c', 'ö' => 'o', 'ü' => 'u'];
    $lower = busLower($value);
    $lower = str_replace(array_keys($map), array_values($map), $lower);
    return preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';
}

function handleSavedPlaceSelection(string $token, $chatId, string $chatIdString, string $mode, array $place): void
{
    $state = loadChatState($chatId);

    if ($mode === 'origin') {
        $state['origin'] = [
            'latitude' => $place['latitude'],
            'longitude' => $place['longitude'],
            'source' => 'saved_place',
            'place_id' => $place['id'],
            'place_name' => $place['name'],
        ];
        $state['step'] = 'awaiting_destination';
        saveChatState($chatId, $state);

        sendTelegramMessage($token, $chatId, '🚏 Biniş noktası olarak <b>' . $place['name'] . '</b> seçildi.', [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        promptForLocation(
            $token,
            $chatId,
            'Harika! Şimdi gitmek istediğin konumu harita üzerinden paylaş veya kayıtlı yerlerinden seç.',
            false
        );
        sendSavedPlacesOptions($token, $chatId, $chatIdString, 'destination');
        return;
    }

    if ($mode === 'destination') {
        if (($state['origin']['latitude'] ?? null) === null) {
            sendTelegramMessage($token, $chatId, 'Önce biniş noktasını seçmelisin.');
            return;
        }

        $state['destination'] = [
            'latitude' => $place['latitude'],
            'longitude' => $place['longitude'],
            'source' => 'saved_place',
            'place_id' => $place['id'],
            'place_name' => $place['name'],
        ];
        saveChatState($chatId, $state);

        sendTelegramMessage($token, $chatId, '🎯 İniş noktası olarak <b>' . $place['name'] . '</b> seçildi.', [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        summarizeRoutePlanning($token, $chatId, $state);
        clearChatState($chatId);
    }
}

function handleRegistrationCommand(string $token, $chatId, string $chatIdString, string $text): bool
{
    if (!preg_match('/^\/(?:register|kayit)(?:@[\w_]+)?(?:\s+(.*))?$/iu', $text, $matches)) {
        return false;
    }

    sendTelegramMessage($token, $chatId, 'Yeni kullanıcı kaydı şu anda kapalı.');

    return true;
}

function promptForLocation(string $token, $chatId, string $prompt, bool $quickShareButton = true): void
{
    if ($quickShareButton) {
        $keyboard = [
            'keyboard' => [
                [
                    ['text' => '📍 Konumumu Paylaş', 'request_location' => true],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];

        sendTelegramMessage($token, $chatId, $prompt, ['reply_markup' => $keyboard]);
        return;
    }

    $extra = ['reply_markup' => ['remove_keyboard' => true]];
    sendTelegramMessage($token, $chatId, $prompt, $extra);
}

function summarizeRoutePlanning(string $token, $chatId, array $state): void
{
    $origin = $state['origin'] ?? [];
    $destination = $state['destination'] ?? [];

    $textLines = [
        'Rota planlama bilgilerini aldım:',
        sprintf('• Başlangıç: %.5f, %.5f', $origin['latitude'] ?? 0, $origin['longitude'] ?? 0),
        sprintf('• Varış: %.5f, %.5f', $destination['latitude'] ?? 0, $destination['longitude'] ?? 0),
        'Belediye servisinden sonuçları alıyorum...'
    ];

    $replyMarkup = ['remove_keyboard' => true];
    sendTelegramMessage($token, $chatId, implode("\n", $textLines), ['reply_markup' => $replyMarkup]);

    $routeResult = fetchRouteSuggestion($origin, $destination);
    logRoutePlanningDebug($chatId, $state, $routeResult);

    if ($routeResult['ok']) {
        if (!empty($routeResult['routes'])) {
            $summarySet = buildRouteSummaries($routeResult['routes']);
            $entries = $summarySet['entries'];
            $skipped = $summarySet['skipped'];

            if ($entries) {
                foreach ($entries as $entry) {
                    $extra = ['disable_web_page_preview' => true];
                    if (!empty($entry['parse_mode'])) {
                        $extra['parse_mode'] = $entry['parse_mode'];
                    }
                    if (!empty($entry['keyboard'])) {
                        $extra['reply_markup'] = $entry['keyboard'];
                    }
                    sendTelegramMessage($token, $chatId, $entry['text'], $extra);
                }
            }

            if ($skipped) {
                $lines = array_map(static fn($item) => '• ' . $item, $skipped);
                $skipText = "🚫 Aşağıdaki hatlar için şu an araç görünmüyor:\n" . implode("\n", $lines);
                sendTelegramMessage($token, $chatId, $skipText, [
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
            }

            if (!$entries) {
                if ($skipped) {
                    sendTelegramMessage($token, $chatId, 'Şu an için önerilen hatlarda aktif araç görünmüyor. Bir süre sonra yeniden deneyebilirsin.');
                } elseif (!empty($routeResult['raw'])) {
                    sendLongMessage($token, $chatId, "Rota servisi çözümlenemedi, ham yanıtı gönderiyorum:\n" . $routeResult['raw']);
                } else {
                    sendTelegramMessage($token, $chatId, 'Şu an için durağa yaklaşan araç bulunamadı.');
                }
            }
        } elseif (!empty($routeResult['raw'])) {
            sendLongMessage($token, $chatId, "Rota servisi çıktısı:\n" . $routeResult['raw']);
        } else {
            sendTelegramMessage($token, $chatId, 'Rota servisi boş bir yanıt döndürdü.');
        }
    } else {
        $errorText = [
            'Rota servisine erişirken sorun oluştu.',
            'Detay: ' . ($routeResult['error'] ?? 'Bilinmeyen hata.'),
        ];
        if (!empty($routeResult['raw'])) {
            $errorText[] = 'Yanıt: ' . $routeResult['raw'];
        }
        sendLongMessage($token, $chatId, implode("\n", $errorText));
    }

    sendMainMenu($token, $chatId);
}

function sendLongMessage(string $token, $chatId, string $text): void
{
    $maxLength = 3500; // Telegram tek mesaj için 4096 karakter sınırı var, biraz pay bırakıyoruz.
    $length = strlen($text);
    if ($length <= $maxLength) {
        sendTelegramMessage($token, $chatId, $text);
        return;
    }

    for ($offset = 0; $offset < $length; $offset += $maxLength) {
        $chunk = substr($text, $offset, $maxLength);
        sendTelegramMessage($token, $chatId, $chunk);
    }
}

function buildRouteSummaries(array $routes): array
{
    $entries = [];
    $skipped = [];
    $stopCache = [];
    $counter = 1;

    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }

        $parsed = parseRouteAlternative($route);
        if ($parsed === null) {
            debugTrack('buildRouteSummaries: parse failed for hat=' . ($route['hatNo'] ?? '') . ' raw=' . json_encode([
                'baslamaDurak' => $route['baslamaDurak'] ?? null,
                'baslamaDurakAd' => $route['baslamaDurakAd'] ?? null,
                'bitisDurak' => $route['bitisDurak'] ?? null,
                'bitisDurakAd' => $route['bitisDurakAd'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            continue;
        }

        debugTrack('buildRouteSummaries: hat=' . ($parsed['hat_code'] ?? '') .
            ' start=' . ($parsed['start_stop_name'] ?? '') . ' (#' . ($parsed['stop_id'] ?? '') . ')'
            . ' dest=' . ($parsed['dest_stop_name'] ?? '') . ' (#' . ($parsed['dest_stop_id'] ?? '') . ')'
            . ' start_coords=' . json_encode($parsed['stop_coords'] ?? null)
            . ' dest_coords=' . json_encode($parsed['dest_coords'] ?? null)
            . ' raw_baslama=' . json_encode($route['baslamaDurak'] ?? null)
            . ' raw_bitıs=' . json_encode($route['bitisDurak'] ?? null));

        $label = $parsed['cozum'] !== '' ? $parsed['cozum'] : (string)$counter;
        $hatLabel = $parsed['hat_display'] ?: $parsed['hat_code'] ?: 'Bilinmiyor';

        $hatInfo = fetchHatEtaForRoute($parsed, $stopCache);
        logRouteCoordinateSample($parsed, $hatInfo, $route);
        $status = strtoupper((string)($hatInfo['status'] ?? ''));
        if ($status === 'YOK') {
            $skipped[] = '<b>' . h($hatLabel) . '</b>';
            $counter++;
            continue;
        }

        $minutes = $hatInfo['minutes'] ?? null;
        $entries[] = [
            'sort'  => $minutes !== null ? $minutes : PHP_INT_MAX,
            'index' => $counter,
            'entry' => [
                'text'       => formatRouteEntryText($label, $parsed, $hatInfo),
                'keyboard'   => buildRouteButtons($parsed, $hatInfo),
                'parse_mode' => 'HTML',
            ],
        ];
        $counter++;
    }

    usort($entries, static function (array $a, array $b): int {
        if ($a['sort'] === $b['sort']) {
            return $a['index'] <=> $b['index'];
        }
        return $a['sort'] <=> $b['sort'];
    });

    return [
        'entries' => array_map(static fn($item) => $item['entry'], $entries),
        'skipped' => $skipped,
    ];
}

function buildRouteButtons(array $parsedRoute, array $hatMeta): ?array
{
    $rows = [];

    $startCoords = $parsedRoute['stop_coords'] ?? null;
    $destCoords = $parsedRoute['dest_coords'] ?? null;

    if (is_array($startCoords) && isset($startCoords['lat'], $startCoords['lng'])) {
        debugTrack('buildRouteButtons: boarding_coords=' . json_encode($startCoords) . ' stop=' . ($parsedRoute['stop_id'] ?? ''));
        $rows[] = [[
            'text' => '🚶 Bineceğin durağa git',
            'url'  => buildGoogleMapsUrl($startCoords['lat'], $startCoords['lng'], $parsedRoute['start_stop_name'] ?? ''),
        ]];
    } elseif (!empty($parsedRoute['stop_id'])) {
        debugTrack('buildRouteButtons: boarding_url using stop_id=' . $parsedRoute['stop_id']);
        $rows[] = [[
            'text' => '🚶 Bineceğin durağa git',
            'url'  => 'https://ulasim.mersin.bel.tr/durakdetay.php?durak_no=' . urlencode($parsedRoute['stop_id']),
        ]];
    }

    if (is_array($destCoords) && isset($destCoords['lat'], $destCoords['lng'])) {
        debugTrack('buildRouteButtons: arrival_coords=' . json_encode($destCoords) . ' dest_stop=' . ($parsedRoute['dest_stop_id'] ?? ''));
        $rows[] = [[
            'text' => '🎯 İneceğin durağı gör',
            'url'  => buildGoogleMapsUrl($destCoords['lat'], $destCoords['lng'], $parsedRoute['dest_stop_name'] ?? ''),
        ]];
    } elseif (!empty($parsedRoute['dest_stop_id'])) {
        debugTrack('buildRouteButtons: arrival_url using dest_stop_id=' . $parsedRoute['dest_stop_id']);
        $rows[] = [[
            'text' => '🎯 İneceğin durağı gör',
            'url'  => 'https://ulasim.mersin.bel.tr/durakdetay.php?durak_no=' . urlencode($parsedRoute['dest_stop_id']),
        ]];
    }

    if ($startCoords && $destCoords) {
        $delta = abs(($startCoords['lat'] ?? 0) - ($destCoords['lat'] ?? 0)) + abs(($startCoords['lng'] ?? 0) - ($destCoords['lng'] ?? 0));
        if ($delta < 0.0001) {
            debugTrack('buildRouteButtons: warning start/dest coords almost identical for token not available.');
        }
    }

    $token = registerTrackingRequest($parsedRoute, $hatMeta);
    if ($token) {
        $rows[] = [[
            'text'          => '👀 Otobüsü takip et',
            'callback_data' => 'track:start|' . $token,
        ]];
    }

    return $rows ? ['inline_keyboard' => $rows] : null;
}

function formatRouteEntryText(string $label, array $parsed, array $hatInfo): string
{
    $parts = [];
    $parts[] = '🚍 <b>Çözüm #' . h($label) . '</b>';

    $hatLabel = $parsed['hat_display'] ?: $parsed['hat_code'] ?: 'Bilinmiyor';
    $parts[] = '🪧 Hat: <b>' . h($hatLabel) . '</b>';

    if (!empty($parsed['hat_name'])) {
        $parts[] = '📛 ' . h($parsed['hat_name']);
    }

    $boarding = formatStopLine('🚏 Biniş', $parsed['stop_id'] ?? '', $parsed['start_stop_name'] ?? '');
    if ($boarding !== '') {
        $parts[] = $boarding;
    }

    $arrival = formatStopLine('🎯 İniş', $parsed['dest_stop_id'] ?? '', $parsed['dest_stop_name'] ?? '');
    if ($arrival !== '') {
        $parts[] = $arrival;
    }

    $minutes = $hatInfo['minutes'] ?? null;
    if ($minutes !== null) {
        $parts[] = '⏱️ <b>' . h((string)$minutes) . ' dk</b> içinde durağa varması bekleniyor.';
    } elseif (!empty($hatInfo['text'])) {
        $parts[] = 'ℹ️ ' . h($hatInfo['text']);
    }

    $status = strtoupper((string)($hatInfo['status'] ?? ''));
    if ($status === 'VAR') {
        $parts[] = '🚦 Araç şu an takipte.';
    } elseif ($status && $status !== 'VAR') {
        $parts[] = '🚦 Durum: ' . h($status);
    }

    if (!empty($hatInfo['error'])) {
        $parts[] = '⚠️ ' . h($hatInfo['error']);
    }

    $parts[] = '🕒 Güncelleme: ' . h(date('H:i'));

    return implode("\n", array_filter($parts, static fn($line) => $line !== ''));
}

function formatStopLine(string $label, string $stopId, string $stopName): string
{
    $segments = [];
    if ($stopId !== '') {
        $segments[] = '#' . h($stopId);
    }
    if ($stopName !== '') {
        $segments[] = h($stopName);
    }

    if (!$segments) {
        return '';
    }

    return $label . ': <b>' . implode(' ', $segments) . '</b>';
}

function buildGoogleMapsUrl(float $lat, float $lng, string $label = ''): string
{
    return sprintf('https://www.google.com/maps/search/?api=1&query=%.6f,%.6f', $lat, $lng);
}

function handleCallbackQuery(string $token, array $callback): ?callable
{
    $callbackId = $callback['id'] ?? '';
    $data = $callback['data'] ?? '';
    $message = $callback['message'] ?? [];
    $chatId = $message['chat']['id'] ?? null;

    if (!is_string($data) || $data === '') {
        answerCallbackQuery($token, $callbackId);
        return null;
    }

    if ($chatId !== null && strpos($data, 'places:') === 0) {
        $chatIdString = (string)$chatId;

        if ($data === 'places:add') {
            answerCallbackQuery($token, $callbackId, 'Yeni yer için konumu paylaş.');
            saveChatState($chatId, ['step' => 'place_add_wait_location']);
            promptForLocation($token, $chatId, 'Kaydetmek istediğin yerin konumunu paylaş.', true);
            return null;
        }

        if ($data === 'places:list') {
            answerCallbackQuery($token, $callbackId);
            sendPlacesList($token, $chatId, $chatIdString);
            return null;
        }

        if ($data === 'places:delete-menu') {
            answerCallbackQuery($token, $callbackId);
            sendPlacesDeleteMenu($token, $chatId, $chatIdString);
            return null;
        }

        if (strpos($data, 'places:delete|') === 0) {
            $placeId = substr($data, strlen('places:delete|'));
            if ($placeId !== '') {
                $deleted = placesDelete($chatIdString, $placeId);
                answerCallbackQuery($token, $callbackId, $deleted ? 'Yer silindi.' : 'Yer bulunamadı.');
                if ($deleted) {
                    sendPlacesMenu($token, $chatId, $chatIdString);
                }
            } else {
                answerCallbackQuery($token, $callbackId);
            }
            return null;
        }

        if (strpos($data, 'places:use|') === 0) {
            $parts = explode('|', $data, 3);
            $mode = $parts[1] ?? '';
            $placeId = $parts[2] ?? '';
            $place = $placeId !== '' ? placesFind($chatIdString, $placeId) : null;
            if ($place && in_array($mode, ['origin', 'destination'], true)) {
                answerCallbackQuery($token, $callbackId, 'Seçim yapıldı.');
                handleSavedPlaceSelection($token, $chatId, $chatIdString, $mode, $place);
            } else {
                answerCallbackQuery($token, $callbackId, 'Geçersiz yer seçimi.', true);
            }
            return null;
        }
    }

    if ($chatId !== null && strpos($data, 'bus:') === 0) {
        $linesData = loadBusLines();
        if (!$linesData['ok']) {
            answerCallbackQuery($token, $callbackId, 'Hat listesi yüklenemedi.', true);
            return null;
        }

        $lines = $linesData['lines'];
        $state = loadChatState($chatId);
        $messageId = $state['message_id'] ?? null;

        if (strpos($data, 'bus:page|') === 0) {
            $page = (int)substr($data, strlen('bus:page|'));
            if ($messageId === null) {
                answerCallbackQuery($token, $callbackId, 'Liste güncellenemedi.', true);
                return null;
            }
            $messageId = sendBusLinesMenuMessage($token, $chatId, $lines, $page, $messageId);
            $state['step'] = 'bus_lines';
            $state['mode'] = 'list';
            $state['page'] = $page;
            $state['query'] = '';
            if ($messageId !== null) {
                $state['message_id'] = $messageId;
            }
            saveChatState($chatId, $state);
            answerCallbackQuery($token, $callbackId);
            return null;
        }

        if ($data === 'bus:mode|list') {
            if ($messageId === null) {
                answerCallbackQuery($token, $callbackId);
                return null;
            }
            $messageId = sendBusLinesMenuMessage($token, $chatId, $lines, 0, $messageId);
            $state['step'] = 'bus_lines';
            $state['mode'] = 'list';
            $state['page'] = 0;
            $state['query'] = '';
            if ($messageId !== null) {
                $state['message_id'] = $messageId;
            }
            saveChatState($chatId, $state);
            answerCallbackQuery($token, $callbackId, 'Listeye dönüldü.');
            return null;
        }

        if ($data === 'bus:search|prompt') {
            answerCallbackQuery($token, $callbackId, 'Aramak istediğin hatı mesaj olarak yazabilirsin.');
            $state['step'] = 'bus_lines';
            $state['mode'] = 'search';
            saveChatState($chatId, $state);
            return null;
        }

        if (strpos($data, 'bus:line|') === 0) {
            $post = substr($data, strlen('bus:line|'));
            $line = findBusLineByPost($lines, $post);
            if (!$line) {
                answerCallbackQuery($token, $callbackId, 'Hat bulunamadı.', true);
                return null;
            }

            $schedule = fetchBusSchedule($post);
            if (!$schedule['ok']) {
                answerCallbackQuery($token, $callbackId, 'Tarife alınamadı.');
                sendTelegramMessage($token, $chatId, 'Tarife alınamadı: ' . ($schedule['error'] ?? 'Bilinmeyen hata.'));
                return null;
            }

            answerCallbackQuery($token, $callbackId, 'Hat seçildi.');
            sendBusScheduleMessages($token, $chatId, $line, $schedule['schedule']);
            clearChatState($chatId);
            return null;
        }

        answerCallbackQuery($token, $callbackId);
        return null;
    }

    if (strpos($data, 'track:start|') === 0) {
    if ($chatId === null) {
        answerCallbackQuery($token, $callbackId, 'Sohbet bilgisi alınamadı.', true);
        return null;
    }

        $trackingToken = substr($data, strlen('track:start|'));
        $request = loadTrackingRequest($trackingToken);
        if (!$request) {
            answerCallbackQuery($token, $callbackId, 'Takip isteği zaman aşımına uğradı. Yeniden deneyin.', true);
            return null;
        }

        answerCallbackQuery($token, $callbackId, 'Takip başlatılıyor...');
        return startTrackingFromRequest($token, $chatId, $trackingToken, $request);
    }

    if (strpos($data, 'track:stop|') === 0) {
        if ($chatId === null) {
            answerCallbackQuery($token, $callbackId, 'Sohbet bilgisi alınamadı.', true);
            return null;
        }

        $trackingToken = substr($data, strlen('track:stop|'));
        $sessions = loadTrackingState($chatId);
        $state = $sessions[$trackingToken] ?? null;
        if (!$state) {
            answerCallbackQuery($token, $callbackId, 'Aktif takip bulunamadı.', true);
            return null;
        }

        finalizeTracking($token, $state, 'Takip kullanıcı tarafından sonlandırıldı.');
        clearTrackingState($chatId, $trackingToken);
        clearTrackingRequest($trackingToken);
        answerCallbackQuery($token, $callbackId, 'Takip sonlandırıldı.');
        return null;
    }

    answerCallbackQuery($token, $callbackId);
    return null;
}

function startTrackingFromRequest(string $token, $chatId, string $trackingToken, array $request): ?callable
{
    $sessions = loadTrackingState($chatId);
    debugTrack('startTrackingFromRequest: chat=' . $chatId . ' existing=' . count($sessions) . ' token=' . $trackingToken);

    // Tekrarlayan hat/durak takibini yeniden başlat
    foreach ($sessions as $existingToken => $existingSession) {
        if (($existingSession['hat_code'] ?? '') === ($request['hat_code'] ?? '')
            && ($existingSession['stop_id'] ?? '') === ($request['stop_id'] ?? '')) {
            finalizeTracking($token, $existingSession, 'Bu hat için takip yeniden başlatıldı.');
            clearTrackingState($chatId, $existingToken);
            debugTrack('startTrackingFromRequest: cleared duplicate token=' . $existingToken);
        }
    }

    $sessions = loadTrackingState($chatId);

    if (count($sessions) >= TRACK_MAX_CONCURRENT) {
        debugTrack('startTrackingFromRequest: limit reached chat=' . $chatId . ' current=' . count($sessions));
        $oldestToken = null;
        $oldestSession = null;
        foreach ($sessions as $candidateToken => $candidateSession) {
            $startedAt = (int)($candidateSession['started_at'] ?? PHP_INT_MAX);
            if ($oldestSession === null || $startedAt < (int)($oldestSession['started_at'] ?? PHP_INT_MAX)) {
                $oldestToken = $candidateToken;
                $oldestSession = $candidateSession;
            }
        }

        if ($oldestToken !== null && $oldestSession !== null) {
            finalizeTracking($token, $oldestSession, 'Maksimum takip limitine ulaşıldı; bu takip sonlandırıldı.');
            clearTrackingState($chatId, $oldestToken);
            debugTrack('startTrackingFromRequest: evicted token=' . $oldestToken);
        }
    }

    $sessions = loadTrackingState($chatId);

    $messageText = formatTrackingStartMessage($request);
    $response = sendTelegramMessage($token, $chatId, $messageText, [
        'parse_mode' => 'HTML',
        'reply_markup' => buildTrackingStopKeyboard($trackingToken),
        'disable_web_page_preview' => true,
    ]);

    $messageId = $response['result']['message_id'] ?? null;
    if ($messageId === null) {
        return null;
    }

    $sessions[$trackingToken] = [
        'token'          => $trackingToken,
        'chat_id'        => $chatId,
        'message_id'     => $messageId,
        'hat_code'       => $request['hat_code'] ?? '',
        'hat_display'    => $request['hat_display'] ?? '',
        'hat_name'       => $request['hat_name'] ?? '',
        'stop_id'        => $request['stop_id'] ?? '',
        'stop_name'      => $request['stop_name'] ?? '',
        'dest_stop_name' => $request['dest_stop_name'] ?? '',
        'started_at'     => time(),
        'status'         => 'running',
    ];

    saveTrackingState($chatId, $sessions);
    clearTrackingRequest($trackingToken);

    if (spawnTrackingWorker($chatId, $trackingToken)) {
        debugTrack('startTrackingFromRequest: spawned worker token=' . $trackingToken);
        return null;
    }

    debugTrack('startTrackingFromRequest: fallback inline token=' . $trackingToken);
    return static function () use ($token, $chatId, $trackingToken): void {
        debugTrack('Inline loop starting token=' . $trackingToken);
        runTrackingLoop($token, $chatId, $trackingToken);
    };
}

function buildTrackingStopKeyboard(string $trackingToken): array
{
    return [
        'inline_keyboard' => [
            [
                [
                    'text'          => '✋ Takibi bırak',
                    'callback_data' => 'track:stop|' . $trackingToken,
                ],
            ],
        ],
    ];
}

function formatTrackingStartMessage(array $request): string
{
    $hatLabel = $request['hat_display'] ?? $request['hat_code'] ?? 'Bilinmiyor';
    $lines = [
        '👀 <b>' . h($hatLabel) . '</b> hattı için takip başlatıldı.',
    ];

    $boarding = formatStopLine('🚏 Biniş', $request['stop_id'] ?? '', $request['stop_name'] ?? '');
    if ($boarding !== '') {
        $lines[] = $boarding;
    }

    if (!empty($request['minutes'])) {
        $lines[] = '⏱️ İlk tahmin: ' . h((string)$request['minutes']) . ' dk';
    }

    $lines[] = 'Takip otomatik olarak her 30 saniyede bir güncellenecek.';

    return implode("\n", array_filter($lines, static fn($line) => $line !== ''));
}

function runTrackingLoop(string $token, $chatId, string $trackingToken, bool $isWorker = false): void
{
    if (!$isWorker) {
        finishWebhookResponse();
    }
    ignore_user_abort(true);
    set_time_limit(0);

    $maxIterations = 40; // ~20 dakika
    $iteration = 0;
    debugTrack('runTrackingLoop[' . $trackingToken . ']: start worker=' . ($isWorker ? 'yes' : 'no'));

    while (true) {
        $sessions = loadTrackingState($chatId);
        $state = $sessions[$trackingToken] ?? null;
        if (!$state) {
            debugTrack('runTrackingLoop[' . $trackingToken . ']: session missing, breaking');
            break;
        }

        $stopInfo = fetchStopInfo($state['stop_id'] ?? '');
        if (!$stopInfo['ok']) {
            debugTrack('runTrackingLoop[' . $trackingToken . ']: fetchStopInfo failed: ' . ($stopInfo['error'] ?? 'unknown'));
            finalizeTracking($token, $state, 'Durak servisine ulaşılamadı. Takip sonlandırıldı.');
            clearTrackingState($chatId, $trackingToken);
            break;
        }

        $hatInfo = findHatInfoForCode($stopInfo['data'], $state['hat_code'] ?? '');
        if (!$hatInfo) {
            debugTrack('runTrackingLoop[' . $trackingToken . ']: hat info missing for code ' . ($state['hat_code'] ?? ''));
            finalizeTracking($token, $state, 'Otobüs durağı geçti veya artık görünmüyor. Takip tamamlandı.');
            clearTrackingState($chatId, $trackingToken);
            break;
        }

        $minutes = extractMinutesFromHat($hatInfo);
        $status = extractVehicleStatus($hatInfo);
        debugTrack('runTrackingLoop[' . $trackingToken . ']: minutes=' . var_export($minutes, true) . ' status=' . var_export($status, true));
        $text = formatTrackingStatusMessage($state, $minutes, $status);

        editMessageText($token, $chatId, $state['message_id'], $text, [
            'parse_mode' => 'HTML',
            'reply_markup' => buildTrackingStopKeyboard($trackingToken),
            'disable_web_page_preview' => true,
        ]);

        if (strtoupper((string)$status) === 'YOK') {
            debugTrack('runTrackingLoop[' . $trackingToken . ']: status YOK, finalizing');
            finalizeTracking($token, $state, 'Otobüs durağı geçti. Takip tamamlandı.');
            clearTrackingState($chatId, $trackingToken);
            break;
        }

        if ($minutes !== null && $minutes <= 0) {
            debugTrack('runTrackingLoop[' . $trackingToken . ']: minutes <= 0, finalizing');
            finalizeTracking($token, $state, 'Otobüs durağa ulaştı gibi görünüyor. Takip sonlandırıldı.');
            clearTrackingState($chatId, $trackingToken);
            break;
        }

        $iteration++;
        if ($iteration >= $maxIterations) {
            debugTrack('runTrackingLoop[' . $trackingToken . ']: max iterations reached');
            finalizeTracking($token, $state, 'Takip 20 dakika sonunda otomatik sonlandırıldı.');
            clearTrackingState($chatId, $trackingToken);
            break;
        }

        debugTrack('runTrackingLoop[' . $trackingToken . ']: sleeping 30s before next iteration');
        sleep(30);
    }

    if ($isWorker) {
        releaseTrackingWorker($trackingToken);
    }
    debugTrack('runTrackingLoop[' . $trackingToken . ']: loop ended');
}

function formatTrackingStatusMessage(array $state, ?int $minutes, ?string $status): string
{
    $hatLabel = $state['hat_display'] ?: ($state['hat_code'] ?? 'Bilinmiyor');
    $lines = [];
    $lines[] = '👀 <b>' . h($hatLabel) . '</b> hattı takibi';

    $boarding = formatStopLine('🚏 Durak', $state['stop_id'] ?? '', $state['stop_name'] ?? '');
    if ($boarding !== '') {
        $lines[] = $boarding;
    }

    if (!empty($state['dest_stop_name'])) {
        $lines[] = '🎯 İniş: ' . h($state['dest_stop_name']);
    }

    if ($minutes !== null) {
        $lines[] = '⏱️ Kalan süre: <b>' . h((string)$minutes) . ' dk</b>';
    }

    $upperStatus = strtoupper((string)$status);
    if ($upperStatus === 'VAR') {
        $lines[] = '🚦 Araç durakta/rota üzerinde görünüyor.';
    } elseif ($upperStatus) {
        $lines[] = '🚦 Durum: ' . h($upperStatus);
    }

    $lines[] = '🕒 Güncelleme: ' . h(date('H:i:s'));

    return implode("\n", array_filter($lines, static fn($line) => $line !== ''));
}

function finalizeTracking(string $token, array $state, string $message): void
{
    if (empty($state['message_id']) || empty($state['chat_id'])) {
        return;
    }

    editMessageText($token, $state['chat_id'], $state['message_id'], h($message), [
        'parse_mode' => 'HTML',
        'reply_markup' => ['inline_keyboard' => []],
        'disable_web_page_preview' => true,
    ]);
}

function parseRouteAlternative(array $route): ?array
{
    logRouteSample($route);
    $hatDisplay = getPipeSegment((string)($route['hatNo'] ?? ''), 1);
    if ($hatDisplay === null) {
        $hatDisplay = trim((string)($route['hatNo'] ?? ''));
    }

    $hatName = getPipeSegment((string)($route['hatAdi'] ?? ''), 1);
    $rawStart = (string)($route['baslamaDurak'] ?? '');
    $stopId = getPipeSegment($rawStart, 1);
    $startStopName = getPipeSegment((string)($route['baslamaDurakAd'] ?? ''), 1);
    $rawDest = (string)($route['bitisDurak'] ?? '');
    $destStopId = getPipeSegment($rawDest, 1);
    $endStopName = getPipeSegment((string)($route['bitisDurakAd'] ?? ''), 1);

    return [
        'cozum'            => (string)($route['cozum'] ?? ''),
        'hat_display'      => $hatDisplay,
        'hat_name'         => $hatName ? trim($hatName) : '',
        'hat_code'         => $hatDisplay ? trim($hatDisplay) : trim((string)($route['hatNo'] ?? '')),
        'stop_id'          => $stopId ? trim($stopId) : '',
        'start_stop_name'  => $startStopName ? trim($startStopName) : '',
        'end_stop_name'    => $endStopName ? trim($endStopName) : '',
        'stop_coords'      => extractStopCoordinates($rawDest, 'before'),
        'dest_stop_id'     => $destStopId ? trim($destStopId) : '',
        'dest_stop_name'   => $endStopName ? trim($endStopName) : '',
        'dest_coords'      => extractStopCoordinates($rawStart, 'after'),
        'raw'              => $route,
    ];
}

function logRouteSample(array $route): void
{
    static $logged = false;
    if ($logged) {
        return;
    }

    $path = __DIR__ . '/route_sample.json';
    $json = json_encode($route, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }

    @file_put_contents($path, $json);
    $logged = true;
}

function logRouteCoordinateSample(array $parsedRoute, array $hatMeta, array $rawRoute): void
{
    ensureLogDirectory();
    $path = getLogDirectory() . '/route_coordinates.jsonl';

    $entry = [
        'timestamp'         => date('c'),
        'cozum'             => $parsedRoute['cozum'] ?? null,
        'hat_code'          => $parsedRoute['hat_code'] ?? null,
        'hat_display'       => $parsedRoute['hat_display'] ?? null,
        'hat_name'          => $parsedRoute['hat_name'] ?? null,
        'stop_id'           => $parsedRoute['stop_id'] ?? null,
        'start_stop_name'   => $parsedRoute['start_stop_name'] ?? null,
        'stop_coords'       => $parsedRoute['stop_coords'] ?? null,
        'dest_stop_id'      => $parsedRoute['dest_stop_id'] ?? null,
        'dest_stop_name'    => $parsedRoute['dest_stop_name'] ?? null,
        'dest_coords'       => $parsedRoute['dest_coords'] ?? null,
        'hat_minutes'       => $hatMeta['minutes'] ?? null,
        'hat_status'        => $hatMeta['status'] ?? null,
        'raw_baslamaDurak'  => $rawRoute['baslamaDurak'] ?? null,
        'raw_baslamaDurakAd'=> $rawRoute['baslamaDurakAd'] ?? null,
        'raw_bitisDurak'    => $rawRoute['bitisDurak'] ?? null,
        'raw_bitisDurakAd'  => $rawRoute['bitisDurakAd'] ?? null,
    ];

    $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        debugTrack('logRouteCoordinateSample: json_encode failed: ' . json_last_error_msg());
        return;
    }

    if (@file_put_contents($path, $json . PHP_EOL, FILE_APPEND) === false) {
        debugTrack('logRouteCoordinateSample: file_put_contents failed for ' . $path);
    } else {
        debugTrack('logRouteCoordinateSample: wrote entry to ' . $path);
    }
}

function logRoutePlanningDebug($chatId, array $state, array $routeResult): void
{
    ensureLogDirectory();
    $path = getLogDirectory() . '/route_debug.jsonl';

    $rawPayload = $routeResult['raw'] ?? null;
    if (is_string($rawPayload) && strlen($rawPayload) > 60000) {
        $rawPayload = substr($rawPayload, 0, 60000) . '...[truncated]';
    }

    $entry = [
        'timestamp'     => date('c'),
        'chat_id'       => (string)$chatId,
        'state_step'    => $state['step'] ?? null,
        'origin'        => sanitizeLocationForLog($state['origin'] ?? null),
        'destination'   => sanitizeLocationForLog($state['destination'] ?? null),
        'route_ok'      => $routeResult['ok'] ?? null,
        'route_error'   => $routeResult['error'] ?? null,
        'routes_count'  => isset($routeResult['routes']) && is_array($routeResult['routes']) ? count($routeResult['routes']) : null,
        'route_raw'     => $rawPayload,
        'route_routes'  => $routeResult['routes'] ?? null,
    ];

    $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        debugTrack('logRoutePlanningDebug: json_encode failed: ' . json_last_error_msg());
        return;
    }

    if (@file_put_contents($path, $json . PHP_EOL, FILE_APPEND) === false) {
        debugTrack('logRoutePlanningDebug: file_put_contents failed for ' . $path);
    } else {
        debugTrack('logRoutePlanningDebug: wrote entry to ' . $path);
    }
}

function sanitizeLocationForLog($location): ?array
{
    if (!is_array($location)) {
        return null;
    }

    $lat = isset($location['latitude']) ? (float)$location['latitude'] : null;
    $lng = isset($location['longitude']) ? (float)$location['longitude'] : null;
    if ($lat === null || $lng === null) {
        return null;
    }

    return [
        'latitude'  => $lat,
        'longitude' => $lng,
    ];
}

function fetchHatEtaForRoute(array $route, array &$stopCache): array
{
    $stopId = $route['stop_id'] ?? '';
    $hatCode = $route['hat_code'] ?? '';

    if ($stopId === '') {
        return ['error' => 'Başlangıç durağı tespit edilemedi.'];
    }
    if ($hatCode === '') {
        return ['error' => 'Hat numarası tespit edilemedi.'];
    }

    if (!isset($stopCache[$stopId])) {
        $stopCache[$stopId] = fetchStopInfo($stopId);
    }

    $info = $stopCache[$stopId];
    if (!$info['ok']) {
        return ['error' => $info['error'] ?? 'Durak servisi hatası.'];
    }

    if (!isset($info['data'])) {
        return ['error' => 'Durak servisi yanıtı çözümlenemedi.'];
    }

    $hatInfo = findHatInfoForCode($info['data'], $hatCode);
    if ($hatInfo) {
        $minutes = extractMinutesFromHat($hatInfo);
        $status = extractVehicleStatus($hatInfo);
        return [
            'text'    => formatHatInfo($hatInfo),
            'minutes' => $minutes,
            'status'  => $status,
            'raw'     => $hatInfo,
        ];
    }

    $available = listAvailableHats($info['data']);
    if ($available) {
        return ['error' => 'İlgili hat bulunamadı. (Durak hatları: ' . implode(', ', array_slice($available, 0, 10)) . ')'];
    }

    return ['error' => 'Durak yanıtında hat bilgisi bulunamadı.'];
}

function fetchRouteSuggestion(array $origin, array $destination): array
{
    $originLat = $origin['latitude'] ?? null;
    $originLng = $origin['longitude'] ?? null;
    $destLat = $destination['latitude'] ?? null;
    $destLng = $destination['longitude'] ?? null;

    if ($originLat === null || $originLng === null) {
        return ['ok' => false, 'error' => 'Başlangıç konumu eksik.'];
    }
    if ($destLat === null || $destLng === null) {
        return ['ok' => false, 'error' => 'Varış konumu eksik.'];
    }

    $url = 'https://ulasim.mersin.bel.tr/nasilgiderim/nasilgiderim.php';
    $postData = http_build_query([
        'baslangic' => sprintf('%.12f,%.12f', $originLat, $originLng),
        'bitis'     => sprintf('%.12f,%.12f', $destLat, $destLng),
    ], '', '&', PHP_QUERY_RFC3986);

    $headers = [
        'Host: ulasim.mersin.bel.tr',
        'User-Agent: Mozilla/5.0 (TelegramBot)',
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://ulasim.mersin.bel.tr',
        'Referer: https://ulasim.mersin.bel.tr/nasilgiderim/',
    ];

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL PHP eklentisi bulunamadı.'];
    }

    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL başlatılamadı.'];
    }

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '', // sunucunun gönderdiği sıkıştırmayı otomatik çöz
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 15,
    ];

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch) ?: 'cURL bilinmeyen hata';
        curl_close($ch);
        return ['ok' => false, 'error' => $error];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        return [
            'ok'    => false,
            'error' => 'HTTP durum kodu ' . $status,
            'raw'   => $response,
        ];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return ['ok' => true, 'routes' => $decoded, 'raw' => $response];
    }

    return ['ok' => true, 'routes' => [], 'raw' => $response];
}

function fetchStopInfo(string $stopId): array
{
    $url = 'https://ulasim.mersin.bel.tr/ajax/bilgi.php';
    $postData = http_build_query([
        'durak_no' => $stopId,
        'tipi'     => 'durakhatbilgisi',
    ], '', '&', PHP_QUERY_RFC3986);

    $headers = [
        'Host: ulasim.mersin.bel.tr',
        'User-Agent: Mozilla/5.0 (TelegramBot)',
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://ulasim.mersin.bel.tr',
        'Referer: https://ulasim.mersin.bel.tr/durakdetay.php',
    ];

    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL başlatılamadı.'];
    }

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 15,
    ];

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch) ?: 'cURL bilinmeyen hata';
        curl_close($ch);
        return ['ok' => false, 'error' => $error];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        return [
            'ok'    => false,
            'error' => 'Durak servisi HTTP durum kodu ' . $status,
            'raw'   => $response,
        ];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return ['ok' => true, 'data' => $decoded, 'raw' => $response];
    }

    return ['ok' => false, 'error' => 'Durak servisi JSON verisi çözülemedi.', 'raw' => $response];
}

function findHatInfoForCode($data, string $hatCode)
{
    if (!is_array($data) || $hatCode === '') {
        return null;
    }

    $targets = buildHatTokens($hatCode);
    if (!$targets) {
        return null;
    }

    $queue = [$data];
    while ($queue) {
        $current = array_shift($queue);
        if (!is_array($current)) {
            continue;
        }

        $hatValue = null;
        foreach (['hatNo', 'hat_no', 'hat', 'line', 'hatKod', 'hat_kod'] as $hatKey) {
            if (isset($current[$hatKey])) {
                $hatValue = extractScalar($current[$hatKey]);
                if ($hatValue !== '') {
                    break;
                }
            }
        }

        if ($hatValue !== null) {
            $normalized = normalizeHatKey($hatValue);
            $digits = normalizeHatDigits($hatValue);
            if (in_array($normalized, $targets, true) || ($digits !== '' && in_array($digits, $targets, true))) {
                return $current;
            }
        }

        foreach ($current as $value) {
            if (is_array($value) || $value instanceof Traversable) {
                $queue[] = (array)$value;
            }
        }
    }

    return null;
}

function formatHatInfo(array $hatInfo): string
{
    $parts = [];

    $hatNo = extractScalar($hatInfo['hat_no'] ?? ($hatInfo['hatNo'] ?? null));
    if ($hatNo !== '') {
        $parts[] = 'Hat ' . $hatNo;
    }

    $minutes = extractMinutesFromHat($hatInfo);
    if ($minutes !== null) {
        $parts[] = 'Kalan süre: ' . $minutes . ' dk';
    }

    $status = extractVehicleStatus($hatInfo);
    if ($status === 'VAR') {
        $parts[] = 'Araç şu an görünüyor';
    } elseif ($status === 'YOK') {
        $parts[] = 'Araç henüz görünmüyor';
    } elseif ($status !== null) {
        $parts[] = 'Durum: ' . $status;
    }

    foreach (['saat', 'planlananSaat', 'kalkisSaati', 'planlanan_saat', 'time'] as $timeKey) {
        if (isset($hatInfo[$timeKey])) {
            $value = extractScalar($hatInfo[$timeKey]);
            if ($value !== '') {
                $parts[] = 'Planlanan saat: ' . $value;
                break;
            }
        }
    }

    foreach (['aciklama', 'message', 'detay'] as $infoKey) {
        if (isset($hatInfo[$infoKey])) {
            $value = extractScalar($hatInfo[$infoKey]);
            if ($value !== '') {
                $parts[] = 'Not: ' . $value;
                break;
            }
        }
    }

    if (!$parts) {
        $json = json_encode($hatInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $json !== false ? $json : 'Hat bilgisi bulunamadı.';
    }

    return implode(' | ', $parts);
}

function extractMinutesFromHat(array $hatInfo): ?int
{
    foreach ([
        'dakika', 'sure', 'kalanSure', 'kalan_sure', 'yaklasikDakika', 'yaklasik_dakika', 'minutes',
    ] as $minuteKey) {
        if (!isset($hatInfo[$minuteKey])) {
            continue;
        }

        $value = extractScalar($hatInfo[$minuteKey]);
        if ($value === '') {
            continue;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        if (preg_match('/-?\d+/', $value, $matches)) {
            return (int)$matches[0];
        }
    }

    return null;
}

function extractVehicleStatus(array $hatInfo): ?string
{
    foreach (['arac_varmi', 'aracVarMi', 'vehicle', 'durum', 'status'] as $statusKey) {
        if (!isset($hatInfo[$statusKey])) {
            continue;
        }

        $value = strtoupper(extractScalar($hatInfo[$statusKey]));
        if ($value === '') {
            continue;
        }

        if (in_array($value, ['VAR', 'YOK'], true)) {
            return $value;
        }

        return $value;
    }

    return null;
}

function listAvailableHats($data): array
{
    if (!is_array($data)) {
        return [];
    }

    $found = [];
    $queue = [$data];

    while ($queue) {
        $current = array_shift($queue);
        if (!is_array($current)) {
            continue;
        }

        foreach (['hatNo', 'hat_no', 'hat', 'line', 'hatKod', 'hat_kod'] as $hatKey) {
            if (isset($current[$hatKey])) {
                $value = trim(extractScalar($current[$hatKey]));
                if ($value !== '') {
                    $found[$value] = true;
                }
            }
        }

        foreach ($current as $value) {
            if (is_array($value) || $value instanceof Traversable) {
                $queue[] = (array)$value;
            }
        }
    }

    return array_keys($found);
}

function buildHatTokens(string $hatCode): array
{
    $hatCode = trim($hatCode);
    if ($hatCode === '') {
        return [];
    }

    $tokens = [];
    $tokens[] = normalizeHatKey($hatCode);
    $tokens[] = normalizeHatKey(str_replace(['-', ' '], '', $hatCode));
    $digits = normalizeHatDigits($hatCode);
    if ($digits !== '') {
        $tokens[] = $digits;
    }

    return array_values(array_unique(array_filter($tokens, static fn($t) => $t !== '')));
}

function normalizeHatKey(string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', $value));
}

function normalizeHatDigits(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    return $digits === null ? '' : $digits;
}

function getPipeSegment(string $value, int $index): ?string
{
    if ($value === '') {
        return null;
    }

    $parts = explode('|', $value);
    return isset($parts[$index]) && $parts[$index] !== '' ? trim((string)$parts[$index]) : null;
}

function extractStopCoordinates(string $raw, string $preference = 'auto'): ?array
{
    if ($raw === '') {
        return null;
    }

    $segments = array_map('trim', explode('|', $raw));
    if (!$segments) {
        return null;
    }

    $coordinateSegments = [];
    foreach ($segments as $index => $segment) {
        if ($segment === '' || strpos($segment, ';') === false) {
            continue;
        }

        [$lat, $lng] = array_map('trim', explode(';', $segment, 2) + ['', '']);
        if (!is_numeric($lat) || !is_numeric($lng)) {
            continue;
        }

        $coordinateSegments[$index] = [(float)$lat, (float)$lng];
    }

    if (!$coordinateSegments) {
        return null;
    }

    $stopIndex = null;
    foreach ($segments as $index => $segment) {
        if ($segment === '' || strpos($segment, ';') !== false) {
            continue;
        }

        if (preg_match('/^\d+$/', $segment)) {
            $stopIndex = $index;
            break;
        }
    }

    if ($stopIndex !== null) {
        $offsets = $preference === 'before'
            ? [-1, 1]
            : ($preference === 'after' ? [1, -1] : [1, -1, -2, 2]);

        foreach ($offsets as $delta) {
            $candidate = $stopIndex + $delta;
            if (isset($coordinateSegments[$candidate])) {
                [$lat, $lng] = $coordinateSegments[$candidate];
                return ['lat' => $lat, 'lng' => $lng];
            }
        }
    }

    $first = reset($coordinateSegments);
    if (is_array($first) && count($first) === 2) {
        return ['lat' => $first[0], 'lng' => $first[1]];
    }

    return null;
}

function extractScalar($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_scalar($value)) {
        return (string)$value;
    }

    if (is_array($value)) {
        if (!$value) {
            return '';
        }

        $firstKey = array_key_first($value);
        if ($firstKey === null) {
            return '';
        }

        return extractScalar($value[$firstKey]);
    }

    if ($value instanceof Traversable) {
        foreach ($value as $item) {
            return extractScalar($item);
        }
        return '';
    }

    if (is_object($value)) {
        return extractScalar(get_object_vars($value));
    }

    return '';
}

function logUpdate(array $update): void
{
    $logPath = __DIR__ . '/latest_update.json';
    $json = json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    @file_put_contents($logPath, $json . PHP_EOL, LOCK_EX);
}

function loadChatState($chatId): array
{
    $path = getStateFilePath($chatId);
    if (!is_readable($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveChatState($chatId, array $state): void
{
    ensureStateDirectory();
    $path = getStateFilePath($chatId);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    @file_put_contents($path, $json, LOCK_EX);
}

function clearChatState($chatId): void
{
    $path = getStateFilePath($chatId);
    if (is_file($path)) {
        @unlink($path);
    }
}

function getStateFilePath($chatId): string
{
    return getStateDirectory() . '/state_' . $chatId . '.json';
}

function ensureStateDirectory(): void
{
    $dir = getStateDirectory();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function getStateDirectory(): string
{
    return __DIR__ . '/state';
}

function getTrackingStateDirectory(): string
{
    return getStateDirectory() . '/track';
}

function getLogDirectory(): string
{
    return getStateDirectory() . '/logs';
}

function ensureLogDirectory(): void
{
    ensureStateDirectory();
    $dir = getLogDirectory();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function saveTrackingState($chatId, array $sessions): void
{
    ensureStateDirectory();
    $dir = getTrackingStateDirectory();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return;
    }

    $path = $dir . '/state_' . $chatId . '.json';

    if (!$sessions) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    $normalized = [];
    foreach ($sessions as $token => $session) {
        if (!is_string($token) || $token === '' || !is_array($session)) {
            continue;
        }
        $session['chat_id'] = $chatId;
        $normalized[$token] = $session;
    }

    if (!$normalized) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    $payload = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }

    @file_put_contents($path, $payload);
}

function loadTrackingState($chatId): array
{
    $path = getTrackingStateDirectory() . '/state_' . $chatId . '.json';
    if (!is_readable($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    if (isset($data['sessions']) && is_array($data['sessions'])) {
        $data = $data['sessions'];
    }

    if (isset($data['token']) && isset($data['chat_id'])) {
        $token = (string)$data['token'];
        return $token !== '' ? [$token => $data] : [];
    }

    $sessions = [];
    foreach ($data as $token => $session) {
        if (!is_string($token) || $token === '' || !is_array($session)) {
            continue;
        }
        $session['chat_id'] = $chatId;
        $sessions[$token] = $session;
    }

    return $sessions;
}

function clearTrackingState($chatId, ?string $token = null): void
{
    $path = getTrackingStateDirectory() . '/state_' . $chatId . '.json';
    if ($token === null) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    $token = trim($token);
    if ($token === '') {
        return;
    }

    $sessions = loadTrackingState($chatId);
    if (!$sessions) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    if (!isset($sessions[$token])) {
        return;
    }

    unset($sessions[$token]);
    saveTrackingState($chatId, $sessions);
}

function getTrackingRequestDirectory(): string
{
    return getStateDirectory() . '/track_requests';
}

function registerTrackingRequest(array $route, array $hatMeta): ?string
{
    $stopId = $route['stop_id'] ?? '';
    $hatCode = $route['hat_code'] ?? '';
    if ($stopId === '' || $hatCode === '') {
        return null;
    }

    ensureStateDirectory();
    $dir = getTrackingRequestDirectory();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        debugTrack('registerTrackingRequest: mkdir failed for ' . $dir);
        return null;
    }

    purgeOldTrackingRequests();

    $token = generateTrackingToken($dir);
    $payload = [
        'hat_code'       => $hatCode,
        'hat_display'    => $route['hat_display'] ?? '',
        'hat_name'       => $route['hat_name'] ?? '',
        'stop_id'        => $stopId,
        'stop_name'      => $route['start_stop_name'] ?? '',
        'stop_coords'    => $route['stop_coords'] ?? null,
        'dest_stop_id'   => $route['dest_stop_id'] ?? '',
        'dest_stop_name' => $route['dest_stop_name'] ?? '',
        'dest_coords'    => $route['dest_coords'] ?? null,
        'minutes'        => $hatMeta['minutes'] ?? null,
        'status'         => $hatMeta['status'] ?? null,
        'created_at'     => time(),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        debugTrack('registerTrackingRequest: json_encode failed: ' . json_last_error_msg());
        return null;
    }

    if (@file_put_contents($dir . '/' . $token . '.json', $json) === false) {
        debugTrack('registerTrackingRequest: file_put_contents failed in ' . $dir);
        return null;
    }

    return $token;
}

function debugTrack(string $message): void
{
    static $path = null;
    if ($path === null) {
        ensureStateDirectory();
        $path = getStateDirectory() . '/track_debug.log';
    }

    $timestamp = date('c');
    @file_put_contents($path, "[$timestamp] $message\n", FILE_APPEND);
}

function loadTrackingRequest(string $token): ?array
{
    $path = getTrackingRequestDirectory() . '/' . $token . '.json';
    if (!is_readable($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }

    $createdAt = isset($data['created_at']) && is_numeric($data['created_at'])
        ? (int)$data['created_at']
        : null;

    if ($createdAt !== null && time() - $createdAt > TRACK_REQUEST_TTL) {
        clearTrackingRequest($token);
        return null;
    }

    return $data;
}

function clearTrackingRequest(string $token): void
{
    $path = getTrackingRequestDirectory() . '/' . $token . '.json';
    if (is_file($path)) {
        @unlink($path);
    }
}

function purgeOldTrackingRequests(int $ttlSeconds = TRACK_REQUEST_TTL): void
{
    $dir = getTrackingRequestDirectory();
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*.json') as $file) {
        if (!is_file($file)) {
            continue;
        }

        $fileTtl = $ttlSeconds;
        $createdAt = null;
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (isset($decoded['created_at']) && is_numeric($decoded['created_at'])) {
                $createdAt = (int)$decoded['created_at'];
            }
        }

        if ($createdAt !== null) {
            if (time() - $createdAt > $fileTtl) {
                @unlink($file);
            }
            continue;
        }

        if (time() - @filemtime($file) > $fileTtl) {
            @unlink($file);
        }
    }
}

function generateTrackingToken(string $dir): string
{
    do {
        try {
            $token = bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            $token = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
        }
    } while (file_exists($dir . '/' . $token . '.json'));

    return $token;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function finishWebhookResponse(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    static $finished = false;
    if ($finished) {
        return;
    }
    $finished = true;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    if (!headers_sent()) {
        header('Connection: close');
        header('Content-Encoding: none');
    }

    while (ob_get_level() > 0) {
        if (!@ob_end_flush()) {
            break;
        }
    }

    flush();
}

function spawnTrackingWorker($chatId, string $trackingToken): bool
{
    ensureStateDirectory();
    $workersDir = getTrackingWorkerDirectory();
    if (!is_dir($workersDir)) {
        @mkdir($workersDir, 0777, true);
    }

    $lockPath = getTrackingWorkerLockPath($trackingToken);
    if (is_file($lockPath)) {
        $lockAge = time() - (int)@filemtime($lockPath);
        if ($lockAge < 5) {
            debugTrack('spawnTrackingWorker: worker already active for ' . $trackingToken);
            return true;
        }
    }

    if (@file_put_contents($lockPath, (string)time()) === false) {
        debugTrack('spawnTrackingWorker: unable to create lock file for ' . $trackingToken);
        return false;
    }

    $scheme = 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($port === 443);
    if ($isHttps) {
        $scheme = 'https';
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/telegram_bot.php';
    $query = http_build_query([
        'track_worker' => 1,
        'chat_id'      => $chatId,
        'token'        => $trackingToken,
    ], '', '&', PHP_QUERY_RFC3986);

    $url = $scheme . '://' . $host . $script . '?' . $query;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER     => false,
            CURLOPT_HEADER             => false,
            CURLOPT_NOBODY             => false,
            CURLOPT_TIMEOUT_MS         => 800,
            CURLOPT_CONNECTTIMEOUT_MS  => 300,
            CURLOPT_NOSIGNAL           => true,
            CURLOPT_SSL_VERIFYPEER     => false,
            CURLOPT_SSL_VERIFYHOST     => 0,
        ]);

        $result = curl_exec($ch);
        $curlErr = curl_errno($ch);
        curl_close($ch);

        if ($result === false && $curlErr !== CURLE_OPERATION_TIMEDOUT) {
            debugTrack('spawnTrackingWorker: curl failed (errno ' . $curlErr . ') for ' . $trackingToken);
            releaseTrackingWorker($trackingToken);
            return false;
        }

        debugTrack('spawnTrackingWorker: HTTP worker triggered for ' . $trackingToken);
        return true;
    }

    $hostWithoutPort = $host;
    if (strpos($host, ':') !== false) {
        [$hostWithoutPort, $explicitPort] = explode(':', $host, 2);
        if (is_numeric($explicitPort)) {
            $port = (int)$explicitPort;
        }
    }

    $transport = $scheme === 'https' ? 'ssl://' : '';
    $fp = @stream_socket_client($transport . $hostWithoutPort . ':' . $port, $errno, $errstr, 1, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);

    if (!$fp) {
        debugTrack('spawnTrackingWorker: socket connect failed (' . $errno . ') ' . $errstr . ' for ' . $trackingToken);
        releaseTrackingWorker($trackingToken);
        return false;
    }

    $request = "GET {$script}?{$query} HTTP/1.1\r\n" .
        'Host: ' . $host . "\r\n" .
        "Connection: Close\r\n\r\n";

    fwrite($fp, $request);
    fclose($fp);

    debugTrack('spawnTrackingWorker: socket worker triggered for ' . $trackingToken);
    return true;
}

function getTrackingWorkerDirectory(): string
{
    return getStateDirectory() . '/workers';
}

function getTrackingWorkerLockPath(string $trackingToken): string
{
    return getTrackingWorkerDirectory() . '/worker_' . $trackingToken . '.lock';
}

function releaseTrackingWorker(string $trackingToken): void
{
    $lockPath = getTrackingWorkerLockPath($trackingToken);
    if (is_file($lockPath)) {
        @unlink($lockPath);
        debugTrack('releaseTrackingWorker: lock cleared for ' . $trackingToken);
    }
}
