<?php
require_once __DIR__ . '/db.php';
$db = getDB();

// Новый пароль: admin
$hash = password_hash('admin', PASSWORD_BCRYPT);

// Удалим старую запись и вставим новую
$db->exec("DELETE FROM settings WHERE setting_key = 'admin_password_hash'");
$stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
$stmt->execute(['admin_password_hash', $hash]);

// Проверим что всё записалось
$check = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'admin_password_hash'");
$check->execute();
$row = $check->fetch();

$works = password_verify('admin', $row['setting_value']);

echo json_encode([
    'hash_saved' => $row['setting_value'],
    'password_verify_test' => $works ? 'OK - пароль admin работает!' : 'ОШИБКА',
    'php_version' => PHP_VERSION
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
