<?php
declare(strict_types=1);

$endpoint = 'https://ulasim.mersin.bel.tr/ajax/bilgi.php';
$payload = http_build_query([
    'aranan' => 'TUM',
    'tipi'   => 'hatbilgisi',
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

$ch = curl_init($endpoint);

if ($ch === false) {
    logBusLineError('cURL başlatılamadı.');
    emitFailureResponse('cURL başlatılamadı.');
    exit(1);
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
    $error = 'cURL hatası: ' . curl_error($ch);
    logBusLineError($error);
    curl_close($ch);
    emitFailureResponse($error);
    exit(1);
}

$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($status < 200 || $status >= 300) {
    $error = 'HTTP durum kodu uygun değil: ' . $status;
    logBusLineError($error);
    emitFailureResponse($error);
    exit(1);
}

$decoded = json_decode($response, true);
if (!is_array($decoded)) {
    $error = 'Beklenmeyen JSON formatı alındı.';
    logBusLineError($error);
    emitFailureResponse($error);
    exit(1);
}

$lines = [];
foreach ($decoded as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $hatNo  = extractFirstScalar($entry['hat_no'] ?? null);
    $hatYon = extractFirstScalar($entry['hat_yon'] ?? null);
    $hatAdi = extractFirstScalar($entry['hat_adi'] ?? null);
    $bolge  = extractFirstScalar($entry['bolge'] ?? null);

    if ($hatNo === '' || $hatYon === '') {
        continue;
    }

    $lines[] = [
        'hat_no'   => $hatNo,
        'hat_yon'  => $hatYon,
        'hat_adi'  => $hatAdi,
        'bolge'    => $bolge,
        'post'     => $hatNo . '-' . $hatYon,
    ];
}

usort($lines, static function (array $a, array $b): int {
    return strcmp($a['hat_no'] . $a['hat_yon'], $b['hat_no'] . $b['hat_yon']);
});

$output = [
    'generated_at' => date('c'),
    'count'        => count($lines),
    'lines'        => $lines,
];

$targetDir = __DIR__ . '/state';
if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
    $error = 'State dizini oluşturulamadı: ' . $targetDir;
    logBusLineError($error);
    emitFailureResponse($error);
    exit(1);
}

$target = $targetDir . '/bus_lines.json';
$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

if ($json === false) {
    $error = 'JSON encode başarısız.';
    logBusLineError($error);
    emitFailureResponse($error);
    exit(1);
}

if (file_put_contents($target, $json) === false) {
    $error = 'bus_lines.json yazılamadı.';
    logBusLineError($error);
    emitFailureResponse($error);
    exit(1);
}

emitSuccessResponse('Bus lines updated: ' . count($lines));

function extractFirstScalar($value): string
{
    if (is_array($value)) {
        $first = reset($value);
        return extractFirstScalar($first);
    }

    if ($value === null) {
        return '';
    }

    return trim((string)$value);
}

function logBusLineError(string $message): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
        return;
    }

    error_log('[update_bus_lines] ' . $message);
}

function emitFailureResponse(string $message): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => false,
            'error'   => $message,
            'time'    => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function emitSuccessResponse(string $message): void
{
    if (PHP_SAPI === 'cli') {
        echo $message . PHP_EOL;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => true,
            'message' => $message,
            'time'    => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
