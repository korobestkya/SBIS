<?php

// Функция для логирования
function logToFile($message, $logFile = 'sbis_log-test.txt') {
    $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Функция для получения токена доступа
function getSbisAccessToken() {
    $tokenFile = 'sbis_token.txt';

    if (file_exists($tokenFile)) {
        $tokenData = json_decode(file_get_contents($tokenFile), true);
        if (isset($tokenData['token'], $tokenData['sid'])) {
            return $tokenData;
        }
    }

    $authData = [
        'app_client_id' => '1282701763162249',
        'app_secret'    => '6UVATTLYU6XTMBJJNF3HG0TZ',
        'secret_key'    => 'sMg1PVR0ptWkpW0v4v8XM3jjumNzTTsYMMqd1Sbwz4idDan2C81plOvZNaw5TyAzQeRHcyqeW8u3sqHEojkJ1ba9qeZ3Eq5f324brSdV95wM7VZHWsNzzX'
    ];

    $ch = curl_init('https://online.sbis.ru/oauth/service/');
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($authData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8'
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseData = json_decode($response, true);

    if (!isset($responseData['token']) || !isset($responseData['sid'])) {
        logToFile("Ошибка получения токена: " . $response);
        return ['status' => 'error', 'message' => 'Ошибка получения токена'];
    }

    file_put_contents($tokenFile, json_encode($responseData));

    return $responseData;
}

// Функция отправки заказа в СБИС
function sendOrderToSbis($orderData) {
    $tokenData = getSbisAccessToken();

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
    curl_close($ch);

    logToFile("Ответ СБИС: " . $response);

    return json_decode($response, true);
}

$timezone = new DateTimeZone('Etc/GMT-5'); // GMT+5
$datetime = new DateTime('now', $timezone);
$datetime->modify('+5 minutes');
// Заголовок JSON
header('Content-Type: application/json');

// Тестовый заказ (без лишних проверок)
$orderData = [
    "product" => "delivery",
    "pointId" => 319,
    "comment" => "as fast as possible",
    "customer" => [
        "name" => "Алексей",
        "lastname" => "Алексеев",
        "patronymic" => "Алексеевич",
        "email" => "alex@post.com",
        "phone" => "88005553535"
    ],
    "datetime" => $datetime->format('Y-m-d H:i:s'),
    "promocode" => "SALE30",
    "nomenclatures" => [
        [
            "id" => 79452,
            "priceListId" => 18,
            "count" => 1,
            "cost" => 115,
            "name" => "Какой-то товар"
        ]
    ],
    "delivery" => [
        "addressFull" => "г. Уфа, ул. Менделеева, д. 134/7",
        "addressJSON" => '{"City": "г. Уфа", "Street": "ул. Менделеева", "HouseNum": "д.134/7", "Coordinates": {"lat":50, "lon": 50}}',
        "paymentType" => "card",
        "persons" => 4,
        "isPickup" => false
    ]
];

logToFile("Отправляемые данные в СБИС: " . json_encode($orderData, JSON_UNESCAPED_UNICODE));
// Отправка данных
$response = sendOrderToSbis($orderData);

// Вывод результата
exit(json_encode($response, JSON_UNESCAPED_UNICODE));