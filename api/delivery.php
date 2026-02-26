<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET — публичный: зоны доставки и статус
if ($method === 'GET') {
    $stmtEnabled = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'delivery_enabled'");
    $stmtEnabled->execute();
    $enabled = (bool)$stmtEnabled->fetchColumn();

    $stmtAddr = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'pickup_address'");
    $stmtAddr->execute();
    $pickupAddress = $stmtAddr->fetchColumn() ?: 'ул. Ярышлар, 2Б, с. Усады';

    $stmtMin = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'min_order_amount'");
    $stmtMin->execute();
    $minOrder = (float)$stmtMin->fetchColumn();

    $zones = [];
    if ($enabled) {
        $stmt = $db->query("SELECT * FROM delivery_zones WHERE is_active = 1 ORDER BY delivery_fee");
        $zones = $stmt->fetchAll();
        foreach ($zones as &$z) {
            $z['delivery_fee'] = (float)$z['delivery_fee'];
            $z['min_order'] = (float)$z['min_order'];
        }
        unset($z);
    }

    jsonResponse([
        'delivery_enabled' => $enabled,
        'pickup_address' => $pickupAddress,
        'min_order_amount' => $minOrder,
        'zones' => $zones
    ]);
}

// POST — сохранить настройки доставки (админ)
if ($method === 'POST') {
    requireAdmin();
    $input = getInput();
    $db->beginTransaction();

    try {
        // Обновляем основные настройки
        if (isset($input['delivery_enabled'])) {
            $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'delivery_enabled'")
                ->execute([$input['delivery_enabled'] ? '1' : '0']);
        }
        if (isset($input['pickup_address'])) {
            $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'pickup_address'")
                ->execute([htmlspecialchars($input['pickup_address'])]);
        }
        if (isset($input['min_order_amount'])) {
            $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'min_order_amount'")
                ->execute([(string)(float)$input['min_order_amount']]);
        }

        // Обновляем зоны доставки
        if (isset($input['zones']) && is_array($input['zones'])) {
            // Деактивируем все старые
            $db->exec("UPDATE delivery_zones SET is_active = 0");

            foreach ($input['zones'] as $zone) {
                if (!empty($zone['id'])) {
                    $db->prepare("UPDATE delivery_zones SET name = ?, delivery_fee = ?, min_order = ?, is_active = 1 WHERE id = ?")
                        ->execute([
                            htmlspecialchars($zone['name']),
                            (float)$zone['delivery_fee'],
                            (float)($zone['min_order'] ?? 0),
                            (int)$zone['id']
                        ]);
                } else {
                    $db->prepare("INSERT INTO delivery_zones (name, delivery_fee, min_order, is_active) VALUES (?,?,?,1)")
                        ->execute([
                            htmlspecialchars($zone['name']),
                            (float)$zone['delivery_fee'],
                            (float)($zone['min_order'] ?? 0)
                        ]);
                }
            }
        }

        $db->commit();
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Ошибка сохранения: ' . $e->getMessage(), 500);
    }
}
