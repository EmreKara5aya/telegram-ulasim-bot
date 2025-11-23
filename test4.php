<?php
$url = "https://ulasim.mersin.bel.tr/ajax/bilgi.php";

$postData = "hat_no=156-G&tipi=tarifeler";

$headers = [
    "Host: ulasim.mersin.bel.tr",
    "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:143.0) Gecko/20100101 Firefox/143.0",
    "Accept: application/json, text/javascript, */*; q=0.01",
    "Accept-Language: tr",
    "Accept-Encoding: gzip, deflate, br, zstd",
    "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
    "X-Requested-With: XMLHttpRequest",
    "Origin: https://ulasim.mersin.bel.tr",
    "DNT: 1",
    "Sec-GPC: 1",
    "Connection: keep-alive",
    "Referer: https://ulasim.mersin.bel.tr/tarifeler.php",
    "Cookie: PHPSESSID=iaesh9561e4ff10dbdk6aqt139",
    "Sec-Fetch-Dest: empty",
    "Sec-Fetch-Mode: cors",
    "Sec-Fetch-Site: same-origin"
];

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_ENCODING => "gzip,deflate,br", // sıkıştırılmış cevabı çöz
    CURLOPT_SSL_VERIFYPEER => false,       // test için SSL doğrulamasını kapat
    CURLOPT_SSL_VERIFYHOST => false
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "cURL Hatası: " . curl_error($ch);
} else {
    // JSON dönerse array'e çevirip yazdıralım
    $data = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "<pre>";
        print_r($data);
        echo "</pre>";
    } else {
        echo $response; // JSON değilse ham çıktıyı bas
    }
}

curl_close($ch);