<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM categories ORDER BY sort_order");
    jsonResponse(['categories' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    requireAdmin();
    $input = getInput();

    if (empty($input['name'])) {
        jsonError('Название категории обязательно');
    }

    // Обновление или создание
    if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE categories SET name = ?, sort_order = ?, is_active = ? WHERE id = ?");
        $stmt->execute([
            htmlspecialchars($input['name']),
            (int)($input['sort_order'] ?? 0),
            isset($input['is_active']) ? (int)$input['is_active'] : 1,
            (int)$input['id']
        ]);
        jsonResponse(['success' => true]);
    }

    $stmt = $db->prepare("INSERT INTO categories (name, sort_order) VALUES (?, ?)");
    $stmt->execute([
        htmlspecialchars($input['name']),
        (int)($input['sort_order'] ?? 0)
    ]);

    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
}

if ($method === 'DELETE') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('ID обязателен');

    // Проверяем, нет ли блюд в категории
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM menu_items WHERE category_id = ? AND is_active = 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row['cnt'] > 0) {
        jsonError('Нельзя удалить категорию с активными блюдами. Сначала удалите или переместите блюда.');
    }

    $stmt = $db->prepare("UPDATE categories SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['success' => true]);
}
