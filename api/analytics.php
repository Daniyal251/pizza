<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireAdmin();

$db = getDB();
$period = $_GET['period'] ?? 'week';

// Определяем диапазон дат
switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d') . ' 00:00:00';
        $dateTo = date('Y-m-d') . ' 23:59:59';
        break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
        $dateTo = date('Y-m-d') . ' 23:59:59';
        break;
    case 'month':
        $dateFrom = date('Y-m-d', strtotime('-29 days')) . ' 00:00:00';
        $dateTo = date('Y-m-d') . ' 23:59:59';
        break;
    case 'custom':
        $dateFrom = ($_GET['from'] ?? date('Y-m-d', strtotime('-6 days'))) . ' 00:00:00';
        $dateTo = ($_GET['to'] ?? date('Y-m-d')) . ' 23:59:59';
        break;
    default:
        $dateFrom = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
        $dateTo = date('Y-m-d') . ' 23:59:59';
}

// Общая статистика (исключая отменённые)
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_orders,
        COALESCE(SUM(total), 0) as total_revenue,
        COALESCE(ROUND(AVG(total), 0), 0) as avg_order_value
    FROM orders
    WHERE created_at BETWEEN ? AND ? AND status != 'canceled'
");
$stmt->execute([$dateFrom, $dateTo]);
$summary = $stmt->fetch();
$summary['total_orders'] = (int)$summary['total_orders'];
$summary['total_revenue'] = (float)$summary['total_revenue'];
$summary['avg_order_value'] = (float)$summary['avg_order_value'];

// Отменённые
$stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ? AND status = 'canceled'");
$stmt->execute([$dateFrom, $dateTo]);
$summary['canceled_count'] = (int)$stmt->fetchColumn();

// По дням
$stmt = $db->prepare("
    SELECT DATE(created_at) as date,
        COUNT(*) as orders,
        COALESCE(SUM(total), 0) as revenue
    FROM orders
    WHERE created_at BETWEEN ? AND ? AND status != 'canceled'
    GROUP BY DATE(created_at)
    ORDER BY date
");
$stmt->execute([$dateFrom, $dateTo]);
$dailyChart = $stmt->fetchAll();
foreach ($dailyChart as &$d) {
    $d['orders'] = (int)$d['orders'];
    $d['revenue'] = (float)$d['revenue'];
}
unset($d);

// Популярные позиции
$stmt = $db->prepare("
    SELECT oi.name, SUM(oi.quantity) as quantity_sold, SUM(oi.price * oi.quantity) as revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.created_at BETWEEN ? AND ? AND o.status != 'canceled'
    GROUP BY oi.name
    ORDER BY quantity_sold DESC
    LIMIT 10
");
$stmt->execute([$dateFrom, $dateTo]);
$popularItems = $stmt->fetchAll();
foreach ($popularItems as &$p) {
    $p['quantity_sold'] = (int)$p['quantity_sold'];
    $p['revenue'] = (float)$p['revenue'];
}
unset($p);

// По статусам (все время)
$stmt = $db->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
$statusRows = $stmt->fetchAll();
$ordersByStatus = [];
foreach ($statusRows as $row) {
    $ordersByStatus[$row['status']] = (int)$row['count'];
}

jsonResponse([
    'summary' => $summary,
    'daily_chart' => $dailyChart,
    'popular_items' => $popularItems,
    'orders_by_status' => $ordersByStatus,
    'period' => ['from' => $dateFrom, 'to' => $dateTo]
]);
