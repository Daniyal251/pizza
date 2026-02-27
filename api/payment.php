<?php
// ============================================
// Платёжный модуль — подготовлен под ЮKassa
// ============================================
// Для подключения:
// 1. Зарегистрируйтесь на yookassa.ru
// 2. Получите shop_id и secret_key
// 3. Введите их в Админ-панель → Настройки → Онлайн-оплата
// 4. Включите тумблер "Онлайн-оплата"
// 5. Укажите URL для уведомлений (webhook): https://youpechka.ru/api/payment.php?action=webhook
// ============================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============================================
// POST /api/payment.php?action=create — создать платёж
// Вызывается после создания заказа, если payment_method = 'card'
// ============================================
if ($method === 'POST' && $action === 'create') {
    $input = getInput();
    $orderNumber = $input['order_number'] ?? '';

    if (!$orderNumber) jsonError('Номер заказа обязателен');

    // Получаем заказ
    $stmt = $db->prepare("SELECT id, total, order_number, payment_method, payment_status FROM orders WHERE order_number = ?");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if (!$order) jsonError('Заказ не найден', 404);
    if ($order['payment_method'] !== 'card') jsonError('Заказ не требует онлайн-оплаты');
    if ($order['payment_status'] === 'paid') jsonError('Заказ уже оплачен');

    // Проверяем настройки ЮKassa
    $settingsStmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('yookassa_shop_id', 'yookassa_secret_key', 'payment_online_enabled')");
    $settings = [];
    foreach ($settingsStmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    if (empty($settings['payment_online_enabled']) || $settings['payment_online_enabled'] !== '1') {
        jsonError('Онлайн-оплата временно недоступна. Пожалуйста, выберите оплату наличными.', 503);
    }

    $shopId = $settings['yookassa_shop_id'] ?? '';
    $secretKey = $settings['yookassa_secret_key'] ?? '';

    if (!$shopId || !$secretKey) {
        jsonError('Онлайн-оплата не настроена. Обратитесь к администратору.', 503);
    }

    // ============================================
    // СОЗДАНИЕ ПЛАТЕЖА В ЮKASSA
    // Документация: https://yookassa.ru/developers/api#create_payment
    // ============================================
    $paymentData = [
        'amount' => [
            'value' => number_format((float)$order['total'], 2, '.', ''),
            'currency' => 'RUB'
        ],
        'confirmation' => [
            'type' => 'redirect',
            'return_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/payment-result.html?order=' . $order['order_number']
        ],
        'capture' => true,
        'description' => 'Заказ #' . $order['order_number'] . ' — YouПечка',
        'metadata' => [
            'order_id' => $order['id'],
            'order_number' => $order['order_number']
        ]
    ];

    $idempotencyKey = uniqid('yp_', true);

    $ch = curl_init('https://api.yookassa.ru/v3/payments');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($paymentData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Idempotence-Key: ' . $idempotencyKey
        ],
        CURLOPT_USERPWD => $shopId . ':' . $secretKey,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("YooKassa create payment error: HTTP $httpCode, response: $response");
        jsonError('Ошибка создания платежа. Попробуйте позже.', 502);
    }

    $paymentResult = json_decode($response, true);

    if (empty($paymentResult['id']) || empty($paymentResult['confirmation']['confirmation_url'])) {
        error_log("YooKassa unexpected response: $response");
        jsonError('Ошибка платёжной системы', 502);
    }

    // Сохраняем ID платежа в заказе
    $db->prepare("UPDATE orders SET payment_id = ? WHERE id = ?")
       ->execute([$paymentResult['id'], $order['id']]);

    jsonResponse([
        'payment_url' => $paymentResult['confirmation']['confirmation_url'],
        'payment_id' => $paymentResult['id']
    ]);
}

// ============================================
// POST /api/payment.php?action=webhook — webhook от ЮKassa
// Настройте в личном кабинете ЮKassa: URL уведомлений
// ============================================
if ($method === 'POST' && $action === 'webhook') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!$data || empty($data['event']) || empty($data['object'])) {
        http_response_code(400);
        exit;
    }

    $event = $data['event'];
    $payment = $data['object'];
    $paymentId = $payment['id'] ?? '';

    if (!$paymentId) {
        http_response_code(400);
        exit;
    }

    // Находим заказ по payment_id
    $stmt = $db->prepare("SELECT id, payment_status FROM orders WHERE payment_id = ?");
    $stmt->execute([$paymentId]);
    $order = $stmt->fetch();

    if (!$order) {
        // Платёж не связан с заказом — OK, не ошибка
        http_response_code(200);
        exit;
    }

    $newStatus = null;

    switch ($event) {
        case 'payment.succeeded':
            $newStatus = 'paid';
            break;
        case 'payment.canceled':
            $newStatus = 'failed';
            break;
        case 'refund.succeeded':
            $newStatus = 'refunded';
            break;
    }

    if ($newStatus && $order['payment_status'] !== $newStatus) {
        $db->prepare("UPDATE orders SET payment_status = ? WHERE id = ?")
           ->execute([$newStatus, $order['id']]);

        // Если оплата не прошла — можно автоматически отменить заказ
        if ($newStatus === 'failed') {
            $db->prepare("UPDATE orders SET status = 'canceled', notes = CONCAT(IFNULL(notes,''), '\nОплата не прошла') WHERE id = ? AND status = 'new'")
               ->execute([$order['id']]);
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// ============================================
// GET /api/payment.php?action=status&order=XXXX — проверить статус оплаты
// Вызывается со страницы payment-result.html
// ============================================
if ($method === 'GET' && $action === 'status') {
    $orderNumber = $_GET['order'] ?? '';
    if (!$orderNumber) jsonError('Номер заказа обязателен');

    $stmt = $db->prepare("SELECT payment_status, payment_method, order_number FROM orders WHERE order_number = ?");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if (!$order) jsonError('Заказ не найден', 404);

    jsonResponse([
        'order_number' => $order['order_number'],
        'payment_method' => $order['payment_method'],
        'payment_status' => $order['payment_status']
    ]);
}

// ============================================
// GET /api/payment.php?action=config — проверить доступна ли онлайн-оплата
// Вызывается фронтендом при загрузке чекаута
// ============================================
if ($method === 'GET' && $action === 'config') {
    $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'payment_online_enabled'");
    $row = $stmt->fetch();
    $enabled = ($row && $row['setting_value'] === '1');

    jsonResponse(['online_payment_enabled' => $enabled]);
}
