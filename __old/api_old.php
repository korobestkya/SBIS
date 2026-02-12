<?php

// Функция для логирования
function logToFile($message, $logFile = 'sbis_log.txt') {
    $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Функция для получения токена доступа
function getSbisAccessToken() {
    $tokenFile = 'sbis_token.txt';

    // Если файл с токеном существует, читаем его
    if (file_exists($tokenFile)) {
        $tokenData = json_decode(file_get_contents($tokenFile), true);
        if (isset($tokenData['token'], $tokenData['sid'])) {
            return $tokenData;
        }
    }

    // Если файл отсутствует или данные некорректны, получаем новый токен
    $authData = [
        'app_client_id' => '1282701763162249', // Ваш client ID
        'app_secret'    => '6UVATTLYU6XTMBJJNF3HG0TZ', // Ваш secret
        'secret_key'    => 'sMg1PVR0ptWkpW0v4v8XM3jjumNzTTsYMMqd1Sbwz4idDan2C81plOvZNaw5TyAzQeRHcyqeW8u3sqHEojkJ1ba9qeZ3Eq5f324brSdV95wM7VZHWsNzzX' // Укажите ваш сервисный ключ
    ];

    $authDataJson = json_encode($authData);

    $ch = curl_init('https://online.sbis.ru/oauth/service/');
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $authDataJson,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        logToFile("Error: Failed to get access token. Response: " . $response);
        return ['status' => 'error', 'message' => 'Failed to get access token'];
    }

    $responseData = json_decode($response, true);

    if (!isset($responseData['token']) || !isset($responseData['sid'])) {
        logToFile("Error: Invalid response from Sbis. Response: " . $response);
        return ['status' => 'error', 'message' => 'Invalid response from Sbis'];
    }

    // Сохраняем токен в файл
    $tokenData = [
        'token' => $responseData['token'],
        'sid' => $responseData['sid']
    ];
    file_put_contents($tokenFile, json_encode($tokenData));

    return $tokenData;
}

// Функция для отправки данных в СБИС
function sendOrderToSbis($orderData) {
    $tokenData = getSbisAccessToken();
    if ($tokenData['status'] === 'error') {
        logToFile("Error: Failed to get Sbis access token - " . $tokenData['message']);
        return ['status' => 'error', 'message' => 'Token retrieval failed'];
    }

    $ch = curl_init('https://api.sbis.ru/retail/order/create');
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($orderData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'X-SBISAccessToken: ' . $tokenData['token']
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logToFile("Response from Sbis (HTTP $httpCode): " . $response);

    if ($httpCode !== 200) {
        logToFile("Error: Failed to send order to Sbis. HTTP Code: $httpCode");
        return ['status' => 'error', 'message' => 'Failed to send order'];
    }

    return json_decode($response, true);
}

// Установим заголовки для обработки запроса
header('Content-Type: application/json');

// Получаем данные из запроса
$inputData = file_get_contents('php://input');

// Логируем входные данные от Tilda
logToFile("Received from Tilda: " . $inputData);

// Декодируем JSON данные
$data = json_decode($inputData, true);

// Проверяем, что это тестовый запрос
if (isset($data['test']) && $data['test'] === 'test') {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Test request received']);
    logToFile("Test request received and responded successfully.");
    exit;
}

// Проверяем, что данные были успешно декодированы
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
    logToFile("Error: Invalid JSON data");
    exit;
}

// Валидация обязательных полей
$requiredFields = ['Name', 'Mail', 'Phone', 'payment'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Missing required field: $field"]);
        logToFile("Error: Missing required field - $field");
        exit;
    }
}

// Генерация JSON адреса
$addressJSON = json_encode([
    "City" => "г. Екатеринбург",
    "Street" => "пер. Переходный",
    "HouseNum" => "11",
    "Address" => $data["payment"]["delivery_address"]
], JSON_UNESCAPED_UNICODE);

// Преобразуем данные для СБИС
$orderData = [
    "product" => "delivery",
    "pointId" => 319, // Идентификатор точки продаж (нужно получить из СБИС)
    "customer" => [
        "name" => $data["Name"],
        "phone" => preg_replace('/[^0-9]/', '', $data["Phone"]), // Очищаем номер телефона
        "email" => $data["Mail"]
    ],
    "datetime" => date('Y-m-d H:i:s'), // Текущее время
    "comment" => $data["payment"]["delivery_comment"] ?? "", // Примечание к заказу
    "priceListId" => 15, // Идентификатор прайс-листа
    "nomenclatures" => array_map(function ($product) {
        return [
            "externalId" => $product["externalid"],
            "priceListId" => 15, // Прайс-лист ID
            "count" => $product["quantity"],
            "cost" => $product["price"]
        ];
    }, $data["payment"]["products"]),
    "delivery" => [
        "addressFull" => $data["payment"]["delivery_address"],
        "addressJSON" => $addressJSON,
        "paymentType" => $data["payment"]["sys"] === "none" ? "cash" : $data["payment"]["sys"],
        "isPickup" => false
    ]
];

// Логируем данные, которые отправляем в СБИС
logToFile("Sending to Sbis: " . json_encode($orderData, JSON_UNESCAPED_UNICODE));

// Отправляем данные в СБИС
$sbisResponse = sendOrderToSbis($orderData);

// Логируем ответ от СБИС
logToFile("Response from Sbis: " . json_encode($sbisResponse, JSON_UNESCAPED_UNICODE));

// Возвращаем ответ
$response = [
    'status' => 'success',
    'message' => 'Data has been logged and sent to Sbis.',
    'sbis_response' => $sbisResponse
];

echo json_encode($response);
