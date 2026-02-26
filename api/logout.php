<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Метод не поддерживается', 405);
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(\S+)$/', $header, $m)) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM admin_sessions WHERE token = ?");
    $stmt->execute([$m[1]]);
}

jsonResponse(['success' => true]);
