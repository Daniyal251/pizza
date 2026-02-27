<?php
// ============================================
// Установочный скрипт — запустите ОДИН раз, затем УДАЛИТЕ этот файл!
// ============================================
require_once __DIR__ . '/db.php';

$db = getDB();

try {
    // --- Таблицы ---

    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS menu_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        name VARCHAR(200) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL,
        size VARCHAR(50),
        tags VARCHAR(500),
        image VARCHAR(500),
        is_active TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(20) NOT NULL UNIQUE,
        customer_name VARCHAR(200) NOT NULL,
        customer_phone VARCHAR(30) NOT NULL,
        delivery_type ENUM('pickup','delivery') DEFAULT 'pickup',
        delivery_address TEXT,
        promo_code VARCHAR(50),
        discount_amount DECIMAL(10,2) DEFAULT 0,
        subtotal DECIMAL(10,2) NOT NULL,
        delivery_fee DECIMAL(10,2) DEFAULT 0,
        total DECIMAL(10,2) NOT NULL,
        status ENUM('new','cooking','ready','delivering','done','canceled') DEFAULT 'new',
        notes TEXT,
        payment_method VARCHAR(30) DEFAULT 'cash',
        payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
        payment_id VARCHAR(100),
        telegram_sent TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        menu_item_id INT,
        name VARCHAR(200) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS promo_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        discount_type ENUM('percent','fixed') DEFAULT 'percent',
        discount_value DECIMAL(10,2) NOT NULL,
        min_order DECIMAL(10,2) DEFAULT 0,
        max_uses INT DEFAULT NULL,
        used_count INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        valid_from DATETIME,
        valid_until DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS delivery_zones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        delivery_fee DECIMAL(10,2) NOT NULL,
        min_order DECIMAL(10,2) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Начальные данные: настройки ---
    $defaultPassword = password_hash('admin', PASSWORD_BCRYPT);
    $settingsData = [
        ['admin_password_hash', $defaultPassword],
        ['telegram_bot_token', ''],
        ['telegram_chat_id', ''],
        ['delivery_enabled', '0'],
        ['pickup_address', 'ул. Ярышлар, 2Б, с. Усады'],
        ['working_hours', '10:00-21:00'],
        ['min_order_amount', '0'],
        ['restaurant_phone', '8 (929) 725-24-55'],
        ['legal_business_type', ''],
        ['legal_name', ''],
        ['legal_inn', ''],
        ['legal_ogrn', ''],
        ['legal_address', ''],
        ['payment_online_enabled', '0'],
        ['yookassa_shop_id', ''],
        ['yookassa_secret_key', ''],
    ];
    $stmtS = $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settingsData as $row) {
        $stmtS->execute($row);
    }

    // --- Начальные данные: категории ---
    $catStmt = $db->prepare("INSERT IGNORE INTO categories (id, name, sort_order) VALUES (?, ?, ?)");
    $catStmt->execute([1, 'Пицца', 1]);
    $catStmt->execute([2, 'Дополни пиццу', 2]);

    // --- Начальные данные: меню ---
    $menuStmt = $db->prepare("INSERT IGNORE INTO menu_items (id, category_id, name, description, price, size, tags, image, sort_order) VALUES (?,?,?,?,?,?,?,?,?)");

    $menuData = [
        [1,1,'Пепперони','Тонкое тесто, сливочный/томатный соус, сыр моцарелла, пепперони',550,'30 см','','https://images.unsplash.com/photo-1628840042765-356cda07504e?w=500&q=80',1],
        [2,1,'Мясная','Тонкое тесто, томатный соус, сыр моцарелла, ветчина, пепперони, куриная грудка, охотничьи колбаски, маслины',650,'30 см','Много мяса!','https://images.unsplash.com/photo-1604381538336-254bc79a9f49?w=500&q=80',2],
        [3,1,'Курица грибы','Тонкое тесто, томатный соус, сыр моцарелла, куриное филе, шампиньоны',550,'30 см','','https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=500&q=80',3],
        [4,1,'С креветками','Тонкое тесто, сливочный соус, сыр моцарелла, королевские креветки, томаты, базилик',600,'30 см','','https://images.unsplash.com/photo-1516697073-419b2bd079db?w=500&q=80',4],
        [5,1,'Сливочная','Тонкое тесто, сливочный соус, сыр моцарелла, ветчина, красный лук, шампиньоны',600,'30 см','New!','https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=500&q=80',5],
        [6,1,'Диабло','Тонкое тесто, томатный соус, сыр моцарелла, ветчина, пепперони, куриная грудка, охотничьи колбаски, красный лук, перец халапеньо',650,'30 см','Острая','https://images.unsplash.com/photo-1528137871618-79d2761e3fd5?w=500&q=80',6],
        [7,1,'Песто','Тонкое тесто, томатный соус, сыр моцарелла, ветчина, томаты, соус песто',550,'30 см','','https://images.unsplash.com/photo-1573225342350-16731dd9be38?w=500&q=80',7],
        [8,1,'Цезарь','Тонкое тесто, сливочный соус, сыр моцарелла, куриное филе, салат Айсберг, томаты черри',600,'30 см','Моя любимая!','https://images.unsplash.com/photo-1513104890138-7c749659a591?w=500&q=80',8],
        [9,1,'Курица с ананасами','Тонкое тесто, сливочный соус, сыр моцарелла, куриное филе, ананасы',550,'30 см','','https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=500&q=80',9],
        [10,1,'Четыре вкуса','Тонкое тесто, сливочный соус, сыр моцарелла, пепперони, курица-грибы, 4 сыра, ветчина',770,'33 см','Большая!','https://images.unsplash.com/photo-1513104890138-7c749659a591?w=500&q=80',10],
        [11,1,'Четыре сыра','Тонкое тесто, сливочный соус, сыр моцарелла, чеддер, дорблю, пармезан',550,'30 см','','https://images.unsplash.com/photo-1513104890138-7c749659a591?w=500&q=80',11],
        [12,1,'Грибная','Тонкое тесто, сливочный соус, сыр моцарелла, шампиньоны, орегано',500,'30 см','','https://images.unsplash.com/photo-1513104890138-7c749659a591?w=500&q=80',12],
        [13,1,'Овощи грибы','Тонкое тесто, томатный соус, сыр моцарелла, шампиньоны, томаты, сладкий перец, красный лук',550,'30 см','','https://images.unsplash.com/photo-1592924357228-91a4daadcfea?w=500&q=80',13],
        [14,1,'С грушей и горгонзолой','Тонкое тесто, сливочный соус, сыр моцарелла, груша, сыр горгонзола',550,'30 см','','https://images.unsplash.com/photo-1513104890138-7c749659a591?w=500&q=80',14],
        [15,1,'Маргарита','Тонкое тесто, томатный соус, сыр моцарелла, томаты, базилик',500,'30 см','','https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=500&q=80',15],
        [16,1,'Бургер','Тонкое тесто, томатный соус, сыр моцарелла, бекон, курица, красный лук, соленые огурчики, соус бургер',650,'30 см','New!','https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=500&q=80',16],
        [17,1,'Пикантная','Тонкое тесто, томатный соус, сыр моцарелла, ветчина, пепперони, бекон, шампиньоны, соус барбекю',650,'30 см','Попробуйте!','https://images.unsplash.com/photo-1628840042765-356cda07504e?w=500&q=80',17],
        [18,1,'Барбекю','Тонкое тесто, томатный соус, сыр моцарелла, куриное филе, томаты, красный лук, соус барбекю',650,'30 см','','https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=500&q=80',18],
        [19,1,'С беконом','Тонкое тесто, томатный соус, сыр моцарелла, бекон, томаты, красный лук, соус барбекю',650,'30 см','Острая','https://images.unsplash.com/photo-1628840042765-356cda07504e?w=500&q=80',19],
        [20,1,'С охотничьими колбасками','Тонкое тесто, томатный соус, сыр моцарелла, охотничьи колбаски',550,'30 см','','https://images.unsplash.com/photo-1628840042765-356cda07504e?w=500&q=80',20],
        [101,2,'Сыр','',50,'Добавка','','https://images.unsplash.com/photo-1486297678162-eb2a19b0a32d?w=500&q=80',1],
        [102,2,'Лук / перец / томаты / грибы','',30,'Добавка','','https://images.unsplash.com/photo-1592924357228-91a4daadcfea?w=500&q=80',2],
        [103,2,'Ветчина / пепперони / курица','',50,'Добавка','','https://images.unsplash.com/photo-1615937657715-bc7b4b7962c1?w=500&q=80',3],
    ];

    foreach ($menuData as $row) {
        $menuStmt->execute($row);
    }

    // --- Зоны доставки по умолчанию ---
    $dzStmt = $db->prepare("INSERT IGNORE INTO delivery_zones (id, name, delivery_fee, min_order) VALUES (?,?,?,?)");
    $dzStmt->execute([1, 'До 3 км', 100, 500]);
    $dzStmt->execute([2, '3-7 км', 200, 800]);
    $dzStmt->execute([3, '7-15 км', 350, 1000]);

    jsonResponse([
        'success' => true,
        'message' => 'База данных успешно создана и заполнена! Удалите файл install.php.',
        'default_password' => 'admin'
    ]);

} catch (Exception $e) {
    jsonError('Ошибка установки', 500, $e->getMessage());
}
