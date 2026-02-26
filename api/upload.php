<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Метод не поддерживается', 405);
}

requireAdmin();

if (empty($_FILES['image'])) {
    jsonError('Файл не загружен');
}

$file = $_FILES['image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonError('Ошибка загрузки файла');
}

if ($file['size'] > MAX_UPLOAD_SIZE) {
    jsonError('Файл слишком большой (максимум 5 МБ)');
}

// Проверяем расширение
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXTENSIONS)) {
    jsonError('Допустимые форматы: ' . implode(', ', ALLOWED_EXTENSIONS));
}

// Проверяем MIME-тип
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes)) {
    jsonError('Недопустимый тип файла');
}

// Создаём уникальное имя
$filename = 'dish_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destination = UPLOAD_DIR . $filename;

// Создаём директорию если нет
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    jsonError('Не удалось сохранить файл', 500);
}

// Пробуем уменьшить изображение (макс. 800x800)
if (function_exists('imagecreatefromjpeg')) {
    $info = getimagesize($destination);
    if ($info && ($info[0] > 800 || $info[1] > 800)) {
        $src = null;
        switch ($info['mime']) {
            case 'image/jpeg': $src = imagecreatefromjpeg($destination); break;
            case 'image/png':  $src = imagecreatefrompng($destination); break;
            case 'image/webp': $src = imagecreatefromwebp($destination); break;
        }
        if ($src) {
            $ratio = min(800 / $info[0], 800 / $info[1]);
            $newW = (int)($info[0] * $ratio);
            $newH = (int)($info[1] * $ratio);
            $dst = imagecreatetruecolor($newW, $newH);

            if ($info['mime'] === 'image/png' || $info['mime'] === 'image/webp') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $info[0], $info[1]);

            switch ($info['mime']) {
                case 'image/jpeg': imagejpeg($dst, $destination, 85); break;
                case 'image/png':  imagepng($dst, $destination, 8); break;
                case 'image/webp': imagewebp($dst, $destination, 85); break;
            }
            imagedestroy($src);
            imagedestroy($dst);
        }
    }
}

jsonResponse([
    'success' => true,
    'url' => UPLOAD_URL . $filename
]);
