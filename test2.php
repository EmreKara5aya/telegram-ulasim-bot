<?php
$url = "https://ulasim.mersin.bel.tr/ajax/bilgi.php";

$postData = "durak_no=58068&tipi=durakhatbilgisi";

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
    "Referer: https://ulasim.mersin.bel.tr/durakdetay.php",
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
    CURLOPT_ENCODING => "gzip,deflate,br", // sıkıştırılmış cevabı çözmek için
    CURLOPT_SSL_VERIFYPEER => false,       // test için SSL doğrulamasını kapat
    CURLOPT_SSL_VERIFYHOST => false
]);

$response = curl_exec($ch);

/*if (curl_errno($ch)) {
    echo "cURL Hatası: " . curl_error($ch);
} else {
    echo $response;
}*/

curl_close($ch);
//$veri = json_decode($response, true);
print_r($response);