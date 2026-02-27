<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireAdmin();

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET — все настройки
if ($method === 'GET') {
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        // Не отдаём хеш пароля на фронтенд
        if ($row['setting_key'] === 'admin_password_hash') continue;
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    jsonResponse(['settings' => $settings]);
}

// POST — обновить настройки
if ($method === 'POST') {
    $input = getInput();

    $allowed = [
        'telegram_bot_token',
        'telegram_chat_id',
        'delivery_enabled',
        'pickup_address',
        'working_hours',
        'min_order_amount',
        'restaurant_phone',
        'legal_business_type',
        'legal_name',
        'legal_inn',
        'legal_ogrn',
        'legal_address',
    ];

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $stmt->execute([$key, htmlspecialchars((string)$input[$key])]);
            }
        }

        // Смена пароля (отдельная обработка)
        if (!empty($input['new_password'])) {
            if (strlen($input['new_password']) < 4) {
                $db->rollBack();
                jsonError('Пароль должен быть не менее 4 символов');
            }
            $hash = password_hash($input['new_password'], PASSWORD_BCRYPT);
            $stmt->execute(['admin_password_hash', $hash]);
        }

        // Тест Telegram
        if (!empty($input['test_telegram'])) {
            $token = $input['telegram_bot_token'] ?? '';
            $chatId = $input['telegram_chat_id'] ?? '';

            if ($token && $chatId) {
                $url = "https://api.telegram.org/bot{$token}/sendMessage";
                $postData = json_encode([
                    'chat_id' => $chatId,
                    'text' => "✅ Тестовое сообщение от YouPechka!\nБот работает корректно.",
                    'parse_mode' => 'HTML'
                ]);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $db->commit();

                if ($httpCode === 200) {
                    jsonResponse(['success' => true, 'telegram_test' => 'ok']);
                } else {
                    jsonResponse(['success' => true, 'telegram_test' => 'failed', 'telegram_error' => 'HTTP ' . $httpCode]);
                }
                return;
            }
        }

        $db->commit();
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Ошибка сохранения: ' . $e->getMessage(), 500);
    }
}
