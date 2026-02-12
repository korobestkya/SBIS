<?php
// Функция для логирования
function logToFile($message, $logFile = '3-products.txt') {
    $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Функция для получения токена из файла
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

// Функция для выполнения запроса о меню
function fetchMenu($pointId, $actualDate, $searchString = '', $page = 1, $pageSize = 1000) {
    $token = getAccessTokenFromFile();
    if (!$token) {
        return ['status' => 'error', 'message' => 'Access token is missing'];
    }

    $url = "https://api.sbis.ru/retail/nomenclature/list?" . http_build_query([
        'pointId' => $pointId,
        'priceListId' => 18,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => $url,
        CURLOPT_HEADER => 0,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'X-SBISAccessToken: ' . $token
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logToFile("Request URL: " . $url);
    logToFile("Response HTTP Code: " . $httpCode);
    logToFile("Response: " . $response);

    if ($httpCode !== 200) {
        return ['status' => 'error', 'message' => 'Failed to fetch menu', 'http_code' => $httpCode];
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['status' => 'error', 'message' => 'Invalid JSON response'];
    }

    return ['status' => 'success', 'data' => $responseData];
}

// Пример использования
$pointId = 319; // Идентификатор точки продаж
$actualDate = date('d.m.Y H:i:s'); // Текущее время

$result = fetchMenu($pointId, $actualDate);

if ($result['status'] === 'success') {
    echo "Menu data: ";
    print_r($result['data']);
} else {
    echo "Error: " . $result['message'] . "\n";
    if (isset($result['http_code'])) {
        echo "HTTP Code: " . $result['http_code'] . "\n";
    }
}
?>