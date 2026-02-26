<?php
// ============================================
// Конфигурация базы данных YouPechka
// ============================================
// Замените значения на реальные данные от хостинга REG.RU

define('DB_HOST', 'localhost');
define('DB_NAME', 'youpechka');
define('DB_USER', 'youpechka_user');
define('DB_PASS', 'CHANGE_ME_TO_SECURE_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

// Загрузка изображений
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', 'uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 МБ
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp']);

// Сессии
define('TOKEN_LIFETIME', 86400); // 24 часа

// CORS и заголовки
header('Content-Type: application/json; charset=utf-8');

// Разрешаем CORS для того же домена
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
