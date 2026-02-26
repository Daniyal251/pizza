<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Метод не поддерживается', 405);
}

$input = getInput();
$password = $input['password'] ?? '';

if (!$password) {
    jsonError('Введите пароль');
}

$db = getDB();

// Получаем хеш пароля из настроек
$stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'admin_password_hash'");
$stmt->execute();
$row = $stmt->fetch();

if (!$row || !password_verify($password, $row['setting_value'])) {
    jsonError('Неверный пароль', 401);
}

// Генерируем токен
$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + TOKEN_LIFETIME);

// Удаляем старые сессии
$db->exec("DELETE FROM admin_sessions WHERE expires_at < NOW()");

// Создаём новую сессию
$stmt = $db->prepare("INSERT INTO admin_sessions (token, expires_at) VALUES (?, ?)");
$stmt->execute([$token, $expires]);

jsonResponse([
    'success' => true,
    'token' => $token,
    'expires_at' => $expires
]);
