<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET — публичный доступ: список меню
if ($method === 'GET') {
    $adminMode = isset($_GET['admin']);
    if ($adminMode) {
        requireAdmin();
        // Админ видит всё
        $stmt = $db->query("
            SELECT m.*, c.name as category_name
            FROM menu_items m
            JOIN categories c ON m.category_id = c.id
            ORDER BY c.sort_order, m.sort_order
        ");
    } else {
        // Публичное — только активные
        $stmt = $db->query("
            SELECT m.id, m.name, m.description, m.price, m.size, m.tags, m.image,
                   m.category_id, c.name as category_name
            FROM menu_items m
            JOIN categories c ON m.category_id = c.id
            WHERE m.is_active = 1 AND c.is_active = 1
            ORDER BY c.sort_order, m.sort_order
        ");
    }

    $items = $stmt->fetchAll();

    // Группируем по категориям
    $categories = [];
    foreach ($items as $item) {
        $catId = $item['category_id'];
        if (!isset($categories[$catId])) {
            $categories[$catId] = [
                'id' => $catId,
                'name' => $item['category_name'],
                'items' => []
            ];
        }
        $item['tags'] = $item['tags'] ? explode(',', $item['tags']) : [];
        $item['price'] = (float)$item['price'];
        $categories[$catId]['items'][] = $item;
    }

    jsonResponse(['categories' => array_values($categories)]);
}

// POST — создать позицию (админ)
if ($method === 'POST') {
    requireAdmin();
    $input = getInput();

    $required = ['name', 'category_id', 'price'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonError("Поле $field обязательно");
        }
    }

    $tags = '';
    if (!empty($input['tags'])) {
        $tags = is_array($input['tags']) ? implode(',', $input['tags']) : $input['tags'];
    }

    $stmt = $db->prepare("
        INSERT INTO menu_items (category_id, name, description, price, size, tags, image, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$input['category_id'],
        htmlspecialchars($input['name']),
        htmlspecialchars($input['description'] ?? ''),
        (float)$input['price'],
        htmlspecialchars($input['size'] ?? ''),
        htmlspecialchars($tags),
        $input['image'] ?? '',
        isset($input['is_active']) ? (int)$input['is_active'] : 1,
        (int)($input['sort_order'] ?? 0)
    ]);

    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
}

// PUT — обновить позицию (админ)
if ($method === 'PUT') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('ID обязателен');

    $input = getInput();
    $fields = [];
    $values = [];

    $allowed = ['name', 'category_id', 'description', 'price', 'size', 'tags', 'image', 'is_active', 'sort_order'];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            $val = $input[$field];
            if ($field === 'tags' && is_array($val)) {
                $val = implode(',', $val);
            }
            if (in_array($field, ['name', 'description', 'size', 'tags'])) {
                $val = htmlspecialchars((string)$val);
            }
            $fields[] = "$field = ?";
            $values[] = $val;
        }
    }

    if (empty($fields)) jsonError('Нет данных для обновления');

    $values[] = $id;
    $stmt = $db->prepare("UPDATE menu_items SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($values);

    jsonResponse(['success' => true]);
}

// DELETE — удалить позицию (админ, мягкое удаление)
if ($method === 'DELETE') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('ID обязателен');

    $stmt = $db->prepare("UPDATE menu_items SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['success' => true]);
}
