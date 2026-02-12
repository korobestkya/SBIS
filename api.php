<?php
date_default_timezone_set('Asia/Yekaterinburg');

function logToFile($message, $logFile = 'logs/1-sending_to_sbis_log.txt') {
    $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

$secrets = require __DIR__ . '/secrets.php';

// Функция для получения токена доступа
function getSbisAccessToken() {
    $tokenFile = $secrets['SBIS_TOKEN_FILE'];

    if (file_exists($tokenFile)) {
        $tokenData = json_decode(file_get_contents($tokenFile), true);
        if (isset($tokenData['token'], $tokenData['sid'])) {
            return $tokenData;
        }
    }

    $authData = [
    'app_client_id' => $secrets['SBIS_APP_CLIENT_ID'],
    'app_secret'    => $secrets['SBIS_APP_SECRET'],
    'secret_key'    => $secrets['SBIS_SECRET_KEY'],
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
        logToFile("Error: Failed to get access token. Response: " . json_encode($response, JSON_UNESCAPED_UNICODE));
        return ['status' => 'error', 'message' => 'Failed to get access token'];
    }

    $responseData = json_decode($response, true);

    if (!isset($responseData['token']) || !isset($responseData['sid'])) {
        logToFile("Error: Invalid response from Sbis. Response: " . json_encode($response, JSON_UNESCAPED_UNICODE));
        return ['status' => 'error', 'message' => 'Invalid response from Sbis'];
    }

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

header('Content-Type: application/json');

$inputData = file_get_contents('php://input');

logToFile("Received from Tilda (raw): " . $inputData);

$data = json_decode($inputData, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logToFile("Error decoding JSON: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
    exit;
}

logToFile("Received from Tilda (decoded): " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));


// Проверяем, что это тестовый запрос
if (isset($data['test']) && $data['test'] === 'test') {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Test request received']);
    logToFile("Test request received and responded successfully.");
    exit;
}

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data'], JSON_UNESCAPED_UNICODE);
    logToFile("Error: Invalid JSON data");
    exit;
}

// Валидация обязательных полей
$requiredFields = ['Name', 'Mail', 'Phone', 'payment'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Missing required field: $field"], JSON_UNESCAPED_UNICODE);
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

// Загрузка списка товаров из all-products.json
$productsJsonPath = 'all-products.json';
$productsData = json_decode(file_get_contents($productsJsonPath), true);

if (!is_array($productsData)) {
    file_put_contents('logs/api-error.txt', "[" . date('Y-m-d H:i:s') . "] Ошибка загрузки all-products.json\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Ошибка загрузки базы товаров'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Функция поиска nomNumber по названию товара
function findNomNumberByName($tildaProduct, $productsData) {
    $baseName = $tildaProduct['name'];
    $variant = $tildaProduct['options'][0]['variant'] ?? null;

    // Словарь переименований
    $nameReplacements = [
        // 'Столовый прибор' => 'Приборы',
    ];

    if (isset($nameReplacements[$baseName])) {
        $baseName = $nameReplacements[$baseName];
    }

    // Формируем полное имя, если есть variant
    $fullName = $variant ? ($baseName . ' / ' . $variant) : $baseName;

    foreach ($productsData as $product) {
        if (isset($product['name']) && $product['name'] === $fullName) {
            return $product['nomNumber'] ?? null;
        }
    }

    return null;
}

//Парсинг адреса
function parseAddress($rawAddress) {
    // Убираем "RU:" в начале
    $address = trim(preg_replace('/^RU:\s*/i', '', $rawAddress));

    $beforeEnt = preg_split('/ent\.\s*/i', $address)[0];
    $beforeEnt = trim($beforeEnt, ' ,');

    $parts = array_map('trim', explode(',', $beforeEnt));

    $translatedFullAddress = translateAddressPartsToRussian($rawAddress);

    $result = [
        'City' => '',
        'Street' => '',
        'HouseNum' => '',
        'Address' => $translatedFullAddress
    ];

    // [0] — индекс,
    // [1] — город
    // [2] — улица
    // [3] — дом

    if (isset($parts[1])) {
        $result['City'] = $parts[1];
    }

    if (isset($parts[2])) {
        $result['Street'] = $parts[2];
    }

    if (isset($parts[3])) {
        $result['HouseNum'] = $parts[3];
    }

    return $result;
}

function translateAddressPartsToRussian($address) {
    return str_ireplace(
        ['ent.', 'fl.', 'entrance code:'],
        ['Подъезд', 'Этаж', 'Домофон'],
        $address
    );
}



// Вычисления времени доставки
function getDeliveryDateTime($data) {
    $dateField = $data["Date"] ?? '';
    $timeField = $data["Укажите_время"] ?? '';

    if (mb_stripos($timeField, 'быстрее') !== false) {
        return date('Y-m-d H:i:s', strtotime('+65 minutes'));
    }

    if (!$isPickup) {
        return date('Y-m-d H:i:s', strtotime('+25 minutes'));
    }

    $parsedDate = DateTime::createFromFormat('d-m-Y H:i', $dateField . ' ' . $timeField);
    if ($parsedDate instanceof DateTime) {
        return $parsedDate->format('Y-m-d H:i:s');
    } else {
        logToFile("Некорректный формат даты или времени: '$dateField' + '$timeField'");
        return date('Y-m-d H:i:s', strtotime('+65 minutes'));
    }
}



// Самовывоз или нет
    $isPickup = strpos($data["payment"]["delivery"], 'Самовывоз') !== false;

    // Сборка массива на передачу в сбис
    $nomenclatures = [];
    $persons = null;
    
    foreach ($data["payment"]["products"] as $product) {
        if ($product['name'] === 'Столовый прибор') {
            if (isset($product['quantity']) && $product['quantity'] > 0) {
                $persons = (int)$product['quantity'];
            }
            continue;
        }
    
        $nomNumber = findNomNumberByName($product, $productsData);
    
        if (!$nomNumber) {
            $msg = "[" . date('Y-m-d H:i:s') . "] Не найден nomNumber для товара: {$product["name"]}\n";
            file_put_contents('logs/api-error.txt', $msg, FILE_APPEND);
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Товар не найден: {$product["name"]}"], JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $nomenclatures[] = [
            "nomNumber" => $nomNumber,
            "priceListId" => 18,
            "count" => $product["quantity"],
            "cost" => (float)$product["price"]
        ];
    }
   

    
    $deliveryData = [
        "paymentType" => "card",
        "isPickup" => $isPickup
    ];
    
    if (!$isPickup) {
        $deliveryData["addressFull"] = translateAddressPartsToRussian($data["payment"]["delivery_address"]);
        $parsedAddress = parseAddress($data["payment"]["delivery_address"]);
        $deliveryData["addressJSON"] = json_encode($parsedAddress, JSON_UNESCAPED_UNICODE);
        if (!is_null($persons)) {
            $deliveryData["persons"] = $persons;
        }
    }

 

// формирование массива в сбис
$orderData = [
    "product" => "delivery",
    "pointId" => 319,
    "customer" => [
        "name" => $data["Name"],
        "phone" => preg_replace('/[^0-9]/', '', $data["Phone"]),
        "email" => $data["Mail"]
    ],
    "datetime" => getDeliveryDateTime($data),
    "comment" => $data["payment"]["delivery_comment"] ?? "",
    "priceListId" => 18,
    "nomenclatures" => $nomenclatures,
    "delivery" => $deliveryData
];

// Отправка в СБИС
logToFile("Sending to Sbis: " . json_encode($orderData, JSON_UNESCAPED_UNICODE));

$sbisResponse = sendOrderToSbis($orderData);

logToFile("Response from Sbis: " . json_encode($sbisResponse, JSON_UNESCAPED_UNICODE));

$response = [
    'status' => 'success',
    'message' => 'Data has been logged and sent to Sbis.',
    'sbis_response' => $sbisResponse
];

echo json_encode($response);
