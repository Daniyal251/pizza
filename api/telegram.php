<?php
// Внутренний хелпер — не вызывается напрямую с фронтенда
require_once __DIR__ . '/db.php';

function sendTelegramNotification(array $order, array $orderItems): bool {
    $db = getDB();

    $stmtToken = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'telegram_bot_token'");
    $stmtToken->execute();
    $botToken = $stmtToken->fetchColumn();

    $stmtChat = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'telegram_chat_id'");
    $stmtChat->execute();
    $chatId = $stmtChat->fetchColumn();

    if (!$botToken || !$chatId) {
        return false; // Telegram не настроен
    }

    $deliveryText = $order['delivery_type'] === 'delivery'
        ? "Доставка ({$order['delivery_address']})"
        : 'Самовывоз (ул. Ярышлар, 2Б)';

    $message = "🍕 <b>НОВЫЙ ЗАКАЗ (#{$order['order_number']})</b>\n\n";
    $message .= "👤 <b>Имя:</b> " . htmlspecialchars($order['customer_name']) . "\n";
    $message .= "📞 <b>Телефон:</b> {$order['customer_phone']}\n";
    $message .= "🚚 <b>Способ:</b> {$deliveryText}\n\n";
    $message .= "🛒 <b>Состав заказа:</b>\n";

    foreach ($orderItems as $item) {
        $lineTotal = $item['price'] * $item['quantity'];
        $message .= "- {$item['name']} x{$item['quantity']} = {$lineTotal}₽\n";
    }

    if ($order['discount_amount'] > 0) {
        $message .= "\n🎁 <b>Скидка:</b> -{$order['discount_amount']}₽";
        if ($order['promo_code']) {
            $message .= " (промокод: {$order['promo_code']})";
        }
        $message .= "\n";
    }
    if ($order['delivery_fee'] > 0) {
        $message .= "🚗 <b>Доставка:</b> {$order['delivery_fee']}₽\n";
    }

    $message .= "\n💰 <b>ИТОГО: {$order['total']} ₽</b>";

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $postData = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        // Помечаем заказ как отправленный
        $stmt = $db->prepare("UPDATE orders SET telegram_sent = 1 WHERE id = ?");
        $stmt->execute([$order['id']]);
        return true;
    }

    return false;
}
