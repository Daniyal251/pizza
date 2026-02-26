<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET — публичная валидация промокода ИЛИ список всех (админ)
if ($method === 'GET') {
    // Публичная проверка одного промокода
    if (!empty($_GET['code'])) {
        $code = trim($_GET['code']);
        $stmt = $db->prepare("
            SELECT id, code, discount_type, discount_value, min_order
            FROM promo_codes
            WHERE code = ? AND is_active = 1
            AND (valid_from IS NULL OR valid_from <= NOW())
            AND (valid_until IS NULL OR valid_until >= NOW())
            AND (max_uses IS NULL OR used_count < max_uses)
        ");
        $stmt->execute([$code]);
        $promo = $stmt->fetch();

        if (!$promo) {
            jsonError('Промокод недействителен или истёк', 404);
        }

        $promo['discount_value'] = (float)$promo['discount_value'];
        $promo['min_order'] = (float)$promo['min_order'];
        jsonResponse(['promo' => $promo]);
    }

    // Полный список для админа
    requireAdmin();
    $stmt = $db->query("SELECT * FROM promo_codes ORDER BY created_at DESC");
    $promos = $stmt->fetchAll();
    foreach ($promos as &$p) {
        $p['discount_value'] = (float)$p['discount_value'];
        $p['min_order'] = (float)$p['min_order'];
    }
    unset($p);
    jsonResponse(['promos' => $promos]);
}

// POST — создать или обновить промокод (админ)
if ($method === 'POST') {
    requireAdmin();
    $input = getInput();

    if (empty($input['code'])) jsonError('Код обязателен');
    if (!isset($input['discount_value']) || $input['discount_value'] <= 0) jsonError('Укажите размер скидки');

    $discountType = in_array($input['discount_type'] ?? '', ['percent', 'fixed']) ? $input['discount_type'] : 'percent';

    // Обновление
    if (!empty($input['id'])) {
        $stmt = $db->prepare("
            UPDATE promo_codes SET code = ?, discount_type = ?, discount_value = ?,
                min_order = ?, max_uses = ?, is_active = ?, valid_from = ?, valid_until = ?
            WHERE id = ?
        ");
        $stmt->execute([
            strtoupper(trim($input['code'])),
            $discountType,
            (float)$input['discount_value'],
            (float)($input['min_order'] ?? 0),
            $input['max_uses'] !== '' && $input['max_uses'] !== null ? (int)$input['max_uses'] : null,
            isset($input['is_active']) ? (int)$input['is_active'] : 1,
            $input['valid_from'] ?: null,
            $input['valid_until'] ?: null,
            (int)$input['id']
        ]);
        jsonResponse(['success' => true]);
    }

    // Создание
    $stmt = $db->prepare("
        INSERT INTO promo_codes (code, discount_type, discount_value, min_order, max_uses, is_active, valid_from, valid_until)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        strtoupper(trim($input['code'])),
        $discountType,
        (float)$input['discount_value'],
        (float)($input['min_order'] ?? 0),
        $input['max_uses'] !== '' && $input['max_uses'] !== null ? (int)$input['max_uses'] : null,
        isset($input['is_active']) ? (int)$input['is_active'] : 1,
        $input['valid_from'] ?: null,
        $input['valid_until'] ?: null,
    ]);

    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()], 201);
}

// DELETE — удалить промокод (админ)
if ($method === 'DELETE') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('ID обязателен');

    $stmt = $db->prepare("DELETE FROM promo_codes WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['success' => true]);
}
