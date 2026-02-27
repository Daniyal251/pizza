<?php
// Публичный эндпоинт — возвращает юридические реквизиты (без авторизации)
require_once __DIR__ . '/db.php';

$db = getDB();

$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'legal_%' OR setting_key = 'restaurant_phone' OR setting_key = 'pickup_address'");
$stmt->execute();
$rows = $stmt->fetchAll();

$legal = [];
foreach ($rows as $row) {
    $legal[$row['setting_key']] = $row['setting_value'];
}

jsonResponse(['legal' => $legal]);
