<?php
// Функция для логирования
function logToFile($message, $logFile = 'address_suggestion_log.txt') {
    $logDir = __DIR__ . '/../logs'; // ../ — если файл в подкаталоге
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $logPath = $logDir . '/' . $logFile;
    $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logPath, $logEntry, FILE_APPEND);
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

// Функция для получения подсказки по адресу
function fetchSuggestedAddress($enteredAddress) {
    $token = getAccessTokenFromFile();
    if (!$token) {
        return ['status' => 'error', 'message' => 'Access token is missing'];
    }

    $url = 'https://api.sbis.ru/retail/delivery/suggested-address?enteredAddress=' . urlencode($enteredAddress);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => $url,
        CURLOPT_HEADER => 0,
        CURLOPT_HTTPHEADER => [
            'Content-Type: charset=utf-8',
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
        return ['status' => 'error', 'message' => 'Failed to fetch suggested address', 'http_code' => $httpCode];
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['status' => 'error', 'message' => 'Invalid JSON response'];
    }

    return ['status' => 'success', 'data' => $responseData];
}

// Пример использования
$address = '620027, Екатеринбург, ул Мамина-Сибиряка, 56';

$result = fetchSuggestedAddress($address);

if ($result['status'] === 'success') {
    echo "Suggested address data: ";
    print_r($result['data']);
} else {
    echo "Error: " . $result['message'] . "\n";
    if (isset($result['http_code'])) {
        echo "HTTP Code: " . $result['http_code'] . "\n";
    }
}
?>
