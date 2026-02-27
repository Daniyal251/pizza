<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/telegram.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET — список заказов (только админ)
if ($method === 'GET') {
    requireAdmin();

    $where = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[] = 'o.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['date_from'])) {
        $where[] = 'o.created_at >= ?';
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where[] = 'o.created_at <= ?';
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }
    if (!empty($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $where[] = '(o.customer_phone LIKE ? OR o.order_number LIKE ? OR o.customer_name LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    // Количество
    $countStmt = $db->prepare("SELECT COUNT(*) FROM orders o $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Заказы
    $stmt = $db->prepare("
        SELECT o.* FROM orders o $whereSQL
        ORDER BY o.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    // Позиции заказов
    if ($orders) {
        $ids = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $db->prepare("SELECT * FROM order_items WHERE order_id IN ($placeholders)");
        $itemStmt->execute($ids);
        $allItems = $itemStmt->fetchAll();

        $itemsByOrder = [];
        foreach ($allItems as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }

        foreach ($orders as &$order) {
            $order['items'] = $itemsByOrder[$order['id']] ?? [];
            $order['total'] = (float)$order['total'];
            $order['subtotal'] = (float)$order['subtotal'];
            $order['delivery_fee'] = (float)$order['delivery_fee'];
            $order['discount_amount'] = (float)$order['discount_amount'];
        }
        unset($order);
    }

    jsonResponse([
        'orders' => $orders,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// POST — создать заказ (публичный)
if ($method === 'POST') {
    $input = getInput();

    // Валидация
    if (empty($input['customer_name'])) jsonError('Укажите имя');
    if (empty($input['customer_phone'])) jsonError('Укажите телефон');
    if (empty($input['items']) || !is_array($input['items'])) jsonError('Корзина пуста');

    $deliveryType = in_array($input['delivery_type'] ?? '', ['pickup', 'delivery']) ? $input['delivery_type'] : 'pickup';
    $paymentMethod = in_array($input['payment_method'] ?? '', ['cash', 'card']) ? $input['payment_method'] : 'cash';
    $paymentStatus = ($paymentMethod === 'cash') ? 'paid' : 'pending';
    $deliveryAddress = '';
    if ($deliveryType === 'delivery') {
        $deliveryAddress = htmlspecialchars(trim($input['delivery_address'] ?? ''));
        if (!$deliveryAddress) jsonError('Укажите адрес доставки');
    }

    // Получаем цены из БД (не доверяем клиенту!)
    $menuIds = array_map(fn($i) => (int)$i['menu_item_id'], $input['items']);
    $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
    $stmt = $db->prepare("SELECT id, name, price FROM menu_items WHERE id IN ($placeholders) AND is_active = 1");
    $stmt->execute($menuIds);
    $dbItems = $stmt->fetchAll();
    $dbItemsById = [];
    foreach ($dbItems as $item) {
        $dbItemsById[$item['id']] = $item;
    }

    // Собираем позиции и считаем подитог
    $orderItems = [];
    $subtotal = 0;
    foreach ($input['items'] as $cartItem) {
        $id = (int)$cartItem['menu_item_id'];
        $qty = max(1, (int)($cartItem['quantity'] ?? 1));
        if (!isset($dbItemsById[$id])) continue; // пропускаем несуществующие

        $dbItem = $dbItemsById[$id];
        $orderItems[] = [
            'menu_item_id' => $id,
            'name' => $dbItem['name'],
            'price' => (float)$dbItem['price'],
            'quantity' => $qty
        ];
        $subtotal += (float)$dbItem['price'] * $qty;
    }

    if (empty($orderItems)) jsonError('Ни одна позиция не найдена в меню');

    // Промокод
    $promoCode = trim($input['promo_code'] ?? '');
    $discount = 0;
    if ($promoCode) {
        $promoStmt = $db->prepare("
            SELECT * FROM promo_codes
            WHERE code = ? AND is_active = 1
            AND (valid_from IS NULL OR valid_from <= NOW())
            AND (valid_until IS NULL OR valid_until >= NOW())
            AND (max_uses IS NULL OR used_count < max_uses)
        ");
        $promoStmt->execute([$promoCode]);
        $promo = $promoStmt->fetch();

        if ($promo && $subtotal >= (float)$promo['min_order']) {
            if ($promo['discount_type'] === 'percent') {
                $discount = round($subtotal * (float)$promo['discount_value'] / 100, 2);
            } else {
                $discount = min((float)$promo['discount_value'], $subtotal);
            }
        } else {
            $promoCode = ''; // Промокод невалиден
        }
    }

    // Стоимость доставки
    $deliveryFee = 0;
    if ($deliveryType === 'delivery') {
        // Берём первую активную зону (упрощённо — без расчёта расстояния)
        $dzStmt = $db->query("SELECT delivery_fee FROM delivery_zones WHERE is_active = 1 ORDER BY delivery_fee ASC LIMIT 1");
        $dz = $dzStmt->fetch();
        $deliveryFee = $dz ? (float)$dz['delivery_fee'] : 0;
    }

    $total = $subtotal - $discount + $deliveryFee;

    // Генерация номера заказа
    $year = date('Y');
    $countStmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE YEAR(created_at) = ?");
    $countStmt->execute([$year]);
    $orderNum = (int)$countStmt->fetchColumn() + 1;
    $orderNumber = $year . '-' . str_pad($orderNum, 4, '0', STR_PAD_LEFT);

    // Сохраняем заказ
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO orders (order_number, customer_name, customer_phone, delivery_type, delivery_address,
                promo_code, discount_amount, subtotal, delivery_fee, total, payment_method, payment_status, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')
        ");
        $stmt->execute([
            $orderNumber,
            htmlspecialchars($input['customer_name']),
            htmlspecialchars($input['customer_phone']),
            $deliveryType,
            $deliveryAddress,
            $promoCode,
            $discount,
            $subtotal,
            $deliveryFee,
            $total,
            $paymentMethod,
            $paymentStatus
        ]);
        $orderId = (int)$db->lastInsertId();

        // Позиции заказа
        $itemStmt = $db->prepare("INSERT INTO order_items (order_id, menu_item_id, name, price, quantity) VALUES (?,?,?,?,?)");
        foreach ($orderItems as $oi) {
            $itemStmt->execute([$orderId, $oi['menu_item_id'], $oi['name'], $oi['price'], $oi['quantity']]);
        }

        // Увеличиваем счётчик промокода
        if ($promoCode && $discount > 0) {
            $db->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE code = ?")->execute([$promoCode]);
        }

        $db->commit();

        // Telegram-уведомление (после коммита)
        $orderData = [
            'id' => $orderId,
            'order_number' => $orderNumber,
            'customer_name' => $input['customer_name'],
            'customer_phone' => $input['customer_phone'],
            'delivery_type' => $deliveryType,
            'delivery_address' => $deliveryAddress,
            'promo_code' => $promoCode,
            'discount_amount' => $discount,
            'delivery_fee' => $deliveryFee,
            'total' => $total
        ];
        sendTelegramNotification($orderData, $orderItems);

        jsonResponse([
            'success' => true,
            'order_number' => $orderNumber,
            'total' => $total
        ], 201);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Ошибка создания заказа: ' . $e->getMessage(), 500);
    }
}

// PUT — обновить статус заказа (админ)
if ($method === 'PUT') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('ID обязателен');

    $input = getInput();
    $fields = [];
    $values = [];

    if (isset($input['status'])) {
        $allowed = ['new', 'cooking', 'ready', 'delivering', 'done', 'canceled'];
        if (!in_array($input['status'], $allowed)) jsonError('Недопустимый статус');
        $fields[] = 'status = ?';
        $values[] = $input['status'];
    }
    if (isset($input['notes'])) {
        $fields[] = 'notes = ?';
        $values[] = htmlspecialchars($input['notes']);
    }

    if (empty($fields)) jsonError('Нет данных для обновления');

    $values[] = $id;
    $stmt = $db->prepare("UPDATE orders SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($values);

    jsonResponse(['success' => true]);
}
