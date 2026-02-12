<?php

// === Конфигурация ===
$pointId = 319;             // Идентификатор точки продаж
$priceListId = 18;           // ID прайс-листа (меню)
$pageSize = 1000;           // Кол-во записей на страницу
$maxPages = 10;             // Защита от зацикливания
$outputFile = 'all-products.json'; // Файл для сохранения результата
$logFile = 'sbis-log.txt';         // Файл логов

// === Логирование ===
function logToFile($message, $logFile = 'logs/2-all_position_logs.txt') {
    $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// === Получение токена из файла ===
function getAccessTokenFromFile($tokenFile = 'sbis_token.txt') {
    if (!file_exists($tokenFile)) {
        logToFile("Error: Token file not found.");
        return false;
    }

    $tokenData = json_decode(file_get_contents($tokenFile), true);
    if (!isset($tokenData['token'])) {
        logToFile("Error: Token not found in file.");
        return false;
    }

    return $tokenData['token'];
}

// === Получение одной страницы данных ===
function fetchMenuPage($token, $pointId, $priceListId, $page, $pageSize) {
    $queryParams = http_build_query([
        'pointId' => $pointId,
        'priceListId' => $priceListId,
        'page' => $page,
    ]);

    $url = "https://api.sbis.ru/retail/nomenclature/list?$queryParams";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => 0,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'X-SBISAccessToken: ' . $token
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    logToFile("Request URL (page $page): $url");
    logToFile("HTTP Code: $httpCode");
    logToFile("Raw response (page $page): $response");

    if ($response === false) {
        logToFile("CURL Error: $curlError");
        return ['status' => 'error', 'message' => 'CURL request failed'];
    }

    if ($httpCode !== 200) {
        logToFile("Error: HTTP $httpCode, response: $response");
        return ['status' => 'error', 'message' => "HTTP error $httpCode"];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logToFile("JSON decode error: " . json_last_error_msg());
        return ['status' => 'error', 'message' => 'Invalid JSON'];
    }

    return ['status' => 'success', 'data' => $data];
}


// === Основная логика ===

$token = getAccessTokenFromFile();
if (!$token) {
    die("Ошибка: не удалось получить токен доступа.\n");
}

$allData = [];
$page = 0;

do {
    $result = fetchMenuPage($token, $pointId, $priceListId, $page, $pageSize);

    if ($result['status'] !== 'success') {
        echo "Ошибка на странице $page: " . $result['message'] . "\n";
        break;
    }

    $data = $result['data'];

    if (!isset($data['nomenclatures']) || !isset($data['outcome']['hasMore'])) {
        logToFile("Warning: Некорректная структура ответа на странице $page");
        break;
    }
    $allData = array_merge($allData, $data['nomenclatures']);

    $hasMore = $data['outcome']['hasMore'];
    $page++;

    if ($page > $maxPages) {
        logToFile("Достигнут лимит в $maxPages страниц. Прерывание.");
        break;
    }

} while ($hasMore);

// === Сохраняем итоговый массив в JSON ===
file_put_contents($outputFile, json_encode($allData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Готово! Всего загружено: " . count($allData) . " позиций.\n";
echo "Сохранено в файл: $outputFile\n";

?>
