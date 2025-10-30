<?php
session_start();

// Увеличиваем лимиты PHP
set_time_limit(0); // Без ограничений по времени
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

// --- Конфиг логина ---
if (!file_exists('config.php')) {
    die("<h1>Admin Panel Configuration Error</h1>
        <p>The configuration file <code>config.php</code> is missing.</p>
        <p>To set up the admin panel, please create a new file named <strong>config.php</strong> in the root directory of the website.</p>
        <p>Copy and paste the following code into the new file:</p>
        <pre style='background-color: #f4f4f4; padding: 15px; border: 1px solid #ddd; border-radius: 5px; color: #333;'>
&lt;?php
// --- Login Config ---
\$admin_user = 'admin';
\$admin_pass = 'admin';
?&gt;
        </pre>
        <p>After creating the file, you can change the username and password inside it to whatever you like. Please make sure to choose a strong, secure password.</p>");
}
require_once 'config.php';

// База данных машин (JSON файл)
$dbFile = 'cars_db.json';

// Выход
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// Авторизация
if (isset($_POST['login']) && isset($_POST['password']) && !isset($_FILES['csv_file']) && !isset($_POST['action'])) {
    if ($_POST['login'] === $admin_user && $_POST['password'] === $admin_pass) {
        $_SESSION['auth'] = true;
        header("Location: admin.php");
        exit;
    } else {
        $error = "❌ Неверный логин или пароль";
    }
}

if (!isset($_SESSION['auth'])) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head><meta charset="UTF-8"><title>Вход в админку</title></head>
    <body style="background:#111;color:#fff;display:flex;justify-content:center;align-items:center;height:100vh;">
        <form method="post" style="background:#222;padding:20px;border-radius:10px;">
            <h2>Вход в админку</h2>
            <?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>
            <input type="text" name="login" placeholder="Логин" style="display:block;width:100%;margin:10px 0;padding:8px;">
            <input type="password" name="password" placeholder="Пароль" style="display:block;width:100%;margin:10px 0;padding:8px;">
            <button type="submit" style="padding:10px 20px;">Войти</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

require_once 'admin_functions.php';

// === AJAX ОБРАБОТКА ИМПОРТА (ПАКЕТНАЯ ЗАГРУЗКА) ===
if (isset($_POST['ajax_process_batch']) && isset($_POST['batch_index'])) {
    header('Content-Type: application/json');

    $batchIndex = intval($_POST['batch_index']);
    $previewData = $_SESSION['preview_data'] ?? [];

    if (empty($previewData)) {
        echo json_encode(['success' => false, 'error' => 'Нет данных для импорта']);
        exit;
    }

    if ($batchIndex >= count($previewData)) {
        echo json_encode(['success' => true, 'done' => true, 'message' => 'Импорт завершен!']);
        exit;
    }

    $carInfo = $previewData[$batchIndex];

    try {
        // Скачиваем изображения
        $images = downloadImages($carInfo['minio_images'], 'assets/images');

        if (empty($images)) {
            echo json_encode([
                'success' => false,
                'error' => "Нет изображений для {$carInfo['title']}"
            ]);
            exit;
        }

        $db = loadDB('cars_db.json');
        $carId = uniqid('car_');

        $carData = [
            'id' => $carId,
            'title' => $carInfo['title'],
            'make' => $carInfo['make'],
            'model' => $carInfo['model'],
            'year' => $carInfo['year'],
            'price' => $carInfo['price'],
            'mileage' => $carInfo['mileage'],
            'vin' => $carInfo['vin'],
            'engine' => $carInfo['engine'],
            'transmission' => $carInfo['transmission'],
            'exterior_color' => $carInfo['exterior_color'],
            'interior_color' => $carInfo['interior_color'],
            'description' => $carInfo['description'],
            'images' => $images,
            'slug' => $carInfo['slug']
        ];

        // Сохраняем в БД
        $db[$carId] = $carData;
        saveDB('cars_db.json', $db);

        // Генерируем страницу
        generateCarPage($carData);

        // Добавляем карточку
        addCardToInventory(generateCarCard($carData));

        echo json_encode([
            'success' => true,
            'done' => false,
            'message' => "✅ {$carInfo['title']} добавлена",
            'processed' => $batchIndex + 1,
            'total' => count($previewData)
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit;
}

// === ОБРАБОТКА ДЕЙСТВИЙ ===

// Regenerate all car pages
if (isset($_GET['regenerate_all'])) {
    $db = loadDB($dbFile);
    $regeneratedCount = 0;
    foreach ($db as $carData) {
        generateCarPage($carData);
        $regeneratedCount++;
    }
    $message = "✅ Success! Regenerated $regeneratedCount car pages with the new template.";
    header("Location: admin.php?msg=" . urlencode($message));
    exit;
}

$message = '';
$previewData = null;

// Удаление машины
if (isset($_GET['delete'])) {
    $carId = $_GET['delete'];
    $db = loadDB($dbFile);

    if (isset($db[$carId])) {
        $car = $db[$carId];
        @unlink("cars/{$car['slug']}.html");
        removeCardFromInventory($carId);
        unset($db[$carId]);
        saveDB($dbFile, $db);
        $message = "✅ Машина {$car['title']} успешно удалена";
    }
}

// Удаление ВСЕХ машин
if (isset($_GET['delete_all']) && $_GET['delete_all'] === 'confirm') {
    $db = loadDB($dbFile);
    $deletedCount = 0;

    // Удаляем все HTML файлы машин
    foreach ($db as $car) {
        @unlink("cars/{$car['slug']}.html");
        $deletedCount++;
    }

    // Удаляем все карточки из inventory.html
    $inventoryHtml = file_get_contents('inventory.html');
    // Удаляем все карточки с data-car-id
    $inventoryHtml = preg_replace(
        '/<a[^>]*data-car-id="[^"]*"[^>]*>.*?<\/a>\s*/s',
        '',
        $inventoryHtml
    );
    file_put_contents('inventory.html', $inventoryHtml);

    // Очищаем базу данных
    saveDB($dbFile, []);

    $message = "✅ Удалено $deletedCount машин. База данных очищена.";
    header("Location: admin.php?msg=" . urlencode($message));
    exit;
}

// Шаг 1: Загрузка и предпросмотр CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && !isset($_POST['confirm_import'])) {
    $csvFile = $_FILES['csv_file']['tmp_name'];

    if (!file_exists($csvFile)) {
        $message = "❌ Ошибка загрузки CSV файла";
    } else {
        $handle = fopen($csvFile, 'r');
        $headers = fgetcsv($handle);

        $previewData = [];

        while (($row = fgetcsv($handle)) !== false) {
            try {
                $data = array_combine($headers, $row);

                $make = trim($data['Make'] ?? '');
                $model = trim($data['Model'] ?? '');
                $year = trim($data['Year'] ?? '');
                $price = trim($data['Price'] ?? '');
                $mileage = trim($data['Mileage'] ?? '');
                $vin = trim($data['VIN'] ?? '');
                $engine = trim($data['Engine'] ?? '');
                $transmission = trim($data['Transmission'] ?? '');
                $exteriorColor = trim($data['ExteriorColor'] ?? '');
                $interiorColor = trim($data['InteriorColor'] ?? '');
                $description = trim($data['Description'] ?? '');
                $minioImages = trim($data['Minio_Images'] ?? '');
                $title = trim($data['Title'] ?? '') ?: "$year $make $model";

                if (empty($make) || empty($model)) continue;

                $imageUrls = json_decode($minioImages, true);
                $firstImageUrl = is_array($imageUrls) && !empty($imageUrls) ? $imageUrls[0] : '';

                $slug = strtolower(str_replace(' ', '-', "$year-$make-$model"));
                $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

                $previewData[] = [
                    'title' => $title,
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                    'price' => $price,
                    'mileage' => $mileage,
                    'vin' => $vin,
                    'engine' => $engine,
                    'transmission' => $transmission,
                    'exterior_color' => $exteriorColor,
                    'interior_color' => $interiorColor,
                    'description' => $description,
                    'image_url' => $firstImageUrl,
                    'minio_images' => $minioImages,
                    'slug' => $slug
                ];

            } catch (Exception $e) {
                // Пропускаем ошибочные строки
            }
        }

        fclose($handle);
        $_SESSION['preview_data'] = $previewData;
        $message = "Найдено " . count($previewData) . " машин. Проверьте данные и нажмите 'Загрузить на сайт'.";
    }
}

// Редактирование машины
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $carId = $_POST['car_id'];
    $db = loadDB($dbFile);

    if (isset($db[$carId])) {
        removeCardFromInventory($carId);

        // Обработка удаления фотографий
        if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
            $deleteIndices = array_map('intval', $_POST['delete_images']);
            $currentImages = $db[$carId]['images'];
            $newImages = [];

            foreach ($currentImages as $index => $imageName) {
                if (in_array($index, $deleteIndices)) {
                    // Удаляем файл с диска
                    @unlink("assets/images/$imageName");
                } else {
                    // Оставляем изображение
                    $newImages[] = $imageName;
                }
            }

            // Проверяем что осталось хотя бы одно изображение
            if (empty($newImages)) {
                $message = "❌ Нельзя удалить все фотографии. Должна остаться хотя бы одна.";
                header("Location: admin.php?edit=$carId&error=" . urlencode($message));
                exit;
            }

            $db[$carId]['images'] = $newImages;
        }

        $db[$carId]['title'] = $_POST['title'];
        $db[$carId]['make'] = $_POST['make'];
        $db[$carId]['model'] = $_POST['model'];
        $db[$carId]['year'] = $_POST['year'];
        $db[$carId]['price'] = $_POST['price'];
        $db[$carId]['mileage'] = $_POST['mileage'];
        $db[$carId]['vin'] = $_POST['vin'];
        $db[$carId]['engine'] = $_POST['engine'];
        $db[$carId]['transmission'] = $_POST['transmission'];
        $db[$carId]['exterior_color'] = $_POST['exterior_color'];
        $db[$carId]['interior_color'] = $_POST['interior_color'];
        $db[$carId]['description'] = $_POST['description'];

        generateCarPage($db[$carId]);
        addCardToInventory(generateCarCard($db[$carId]));
        saveDB($dbFile, $db);

        $deletedCount = isset($_POST['delete_images']) ? count($_POST['delete_images']) : 0;
        $message = $deletedCount > 0
            ? "✅ Машина успешно обновлена. Удалено фото: $deletedCount"
            : "✅ Машина успешно обновлена";
        header("Location: admin.php?msg=" . urlencode($message));
        exit;
    }
}

// Сохранение настроек стилей
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_styles') {
    $cssFile = 'admin-style.css';
    $currentVars = getCSSVariables($cssFile);

    // Обновляем переменные из формы
    $newVars = [];
    foreach ($currentVars as $name => $oldValue) {
        if (isset($_POST['var_' . $name])) {
            $newVars[$name] = trim($_POST['var_' . $name]);
        } else {
            $newVars[$name] = $oldValue;
        }
    }

    if (saveCSSVariables($cssFile, $newVars)) {
        $message = "✅ Настройки стилей успешно сохранены!";
        header("Location: admin.php?tab=styles&msg=" . urlencode($message));
    } else {
        $message = "❌ Ошибка при сохранении стилей";
        header("Location: admin.php?tab=styles&error=" . urlencode($message));
    }
    exit;
}

// Применение готового шаблона темы к основному сайту
if (isset($_GET['apply_main_theme'])) {
    $template = $_GET['apply_main_theme'];
    $templateVars = [];

    switch ($template) {
        case 'light':
            $templateVars = [
                'bg' => '#ffffff',
                'panel' => '#f8f9fa',
                'text' => '#1a1a1a',
                'muted' => '#666666',
                'accent' => '#ff6a00',
                'accent-2' => '#ff9140'
            ];
            break;

        case 'dark':
            $templateVars = [
                'bg' => '#0b0e14',
                'panel' => '#141821',
                'text' => '#eef1f5',
                'muted' => '#aab4c3',
                'accent' => '#ff6a00',
                'accent-2' => '#ff9140'
            ];
            break;

        case 'ocean':
            $templateVars = [
                'bg' => '#f0f8ff',
                'panel' => '#e6f3ff',
                'text' => '#0d1b2a',
                'muted' => '#4a5f7f',
                'accent' => '#0077b6',
                'accent-2' => '#00b4d8'
            ];
            break;

        case 'sunset':
            $templateVars = [
                'bg' => '#fff5f5',
                'panel' => '#ffe5e5',
                'text' => '#2d1b1b',
                'muted' => '#7f5a5a',
                'accent' => '#ff6b6b',
                'accent-2' => '#ffa07a'
            ];
            break;

        case 'forest':
            $templateVars = [
                'bg' => '#f4f9f4',
                'panel' => '#e8f5e8',
                'text' => '#1b2d1b',
                'muted' => '#5a7f5a',
                'accent' => '#2d6a2d',
                'accent-2' => '#66bb6a'
            ];
            break;

        case 'purple':
            $templateVars = [
                'bg' => '#f8f5ff',
                'panel' => '#f0e6ff',
                'text' => '#2d1b3d',
                'muted' => '#7f5a9f',
                'accent' => '#7b2cbf',
                'accent-2' => '#9d4edd'
            ];
            break;

        case 'monochrome':
            $templateVars = [
                'bg' => '#ffffff',
                'panel' => '#f5f5f5',
                'text' => '#000000',
                'muted' => '#666666',
                'accent' => '#333333',
                'accent-2' => '#555555'
            ];
            break;

        case 'coffee':
            $templateVars = [
                'bg' => '#faf8f5',
                'panel' => '#f5f0e8',
                'text' => '#2d2520',
                'muted' => '#7f6f5f',
                'accent' => '#8b6f47',
                'accent-2' => '#a68a64'
            ];
            break;

        case 'midnight':
            $templateVars = [
                'bg' => '#0a0e27',
                'panel' => '#0f1433',
                'text' => '#e0e6ff',
                'muted' => '#8892b0',
                'accent' => '#3b82f6',
                'accent-2' => '#60a5fa'
            ];
            break;

        case 'cyberpunk':
            $templateVars = [
                'bg' => '#0d0d0d',
                'panel' => '#1a1a1a',
                'text' => '#e0e0e0',
                'muted' => '#a0a0a0',
                'accent' => '#ff2a6d',
                'accent-2' => '#05d9e8'
            ];
            break;

        default:
            $message = "❌ Неизвестный шаблон";
            header("Location: admin.php?tab=main-themes&error=" . urlencode($message));
            exit;
    }

    if (!empty($templateVars) && applyThemeToMainSite($templateVars)) {
        $templateNames = [
            'light' => 'Светлая классика',
            'dark' => 'Темная классика',
            'ocean' => 'Океан',
            'sunset' => 'Закат',
            'forest' => 'Лес',
            'purple' => 'Фиолетовая мечта',
            'monochrome' => 'Монохром',
            'coffee' => 'Кофейная',
            'midnight' => 'Полночь',
            'cyberpunk' => 'Киберпанк'
        ];
        $message = "✅ Тема '{$templateNames[$template]}' успешно применена к основному сайту!";
    } else {
        $message = "❌ Ошибка при применении темы к основному сайту";
    }
    header("Location: admin.php?tab=main-themes&msg=" . urlencode($message));
    exit;
}

// Сброс стилей к светлой теме
if (isset($_GET['reset_styles']) && $_GET['reset_styles'] === 'light') {
    $cssFile = 'admin-style.css';
    $defaultLightVars = [
        'bg' => '#ffffff',
        'panel' => '#f8f9fa',
        'text' => '#1a1a1a',
        'muted' => '#666666',
        'accent' => '#e19c5a',
        'accent-2' => '#7ac0c4',
        'stroke' => '#e0e0e0',
        'card' => '#ffffff',
        'gradient-1' => '#f0f0f0',
        'gradient-2' => '#f5f5f5',
        'header-bg' => 'rgba(255, 255, 255, 0.9)',
        'footer-bg' => 'rgba(248, 249, 250, 0.95)',
        'nav-mobile-bg' => 'rgba(255, 255, 255, 0.98)',
        'btn-primary-dark' => '#cc8748',
        'btn-primary-border' => '#b87536',
        'btn-primary-text' => '#1a120a',
        'input-bg' => '#ffffff',
        'media-bg' => '#f5f5f5',
        'media-bg-2' => '#f0f0f0',
        'media-bg-3' => '#ececec',
        'gallery-bg' => '#f5f5f5',
        'overlay-dark' => 'rgba(0, 0, 0, 0.35)',
        'overlay-light' => 'rgba(255, 255, 255, 0.25)',
        'shadow-sm' => 'rgba(0, 0, 0, 0.04)',
        'shadow-md' => 'rgba(0, 0, 0, 0.1)',
        'shadow-lg' => 'rgba(0, 0, 0, 0.3)'
    ];

    if (saveCSSVariables($cssFile, $defaultLightVars)) {
        $message = "✅ Стили сброшены к светлой теме!";
    } else {
        $message = "❌ Ошибка при сбросе стилей";
    }
    header("Location: admin.php?tab=styles&msg=" . urlencode($message));
    exit;
}

// Применение готового шаблона темы
if (isset($_GET['apply_template'])) {
    $cssFile = 'admin-style.css';
    $template = $_GET['apply_template'];
    $templateVars = [];

    switch ($template) {
        case 'light':
            $templateVars = [
                'bg' => '#ffffff',
                'panel' => '#f8f9fa',
                'text' => '#1a1a1a',
                'muted' => '#666666',
                'accent' => '#e19c5a',
                'accent-2' => '#7ac0c4',
                'stroke' => '#e0e0e0',
                'card' => '#ffffff',
                'gradient-1' => '#f0f0f0',
                'gradient-2' => '#f5f5f5',
                'header-bg' => 'rgba(255, 255, 255, 0.9)',
                'footer-bg' => 'rgba(248, 249, 250, 0.95)',
                'nav-mobile-bg' => 'rgba(255, 255, 255, 0.98)',
                'btn-primary-dark' => '#cc8748',
                'btn-primary-border' => '#b87536',
                'btn-primary-text' => '#1a120a',
                'input-bg' => '#ffffff',
                'media-bg' => '#f5f5f5',
                'media-bg-2' => '#f0f0f0',
                'media-bg-3' => '#ececec',
                'gallery-bg' => '#f5f5f5',
                'overlay-dark' => 'rgba(0, 0, 0, 0.35)',
                'overlay-light' => 'rgba(255, 255, 255, 0.25)',
                'shadow-sm' => 'rgba(0, 0, 0, 0.04)',
                'shadow-md' => 'rgba(0, 0, 0, 0.1)',
                'shadow-lg' => 'rgba(0, 0, 0, 0.3)'
            ];
            break;

        case 'dark':
            $templateVars = [
                'bg' => '#0e0e10',
                'panel' => '#141417',
                'text' => '#ebe9e6',
                'muted' => '#bdb7ad',
                'accent' => '#e19c5a',
                'accent-2' => '#7ac0c4',
                'stroke' => '#2a2a2d',
                'card' => '#18181c',
                'gradient-1' => '#1b1b1f',
                'gradient-2' => '#16161a',
                'header-bg' => 'rgba(14, 14, 16, 0.7)',
                'footer-bg' => 'rgba(20, 20, 22, 0.6)',
                'nav-mobile-bg' => 'rgba(14, 14, 16, 0.95)',
                'btn-primary-dark' => '#cc8748',
                'btn-primary-border' => '#b87536',
                'btn-primary-text' => '#1a120a',
                'input-bg' => '#111114',
                'media-bg' => '#222228',
                'media-bg-2' => '#232329',
                'media-bg-3' => '#1f2630',
                'gallery-bg' => '#1b1b20',
                'overlay-dark' => 'rgba(0, 0, 0, 0.5)',
                'overlay-light' => 'rgba(255, 255, 255, 0.15)',
                'shadow-sm' => 'rgba(0, 0, 0, 0.2)',
                'shadow-md' => 'rgba(0, 0, 0, 0.3)',
                'shadow-lg' => 'rgba(0, 0, 0, 0.5)'
            ];
            break;

        case 'ocean':
            $templateVars = [
                'bg' => '#f0f8ff',
                'panel' => '#e6f3ff',
                'text' => '#0d1b2a',
                'muted' => '#4a5f7f',
                'accent' => '#0077b6',
                'accent-2' => '#00b4d8',
                'stroke' => '#90caf9',
                'card' => '#ffffff',
                'gradient-1' => '#cfe8fc',
                'gradient-2' => '#e3f2fd',
                'header-bg' => 'rgba(230, 243, 255, 0.9)',
                'footer-bg' => 'rgba(230, 243, 255, 0.95)',
                'nav-mobile-bg' => 'rgba(230, 243, 255, 0.98)',
                'btn-primary-dark' => '#005f8a',
                'btn-primary-border' => '#004d73',
                'btn-primary-text' => '#ffffff',
                'input-bg' => '#ffffff',
                'media-bg' => '#e3f2fd',
                'media-bg-2' => '#cfe8fc',
                'media-bg-3' => '#bbdefb',
                'gallery-bg' => '#e3f2fd',
                'overlay-dark' => 'rgba(0, 119, 182, 0.35)',
                'overlay-light' => 'rgba(144, 202, 249, 0.25)',
                'shadow-sm' => 'rgba(0, 119, 182, 0.08)',
                'shadow-md' => 'rgba(0, 119, 182, 0.15)',
                'shadow-lg' => 'rgba(0, 119, 182, 0.3)'
            ];
            break;

        case 'sunset':
            $templateVars = [
                'bg' => '#fff5f5',
                'panel' => '#ffe5e5',
                'text' => '#2d1b1b',
                'muted' => '#7f5a5a',
                'accent' => '#ff6b6b',
                'accent-2' => '#ffa07a',
                'stroke' => '#ffcccc',
                'card' => '#ffffff',
                'gradient-1' => '#ffe0e0',
                'gradient-2' => '#fff0f0',
                'header-bg' => 'rgba(255, 229, 229, 0.9)',
                'footer-bg' => 'rgba(255, 229, 229, 0.95)',
                'nav-mobile-bg' => 'rgba(255, 229, 229, 0.98)',
                'btn-primary-dark' => '#e55555',
                'btn-primary-border' => '#cc4444',
                'btn-primary-text' => '#ffffff',
                'input-bg' => '#ffffff',
                'media-bg' => '#ffe0e0',
                'media-bg-2' => '#ffcccc',
                'media-bg-3' => '#ffb8b8',
                'gallery-bg' => '#ffe0e0',
                'overlay-dark' => 'rgba(255, 107, 107, 0.35)',
                'overlay-light' => 'rgba(255, 204, 204, 0.25)',
                'shadow-sm' => 'rgba(255, 107, 107, 0.08)',
                'shadow-md' => 'rgba(255, 107, 107, 0.15)',
                'shadow-lg' => 'rgba(255, 107, 107, 0.3)'
            ];
            break;

        case 'forest':
            $templateVars = [
                'bg' => '#f4f9f4',
                'panel' => '#e8f5e8',
                'text' => '#1b2d1b',
                'muted' => '#5a7f5a',
                'accent' => '#2d6a2d',
                'accent-2' => '#66bb6a',
                'stroke' => '#a5d6a7',
                'card' => '#ffffff',
                'gradient-1' => '#d4edd4',
                'gradient-2' => '#e8f5e8',
                'header-bg' => 'rgba(232, 245, 232, 0.9)',
                'footer-bg' => 'rgba(232, 245, 232, 0.95)',
                'nav-mobile-bg' => 'rgba(232, 245, 232, 0.98)',
                'btn-primary-dark' => '#1e4d1e',
                'btn-primary-border' => '#163a16',
                'btn-primary-text' => '#ffffff',
                'input-bg' => '#ffffff',
                'media-bg' => '#e8f5e8',
                'media-bg-2' => '#d4edd4',
                'media-bg-3' => '#c8e6c9',
                'gallery-bg' => '#e8f5e8',
                'overlay-dark' => 'rgba(45, 106, 45, 0.35)',
                'overlay-light' => 'rgba(165, 214, 167, 0.25)',
                'shadow-sm' => 'rgba(45, 106, 45, 0.08)',
                'shadow-md' => 'rgba(45, 106, 45, 0.15)',
                'shadow-lg' => 'rgba(45, 106, 45, 0.3)'
            ];
            break;

        case 'purple':
            $templateVars = [
                'bg' => '#f8f5ff',
                'panel' => '#f0e6ff',
                'text' => '#2d1b3d',
                'muted' => '#7f5a9f',
                'accent' => '#7b2cbf',
                'accent-2' => '#9d4edd',
                'stroke' => '#d4b3ff',
                'card' => '#ffffff',
                'gradient-1' => '#e8d4ff',
                'gradient-2' => '#f0e6ff',
                'header-bg' => 'rgba(240, 230, 255, 0.9)',
                'footer-bg' => 'rgba(240, 230, 255, 0.95)',
                'nav-mobile-bg' => 'rgba(240, 230, 255, 0.98)',
                'btn-primary-dark' => '#5a1f99',
                'btn-primary-border' => '#48157a',
                'btn-primary-text' => '#ffffff',
                'input-bg' => '#ffffff',
                'media-bg' => '#e8d4ff',
                'media-bg-2' => '#d4b3ff',
                'media-bg-3' => '#c79fff',
                'gallery-bg' => '#e8d4ff',
                'overlay-dark' => 'rgba(123, 44, 191, 0.35)',
                'overlay-light' => 'rgba(212, 179, 255, 0.25)',
                'shadow-sm' => 'rgba(123, 44, 191, 0.08)',
                'shadow-md' => 'rgba(123, 44, 191, 0.15)',
                'shadow-lg' => 'rgba(123, 44, 191, 0.3)'
            ];
            break;

        case 'monochrome':
            $templateVars = [
                'bg' => '#ffffff',
                'panel' => '#f5f5f5',
                'text' => '#000000',
                'muted' => '#666666',
                'accent' => '#333333',
                'accent-2' => '#555555',
                'stroke' => '#cccccc',
                'card' => '#ffffff',
                'gradient-1' => '#eeeeee',
                'gradient-2' => '#f5f5f5',
                'header-bg' => 'rgba(245, 245, 245, 0.9)',
                'footer-bg' => 'rgba(245, 245, 245, 0.95)',
                'nav-mobile-bg' => 'rgba(245, 245, 245, 0.98)',
                'btn-primary-dark' => '#222222',
                'btn-primary-border' => '#111111',
                'btn-primary-text' => '#ffffff',
                'input-bg' => '#ffffff',
                'media-bg' => '#f5f5f5',
                'media-bg-2' => '#eeeeee',
                'media-bg-3' => '#e8e8e8',
                'gallery-bg' => '#f5f5f5',
                'overlay-dark' => 'rgba(0, 0, 0, 0.35)',
                'overlay-light' => 'rgba(204, 204, 204, 0.25)',
                'shadow-sm' => 'rgba(0, 0, 0, 0.08)',
                'shadow-md' => 'rgba(0, 0, 0, 0.15)',
                'shadow-lg' => 'rgba(0, 0, 0, 0.3)'
            ];
            break;

        case 'coffee':
            $templateVars = [
                'bg' => '#faf8f5',
                'panel' => '#f5f0e8',
                'text' => '#2d2520',
                'muted' => '#7f6f5f',
                'accent' => '#8b6f47',
                'accent-2' => '#a68a64',
                'stroke' => '#d4c4b0',
                'card' => '#ffffff',
                'gradient-1' => '#ebe5dc',
                'gradient-2' => '#f5f0e8',
                'header-bg' => 'rgba(245, 240, 232, 0.9)',
                'footer-bg' => 'rgba(245, 240, 232, 0.95)',
                'nav-mobile-bg' => 'rgba(245, 240, 232, 0.98)',
                'btn-primary-dark' => '#6f5535',
                'btn-primary-border' => '#5a4328',
                'btn-primary-text' => '#ffffff',
                'input-bg' => '#ffffff',
                'media-bg' => '#f5f0e8',
                'media-bg-2' => '#ebe5dc',
                'media-bg-3' => '#e0d8cc',
                'gallery-bg' => '#f5f0e8',
                'overlay-dark' => 'rgba(139, 111, 71, 0.35)',
                'overlay-light' => 'rgba(212, 196, 176, 0.25)',
                'shadow-sm' => 'rgba(139, 111, 71, 0.08)',
                'shadow-md' => 'rgba(139, 111, 71, 0.15)',
                'shadow-lg' => 'rgba(139, 111, 71, 0.3)'
            ];
            break;

        case 'midnight':
            $templateVars = [
                'bg' => '#0a0e27',
                'panel' => '#0f1433',
                'text' => '#e0e6ff',
                'muted' => '#8892b0',
                'accent' => '#3b82f6',
                'accent-2' => '#60a5fa',
                'stroke' => '#1e293b',
                'card' => '#0f1433',
                'gradient-1' => '#0d1128',
                'gradient-2' => '#0a0e27',
                'header-bg' => 'rgba(15, 20, 51, 0.8)',
                'footer-bg' => 'rgba(15, 20, 51, 0.7)',
                'nav-mobile-bg' => 'rgba(15, 20, 51, 0.95)',
                'btn-primary-dark' => '#2563eb',
                'btn-primary-border' => '#1d4ed8',
                'btn-primary-text' => '#ffffff',
                'input-bg' => '#1a1f3a',
                'media-bg' => '#1a1f3a',
                'media-bg-2' => '#151933',
                'media-bg-3' => '#12162d',
                'gallery-bg' => '#1a1f3a',
                'overlay-dark' => 'rgba(0, 0, 0, 0.6)',
                'overlay-light' => 'rgba(59, 130, 246, 0.2)',
                'shadow-sm' => 'rgba(59, 130, 246, 0.15)',
                'shadow-md' => 'rgba(59, 130, 246, 0.25)',
                'shadow-lg' => 'rgba(59, 130, 246, 0.35)'
            ];
            break;

        case 'cyberpunk':
            $templateVars = [
                'bg' => '#0d0d0d',
                'panel' => '#1a1a1a',
                'text' => '#e0e0e0',
                'muted' => '#a0a0a0',
                'accent' => '#ff2a6d',
                'accent-2' => '#05d9e8',
                'stroke' => '#333333',
                'card' => '#1a1a1a',
                'gradient-1' => '#1a0d1a',
                'gradient-2' => '#0d1a1a',
                'header-bg' => 'rgba(26, 26, 26, 0.85)',
                'footer-bg' => 'rgba(26, 26, 26, 0.75)',
                'nav-mobile-bg' => 'rgba(26, 26, 26, 0.95)',
                'btn-primary-dark' => '#d91a5c',
                'btn-primary-border' => '#b3154a',
                'btn-primary-text' => '#ffffff',
                'input-bg' => '#262626',
                'media-bg' => '#262626',
                'media-bg-2' => '#1f1f1f',
                'media-bg-3' => '#1a1a1a',
                'gallery-bg' => '#262626',
                'overlay-dark' => 'rgba(255, 42, 109, 0.4)',
                'overlay-light' => 'rgba(5, 217, 232, 0.2)',
                'shadow-sm' => 'rgba(255, 42, 109, 0.2)',
                'shadow-md' => 'rgba(255, 42, 109, 0.3)',
                'shadow-lg' => 'rgba(255, 42, 109, 0.4)'
            ];
            break;

        default:
            $message = "❌ Неизвестный шаблон";
            header("Location: admin.php?tab=templates&error=" . urlencode($message));
            exit;
    }

    if (!empty($templateVars) && saveCSSVariables($cssFile, $templateVars)) {
        $templateNames = [
            'light' => 'Светлая классика',
            'dark' => 'Темная классика',
            'ocean' => 'Океан',
            'sunset' => 'Закат',
            'forest' => 'Лес',
            'purple' => 'Фиолетовая мечта',
            'monochrome' => 'Монохром',
            'coffee' => 'Кофейная'
        ];
        $message = "✅ Шаблон '{$templateNames[$template]}' успешно применен!";
    } else {
        $message = "❌ Ошибка при применении шаблона";
    }
    header("Location: admin.php?tab=templates&msg=" . urlencode($message));
    exit;
}

// Сброс стилей к темной теме
if (isset($_GET['reset_styles']) && $_GET['reset_styles'] === 'dark') {
    $cssFile = 'admin-style.css';
    $defaultDarkVars = [
        'bg' => '#0e0e10',
        'panel' => '#141417',
        'text' => '#ebe9e6',
        'muted' => '#bdb7ad',
        'accent' => '#e19c5a',
        'accent-2' => '#7ac0c4',
        'stroke' => '#2a2a2d',
        'card' => '#18181c',
        'gradient-1' => '#1b1b1f',
        'gradient-2' => '#16161a',
        'header-bg' => 'rgba(14, 14, 16, 0.7)',
        'footer-bg' => 'rgba(20, 20, 22, 0.6)',
        'nav-mobile-bg' => 'rgba(14, 14, 16, 0.95)',
        'btn-primary-dark' => '#cc8748',
        'btn-primary-border' => '#b87536',
        'btn-primary-text' => '#1a120a',
        'input-bg' => '#111114',
        'media-bg' => '#222228',
        'media-bg-2' => '#232329',
        'media-bg-3' => '#1f2630',
        'gallery-bg' => '#1b1b20',
        'overlay-dark' => 'rgba(0, 0, 0, 0.5)',
        'overlay-light' => 'rgba(255, 255, 255, 0.15)',
        'shadow-sm' => 'rgba(0, 0, 0, 0.2)',
        'shadow-md' => 'rgba(0, 0, 0, 0.3)',
        'shadow-lg' => 'rgba(0, 0, 0, 0.5)'
    ];

    if (saveCSSVariables($cssFile, $defaultDarkVars)) {
        $message = "✅ Стили сброшены к темной теме!";
    } else {
        $message = "❌ Ошибка при сбросе стилей";
    }
    header("Location: admin.php?tab=styles&msg=" . urlencode($message));
    exit;
}

// Очистка сессии после успешного импорта
if (isset($_GET['import_done'])) {
    unset($_SESSION['preview_data']);
    $message = "✅ Импорт завершен! Все машины успешно загружены.";
}

// Отмена предпросмотра
if (isset($_GET['cancel_preview'])) {
    unset($_SESSION['preview_data']);
    header("Location: admin.php");
    exit;
}

// Загружаем список машин
$db = loadDB($dbFile);
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Страница редактирования
if (isset($_GET['edit'])) {
    $carId = $_GET['edit'];
    if (isset($db[$carId])) {
        $car = $db[$carId];
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
        <meta charset="UTF-8">
        <title>Редактирование: <?php echo htmlspecialchars($car['title']); ?></title>
        <link rel="stylesheet" href="admin-style.css">
        </head>
        <body>
        <div class="container">
            <h1>Редактирование: <?php echo htmlspecialchars($car['title']); ?></h1>
            <a href="admin.php" class="btn-back">← Назад к списку</a>

            <?php if (isset($_GET['error'])): ?>
            <div style="background: #3a1a1a; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; border-radius: 5px; color: #fff;">
                <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
            <?php endif; ?>

            <form method="post" class="edit-form">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="car_id" value="<?php echo $carId; ?>">

                <div class="form-row">
                    <label>Название:</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($car['title']); ?>" required>
                </div>

                <div class="form-row">
                    <label>Марка:</label>
                    <input type="text" name="make" value="<?php echo htmlspecialchars($car['make']); ?>" required>
                </div>

                <div class="form-row">
                    <label>Модель:</label>
                    <input type="text" name="model" value="<?php echo htmlspecialchars($car['model']); ?>" required>
                </div>

                <div class="form-row">
                    <label>Год:</label>
                    <input type="number" name="year" value="<?php echo htmlspecialchars($car['year']); ?>" required>
                </div>

                <div class="form-row">
                    <label>Цена ($):</label>
                    <input type="number" name="price" value="<?php echo htmlspecialchars($car['price']); ?>" required>
                </div>

                <div class="form-row">
                    <label>Пробег (miles):</label>
                    <input type="number" name="mileage" value="<?php echo htmlspecialchars($car['mileage']); ?>" required>
                </div>

                <div class="form-row">
                    <label>VIN:</label>
                    <input type="text" name="vin" value="<?php echo htmlspecialchars($car['vin']); ?>">
                </div>

                <div class="form-row">
                    <label>Двигатель:</label>
                    <input type="text" name="engine" value="<?php echo htmlspecialchars($car['engine']); ?>">
                </div>

                <div class="form-row">
                    <label>Трансмиссия:</label>
                    <input type="text" name="transmission" value="<?php echo htmlspecialchars($car['transmission']); ?>">
                </div>

                <div class="form-row">
                    <label>Цвет кузова:</label>
                    <input type="text" name="exterior_color" value="<?php echo htmlspecialchars($car['exterior_color']); ?>">
                </div>

                <div class="form-row">
                    <label>Цвет салона:</label>
                    <input type="text" name="interior_color" value="<?php echo htmlspecialchars($car['interior_color']); ?>">
                </div>

                <div class="form-row">
                    <label>Описание:</label>
                    <textarea name="description" rows="10"><?php echo htmlspecialchars($car['description']); ?></textarea>
                </div>

                <!-- Управление фотографиями -->
                <div class="form-row">
                    <label>Фотографии (<?php echo count($car['images']); ?>):</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; margin-top: 10px;">
                        <?php foreach ($car['images'] as $imgIndex => $imgName): ?>
                        <div style="position: relative; background: #1a1a1a; border-radius: 8px; overflow: hidden; border: 2px solid #333;">
                            <img src="assets/images/<?php echo htmlspecialchars($imgName); ?>"
                                 alt="<?php echo htmlspecialchars($car['title']); ?>"
                                 style="width: 100%; height: 150px; object-fit: cover; display: block;">
                            <div style="padding: 8px; background: #222;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #dc3545; font-weight: normal; margin: 0;">
                                    <input type="checkbox" name="delete_images[]" value="<?php echo $imgIndex; ?>" style="width: auto; margin: 0;">
                                    <span style="font-size: 13px;">Удалить</span>
                                </label>
                                <?php if ($imgIndex === 0): ?>
                                <span style="display: block; margin-top: 5px; font-size: 11px; color: #28a745;">Главное фото</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="margin-top: 10px; color: #999; font-size: 14px;">
                        ⚠️ Отметьте фотографии, которые нужно удалить. Первая фотография - главная для карточки.
                    </p>
                </div>

                <button type="submit" class="btn-primary">Сохранить изменения</button>
            </form>
        </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Получаем данные предпросмотра из сессии если есть
if (isset($_SESSION['preview_data']) && !$previewData) {
    $previewData = $_SESSION['preview_data'];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Админка — Управление машинами</title>
<link rel="stylesheet" href="admin-style.css">
<style>
.tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 0;
}
.tab {
    padding: 12px 24px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 16px;
    font-weight: 600;
    color: #666;
    text-decoration: none;
    transition: all 0.3s;
}
.tab:hover {
    color: #0066cc;
}
.tab.active {
    color: #0066cc;
    border-bottom-color: #0066cc;
}
.color-picker-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.color-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}
.color-item label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #1a1a1a;
    text-transform: capitalize;
}
.color-item input[type="text"] {
    padding: 10px 12px;
    border: 1px solid #e0e0e0;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    background: #ffffff;
    transition: border-color 0.2s;
}
.color-item input[type="text"]:focus {
    outline: none;
    border-color: #0066cc;
}
.color-item input[type="color"] {
    width: 50px;
    height: 40px;
    border: 2px solid #e0e0e0;
    border-radius: 6px;
    cursor: pointer;
    transition: border-color 0.2s;
    flex-shrink: 0;
}
.color-item input[type="color"]:hover {
    border-color: #0066cc;
}
.color-preview {
    display: flex;
    align-items: center;
    gap: 10px;
}
.preset-buttons {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    flex-wrap: wrap;
}
.btn-preset {
    padding: 10px 20px;
    border-radius: 5px;
    border: 2px solid;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
}
.btn-light {
    background: #ffffff;
    border-color: #e0e0e0;
    color: #1a1a1a;
}
.btn-dark {
    background: #1a1a1a;
    border-color: #333;
    color: #ffffff;
}
.template-card {
    padding: 20px;
    border-radius: 12px;
    transition: transform 0.2s, box-shadow 0.2s;
    cursor: pointer;
}
.template-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
}
.btn-apply {
    display: block;
    width: 100%;
    padding: 10px 16px;
    background: #0066cc;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    text-align: center;
    font-weight: 600;
    transition: background 0.2s;
}
.btn-apply:hover {
    background: #0052a3;
}
.btn-apply-main {
    display: block;
    width: 100%;
    padding: 10px 16px;
    background: #ff6600;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    text-align: center;
    font-weight: 600;
    transition: background 0.2s;
    margin-top: 8px;
}
.btn-apply-main:hover {
    background: #e55a00;
}
</style>
</head>
<body>
<div class="container">
    <div class="header-bar">
        <h1>Админка сайта</h1>
        <a href="?logout=1" class="logout">Выйти</a>
    </div>

    <!-- Вкладки -->
    <div class="tabs">
        <a href="?tab=cars" class="tab <?php echo (!isset($_GET['tab']) || $_GET['tab'] === 'cars') ? 'active' : ''; ?>">
            🚗 Машины
        </a>
        <a href="?tab=main-themes" class="tab <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'main-themes') ? 'active' : ''; ?>">
            🎨 Темы основного сайта
        </a>
        <a href="?tab=templates" class="tab <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'templates') ? 'active' : ''; ?>">
            ✨ Шаблоны тем админки
        </a>
        <a href="?tab=styles" class="tab <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'styles') ? 'active' : ''; ?>">
            🔧 Настройки стилей админки
        </a>
    </div>

    <?php if ($message): ?>
    <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php
    $currentTab = $_GET['tab'] ?? 'cars';
    ?>

    <?php if ($currentTab === 'main-themes'): ?>
    <!-- ВКЛАДКА ТЕМ ОСНОВНОГО САЙТА -->
    <div class="section">
        <h2>🎨 Темы для основного сайта</h2>
        <p>Выберите тему для основного сайта (изменяет файл <code>style.css</code>). Эти изменения будут видны посетителям сайта!</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 30px;">

            <!-- Светлая классика -->
            <div class="template-card" style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border: 2px solid #e0e0e0;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ffffff; border: 1px solid #e0e0e0;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #1a1a1a;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ff6a00;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ff9140;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #1a1a1a;">☀️ Светлая классика</h3>
                <p style="font-size: 13px; color: #666; margin: 0 0 15px;">Чистый и современный светлый дизайн</p>
                <a href="?apply_main_theme=light" class="btn-apply-main" onclick="return confirm('Применить светлую тему к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Темная классика -->
            <div class="template-card" style="background: linear-gradient(135deg, #0b0e14 0%, #141821 100%); border: 2px solid #2a2a2d; color: #eef1f5;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #0b0e14; border: 1px solid #2a2a2d;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #eef1f5;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ff6a00;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ff9140;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #eef1f5;">🌙 Темная классика</h3>
                <p style="font-size: 13px; color: #aab4c3; margin: 0 0 15px;">Элегантный темный дизайн (по умолчанию)</p>
                <a href="?apply_main_theme=dark" class="btn-apply-main" onclick="return confirm('Применить темную тему к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Океан -->
            <div class="template-card" style="background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%); border: 2px solid #90caf9;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #f0f8ff; border: 1px solid #90caf9;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #0d1b2a;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #0077b6;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #00b4d8;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #0d1b2a;">🌊 Океан</h3>
                <p style="font-size: 13px; color: #4a5f7f; margin: 0 0 15px;">Свежая морская тема с голубыми оттенками</p>
                <a href="?apply_main_theme=ocean" class="btn-apply-main" onclick="return confirm('Применить тему Океан к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Закат -->
            <div class="template-card" style="background: linear-gradient(135deg, #fff5f5 0%, #ffe5e5 100%); border: 2px solid #ffcccc;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #fff5f5; border: 1px solid #ffcccc;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #2d1b1b;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ff6b6b;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ffa07a;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #2d1b1b;">🌅 Закат</h3>
                <p style="font-size: 13px; color: #7f5a5a; margin: 0 0 15px;">Теплая романтичная тема с розово-оранжевыми тонами</p>
                <a href="?apply_main_theme=sunset" class="btn-apply-main" onclick="return confirm('Применить тему Закат к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Лес -->
            <div class="template-card" style="background: linear-gradient(135deg, #f4f9f4 0%, #e8f5e8 100%); border: 2px solid #a5d6a7;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #f4f9f4; border: 1px solid #a5d6a7;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #1b2d1b;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #2d6a2d;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #66bb6a;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #1b2d1b;">🌲 Лес</h3>
                <p style="font-size: 13px; color: #5a7f5a; margin: 0 0 15px;">Природная успокаивающая тема с зелеными оттенками</p>
                <a href="?apply_main_theme=forest" class="btn-apply-main" onclick="return confirm('Применить тему Лес к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Фиолетовая мечта -->
            <div class="template-card" style="background: linear-gradient(135deg, #f8f5ff 0%, #f0e6ff 100%); border: 2px solid #d4b3ff;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #f8f5ff; border: 1px solid #d4b3ff;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #2d1b3d;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #7b2cbf;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #9d4edd;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #2d1b3d;">💜 Фиолетовая мечта</h3>
                <p style="font-size: 13px; color: #7f5a9f; margin: 0 0 15px;">Роскошная тема с фиолетовыми акцентами</p>
                <a href="?apply_main_theme=purple" class="btn-apply-main" onclick="return confirm('Применить тему Фиолетовая мечта к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Монохром -->
            <div class="template-card" style="background: linear-gradient(135deg, #ffffff 0%, #f5f5f5 100%); border: 2px solid #cccccc;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ffffff; border: 1px solid #cccccc;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #000000;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #666666;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #333333;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #000000;">⚫ Монохром</h3>
                <p style="font-size: 13px; color: #666666; margin: 0 0 15px;">Минималистичная черно-белая тема</p>
                <a href="?apply_main_theme=monochrome" class="btn-apply-main" onclick="return confirm('Применить тему Монохром к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Кофейная -->
            <div class="template-card" style="background: linear-gradient(135deg, #faf8f5 0%, #f5f0e8 100%); border: 2px solid #d4c4b0;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #faf8f5; border: 1px solid #d4c4b0;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #2d2520;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #8b6f47;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #a68a64;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #2d2520;">☕ Кофейная</h3>
                <p style="font-size: 13px; color: #7f6f5f; margin: 0 0 15px;">Уютная теплая тема с коричневыми оттенками</p>
                <a href="?apply_main_theme=coffee" class="btn-apply-main" onclick="return confirm('Применить тему Кофейная к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Полночь -->
            <div class="template-card" style="background: linear-gradient(135deg, #0a0e27 0%, #0f1433 100%); border: 2px solid #1e2951; color: #e0e6ff;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #0a0e27; border: 1px solid #1e2951;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #e0e6ff;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #3b82f6;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #60a5fa;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #e0e6ff;">🌌 Полночь</h3>
                <p style="font-size: 13px; color: #8892b0; margin: 0 0 15px;">Глубокая ночная тема с синими акцентами</p>
                <a href="?apply_main_theme=midnight" class="btn-apply-main" onclick="return confirm('Применить тему Полночь к основному сайту?')">Применить к сайту</a>
            </div>

            <!-- Киберпанк -->
            <div class="template-card" style="background: linear-gradient(135deg, #0d0d0d 0%, #1a1a1a 100%); border: 2px solid #333; color: #e0e0e0;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #0d0d0d; border: 1px solid #333;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #e0e0e0;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ff2a6d;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #05d9e8;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #e0e0e0;">🔮 Киберпанк</h3>
                <p style="font-size: 13px; color: #a0a0a0; margin: 0 0 15px;">Футуристическая тема с неоновыми цветами</p>
                <a href="?apply_main_theme=cyberpunk" class="btn-apply-main" onclick="return confirm('Применить тему Киберпанк к основному сайту?')">Применить к сайту</a>
            </div>

        </div>

        <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ff6600;">
            <h3 style="margin-top: 0;">⚠️ Важно</h3>
            <p style="margin: 0; line-height: 1.6;">
                Эти темы изменяют внешний вид <strong>основного сайта</strong> (файл <code>style.css</code>), который видят посетители!<br>
                После применения темы обязательно обновите основную страницу сайта (Ctrl+F5) чтобы увидеть изменения.
            </p>
        </div>
    </div>

    <?php elseif ($currentTab === 'templates'): ?>
    <!-- ВКЛАДКА ШАБЛОНОВ ТЕМ АДМИНКИ -->
    <div class="section">
        <h2>✨ Готовые шаблоны цветовых тем для админки</h2>
        <p>Выберите готовый шаблон темы для админки. Эти изменения влияют только на файл <code>styles.css</code> (админка).</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 30px;">

            <!-- Светлая классика -->
            <div class="template-card" style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border: 2px solid #e0e0e0;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ffffff; border: 1px solid #e0e0e0;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #1a1a1a;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #e19c5a;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #7ac0c4;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #1a1a1a;">☀️ Светлая классика</h3>
                <p style="font-size: 13px; color: #666; margin: 0 0 15px;">Чистый и современный светлый дизайн с золотыми акцентами</p>
                <a href="?apply_template=light" class="btn-apply" onclick="return confirm('Применить шаблон Светлая классика?')">Применить тему</a>
            </div>

            <!-- Темная классика -->
            <div class="template-card" style="background: linear-gradient(135deg, #0e0e10 0%, #18181c 100%); border: 2px solid #2a2a2d; color: #ebe9e6;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #0e0e10; border: 1px solid #2a2a2d;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ebe9e6;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #e19c5a;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #7ac0c4;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #ebe9e6;">🌙 Темная классика</h3>
                <p style="font-size: 13px; color: #bdb7ad; margin: 0 0 15px;">Элегантный темный дизайн для комфортного просмотра</p>
                <a href="?apply_template=dark" class="btn-apply" onclick="return confirm('Применить шаблон Темная классика?')">Применить тему</a>
            </div>

            <!-- Океан -->
            <div class="template-card" style="background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%); border: 2px solid #90caf9;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #f0f8ff; border: 1px solid #90caf9;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #0d1b2a;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #0077b6;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #00b4d8;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #0d1b2a;">🌊 Океан</h3>
                <p style="font-size: 13px; color: #4a5f7f; margin: 0 0 15px;">Свежая морская тема с голубыми оттенками</p>
                <a href="?apply_template=ocean" class="btn-apply" onclick="return confirm('Применить шаблон Океан?')">Применить тему</a>
            </div>

            <!-- Закат -->
            <div class="template-card" style="background: linear-gradient(135deg, #fff5f5 0%, #ffe5e5 100%); border: 2px solid #ffcccc;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #fff5f5; border: 1px solid #ffcccc;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #2d1b1b;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ff6b6b;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ffa07a;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #2d1b1b;">🌅 Закат</h3>
                <p style="font-size: 13px; color: #7f5a5a; margin: 0 0 15px;">Теплая романтичная тема с розово-оранжевыми тонами</p>
                <a href="?apply_template=sunset" class="btn-apply" onclick="return confirm('Применить шаблон Закат?')">Применить тему</a>
            </div>

            <!-- Лес -->
            <div class="template-card" style="background: linear-gradient(135deg, #f4f9f4 0%, #e8f5e8 100%); border: 2px solid #a5d6a7;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #f4f9f4; border: 1px solid #a5d6a7;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #1b2d1b;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #2d6a2d;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #66bb6a;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #1b2d1b;">🌲 Лес</h3>
                <p style="font-size: 13px; color: #5a7f5a; margin: 0 0 15px;">Природная успокаивающая тема с зелеными оттенками</p>
                <a href="?apply_template=forest" class="btn-apply" onclick="return confirm('Применить шаблон Лес?')">Применить тему</a>
            </div>

            <!-- Фиолетовая мечта -->
            <div class="template-card" style="background: linear-gradient(135deg, #f8f5ff 0%, #f0e6ff 100%); border: 2px solid #d4b3ff;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #f8f5ff; border: 1px solid #d4b3ff;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #2d1b3d;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #7b2cbf;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #9d4edd;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #2d1b3d;">💜 Фиолетовая мечта</h3>
                <p style="font-size: 13px; color: #7f5a9f; margin: 0 0 15px;">Роскошная тема с фиолетовыми акцентами</p>
                <a href="?apply_template=purple" class="btn-apply" onclick="return confirm('Применить шаблон Фиолетовая мечта?')">Применить тему</a>
            </div>

            <!-- Монохром -->
            <div class="template-card" style="background: linear-gradient(135deg, #ffffff 0%, #f5f5f5 100%); border: 2px solid #cccccc;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #ffffff; border: 1px solid #cccccc;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #000000;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #666666;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #333333;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #000000;">⚫ Монохром</h3>
                <p style="font-size: 13px; color: #666666; margin: 0 0 15px;">Минималистичная черно-белая тема без цветных акцентов</p>
                <a href="?apply_template=monochrome" class="btn-apply" onclick="return confirm('Применить шаблон Монохром?')">Применить тему</a>
            </div>

            <!-- Кофейная -->
            <div class="template-card" style="background: linear-gradient(135deg, #faf8f5 0%, #f5f0e8 100%); border: 2px solid #d4c4b0;">
                <div class="template-preview">
                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #faf8f5; border: 1px solid #d4c4b0;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #2d2520;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #8b6f47;"></div>
                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #a68a64;"></div>
                    </div>
                </div>
                <h3 style="margin: 12px 0 8px; color: #2d2520;">☕ Кофейная</h3>
                <p style="font-size: 13px; color: #7f6f5f; margin: 0 0 15px;">Уютная теплая тема с коричневыми оттенками</p>
                <a href="?apply_template=coffee" class="btn-apply" onclick="return confirm('Применить шаблон Кофейная?')">Применить тему</a>
            </div>

        </div>

        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #0066cc;">
            <h3 style="margin-top: 0;">💡 Совет</h3>
            <p style="margin: 0; line-height: 1.6;">
                После применения шаблона вы можете дополнительно настроить цвета во вкладке "🔧 Настройки стилей админки".
                Эти темы влияют только на админку, не на основной сайт!
            </p>
        </div>
    </div>

    <?php elseif ($currentTab === 'styles'): ?>
    <!-- ВКЛАДКА НАСТРОЕК СТИЛЕЙ -->
    <div class="section">
        <h2>🔧 Настройка цветовой схемы админки</h2>
        <p>Здесь вы можете изменить цвета админки. Изменения применяются к файлу <code>styles.css</code>.</p>

        <?php
        $cssVars = getCSSVariables('styles.css');
        $varLabels = [
            'bg' => 'Фон страницы',
            'panel' => 'Фон панелей',
            'text' => 'Основной текст',
            'muted' => 'Приглушенный текст',
            'accent' => 'Акцентный цвет 1 (золотой)',
            'accent-2' => 'Акцентный цвет 2 (бирюзовый)',
            'stroke' => 'Границы и линии',
            'card' => 'Фон карточек',
            'gradient-1' => 'Градиент 1 (фон)',
            'gradient-2' => 'Градиент 2 (фон)',
            'header-bg' => 'Фон шапки (прозрачный)',
            'footer-bg' => 'Фон футера (прозрачный)',
            'nav-mobile-bg' => 'Фон мобильного меню',
            'btn-primary-dark' => 'Кнопка (темный оттенок)',
            'btn-primary-border' => 'Кнопка (граница)',
            'btn-primary-text' => 'Кнопка (текст)',
            'input-bg' => 'Фон полей ввода',
            'media-bg' => 'Фон медиа элементов 1',
            'media-bg-2' => 'Фон медиа элементов 2',
            'media-bg-3' => 'Фон медиа элементов 3',
            'gallery-bg' => 'Фон галереи',
            'overlay-dark' => 'Темный оверлей (прозрачный)',
            'overlay-light' => 'Светлый оверлей (прозрачный)',
            'shadow-sm' => 'Тень малая (прозрачная)',
            'shadow-md' => 'Тень средняя (прозрачная)',
            'shadow-lg' => 'Тень большая (прозрачная)'
        ];
        ?>

        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="save_styles">

            <div class="color-picker-group">
                <?php foreach ($cssVars as $varName => $varValue): ?>
                <div class="color-item">
                    <label><?php echo $varLabels[$varName] ?? ucfirst($varName); ?></label>
                    <div class="color-preview">
                        <input type="text"
                               name="var_<?php echo htmlspecialchars($varName); ?>"
                               value="<?php echo htmlspecialchars($varValue); ?>"
                               id="text_<?php echo htmlspecialchars($varName); ?>"
                               placeholder="#ffffff или rgba()"
                               style="flex: 1;">
                        <?php
                        // Пытаемся извлечь hex цвет для color picker
                        $hexColor = $varValue;

                        // Если это rgba, пытаемся конвертировать в hex (для простых случаев)
                        if (preg_match('/rgba?\((\d+),\s*(\d+),\s*(\d+)/', $varValue, $matches)) {
                            $r = str_pad(dechex($matches[1]), 2, '0', STR_PAD_LEFT);
                            $g = str_pad(dechex($matches[2]), 2, '0', STR_PAD_LEFT);
                            $b = str_pad(dechex($matches[3]), 2, '0', STR_PAD_LEFT);
                            $hexColor = "#$r$g$b";
                        } elseif (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hexColor)) {
                            $hexColor = '#ffffff';
                        }
                        ?>
                        <input type="color"
                               value="<?php echo htmlspecialchars($hexColor); ?>"
                               id="color_<?php echo htmlspecialchars($varName); ?>"
                               onchange="updateColorFromPicker('<?php echo htmlspecialchars($varName); ?>')">
                    </div>
                    <small style="color: #999; font-size: 11px; display: block; margin-top: 4px;">
                        <?php echo strpos($varName, 'shadow') !== false || strpos($varName, 'overlay') !== false || strpos($varName, 'bg') !== false && strpos($varValue, 'rgba') !== false ? 'Поддерживает rgba' : 'HEX формат'; ?>
                    </small>
                </div>
                <?php endforeach; ?>
            </div>
<label style="display:block;margin:6px 0">
  Hover shade, % (насколько темнее кнопка при наведении)
  <input type="number" name="hover_pct" value="18" min="0" max="60" step="1">
</label>

<label style="display:block;margin:6px 0">
  Glow opacity, % (интенсивность свечения)
  <input type="number" name="glow_alpha" value="40" min="0" max="80" step="1">
</label>

            <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
                <button type="submit" class="btn-primary" style="font-size: 18px; padding: 15px 30px;">
                    💾 Сохранить изменения
                </button>

                <div class="preset-buttons">
                    <p style="width: 100%; margin: 0 0 10px 0; font-weight: 600; color: #666;">Быстрые пресеты:</p>
                    <a href="?reset_styles=light" class="btn-preset btn-light" onclick="return confirm('Применить светлую тему?')">
                        ☀️ Светлая тема
                    </a>
                    <a href="?reset_styles=dark" class="btn-preset btn-dark" onclick="return confirm('Применить темную тему?')">
                        🌙 Темная тема
                    </a>
                </div>
            </div>
        </form>

        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #0066cc;">
            <h3 style="margin-top: 0;">ℹ️ Информация</h3>
            <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                <li>Используйте HEX-формат цветов: <code>#ffffff</code> или rgba: <code>rgba(255, 255, 255, 0.9)</code></li>
                <li>Color picker обновляет только базовый цвет (для rgba сохраняется прозрачность)</li>
                <li>Изменения применяются только к админке (файл styles.css)</li>
                <li>Пресеты позволяют быстро переключаться между темной и светлой темами</li>
            </ul>
        </div>
    </div>

    <script>
    function updateColorFromPicker(varName) {
        const colorPicker = document.getElementById('color_' + varName);
        const textInput = document.getElementById('text_' + varName);
        const currentValue = textInput.value;

        // Если текущее значение rgba, сохраняем альфа-канал
        const rgbaMatch = currentValue.match(/rgba?\((\d+),\s*(\d+),\s*(\d+),?\s*([\d.]*)\)/);

        if (rgbaMatch && rgbaMatch[4]) {
            // Есть альфа-канал, сохраняем его
            const alpha = rgbaMatch[4];
            const hex = colorPicker.value;
            const r = parseInt(hex.substr(1, 2), 16);
            const g = parseInt(hex.substr(3, 2), 16);
            const b = parseInt(hex.substr(5, 2), 16);
            textInput.value = `rgba(${r}, ${g}, ${b}, ${alpha})`;
        } else {
            // Простой hex цвет
            textInput.value = colorPicker.value;
        }
    }

    // Синхронизация при изменении текстового поля
    document.addEventListener('DOMContentLoaded', function() {
        const textInputs = document.querySelectorAll('input[id^="text_"]');
        textInputs.forEach(function(textInput) {
            textInput.addEventListener('input', function() {
                const varName = this.id.replace('text_', '');
                const colorPicker = document.getElementById('color_' + varName);
                const value = this.value.trim();

                // Если введен hex, обновляем color picker
                if (value.match(/^#[0-9A-Fa-f]{6}$/)) {
                    colorPicker.value = value;
                }
                // Если введен rgb/rgba, извлекаем базовый цвет для picker
                else if (value.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)) {
                    const match = value.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                    const r = parseInt(match[1]).toString(16).padStart(2, '0');
                    const g = parseInt(match[2]).toString(16).padStart(2, '0');
                    const b = parseInt(match[3]).toString(16).padStart(2, '0');
                    colorPicker.value = `#${r}${g}${b}`;
                }
            });
        });
    });
    </script>

    <?php else: ?>
    <!-- ВКЛАДКА УПРАВЛЕНИЯ МАШИНАМИ -->
    <?php if ($previewData): ?>
    <!-- ПРЕДПРОСМОТР CSV -->
    <div class="section preview-section">
        <h2>Предпросмотр CSV (<?php echo count($previewData); ?> машин)</h2>
        <p>Проверьте данные перед загрузкой на сайт:</p>

        <div class="preview-grid">
            <?php foreach ($previewData as $car): ?>
            <div class="preview-card">
                <?php if ($car['image_url']): ?>
                <div class="preview-image" style="background-image: url('<?php echo htmlspecialchars($car['image_url']); ?>')"></div>
                <?php else: ?>
                <div class="preview-image no-image">Нет изображения</div>
                <?php endif; ?>
                <div class="preview-info">
                    <h3><?php echo htmlspecialchars($car['title']); ?></h3>
                    <p class="preview-price">$<?php echo number_format(floatval(str_replace(',', '', $car['price'])), 0, '.', ','); ?></p>
                    <p class="preview-meta">
                        <?php echo htmlspecialchars($car['year']); ?> •
                        <?php echo number_format(intval(str_replace(',', '', $car['mileage'])), 0, '.', ','); ?> miles
                    </p>
                    <p class="preview-details">
                        <?php echo htmlspecialchars($car['engine']); ?><br>
                        <?php echo htmlspecialchars($car['transmission']); ?>
                    </p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Прогресс-бар -->
        <div id="progress-container" style="display:none; margin-top: 20px;">
            <div class="progress-bar">
                <div id="progress-fill" class="progress-fill"></div>
            </div>
            <p id="progress-text" style="text-align:center; margin-top:10px;">Обработка...</p>
            <div id="progress-log" class="progress-log"></div>
        </div>

        <div style="margin-top: 20px;">
            <button id="start-import" class="btn-confirm">✓ Загрузить все машины на сайт</button>
            <a href="admin.php?cancel_preview=1" class="btn-cancel">✕ Отменить</a>
        </div>
    </div>

    <script>
    document.getElementById('start-import').addEventListener('click', function() {
        const totalCars = <?php echo count($previewData); ?>;
        let currentIndex = 0;

        // Скрываем кнопки, показываем прогресс
        this.style.display = 'none';
        document.querySelector('.btn-cancel').style.display = 'none';
        document.getElementById('progress-container').style.display = 'block';

        const progressFill = document.getElementById('progress-fill');
        const progressText = document.getElementById('progress-text');
        const progressLog = document.getElementById('progress-log');

        function processNext() {
            if (currentIndex >= totalCars) {
                progressText.textContent = '✅ Импорт завершен! Обработано ' + totalCars + ' машин.';
                progressLog.innerHTML += '<p style="color:#28a745;font-weight:bold;">✅ Все машины успешно загружены!</p>';
                setTimeout(() => {
                    window.location.href = 'admin.php?import_done=1';
                }, 2000);
                return;
            }

            const formData = new FormData();
            formData.append('ajax_process_batch', '1');
            formData.append('batch_index', currentIndex);

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentIndex++;
                    const percent = Math.round((currentIndex / totalCars) * 100);
                    progressFill.style.width = percent + '%';
                    progressText.textContent = 'Обработано ' + currentIndex + ' из ' + totalCars + ' (' + percent + '%)';

                    if (data.message) {
                        progressLog.innerHTML += '<p>' + data.message + '</p>';
                        progressLog.scrollTop = progressLog.scrollHeight;
                    }

                    if (!data.done) {
                        processNext();
                    }
                } else {
                    progressLog.innerHTML += '<p style="color:#dc3545;">❌ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</p>';
                    currentIndex++;
                    processNext();
                }
            })
            .catch(error => {
                progressLog.innerHTML += '<p style="color:#dc3545;">❌ Ошибка сети: ' + error + '</p>';
                currentIndex++;
                processNext();
            });
        }

        processNext();
    });
    </script>

    <?php else: ?>
    <!-- CSV ИМПОРТ -->
    <div class="section">
        <h2>CSV Импорт</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit" class="btn-primary">Загрузить CSV для предпросмотра</button>
        </form>
    </div>

    <!-- СПИСОК МАШИН -->
    <div class="section">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Список машин (<?php echo count($db); ?>)</h2>
            <div class="action-buttons">
                <?php if (count($db) > 0): ?>
                <a href="?regenerate_all=true" class="btn-regenerate" onclick="return confirm('Regenerate all car pages? This will apply the latest template to all existing cars.')">
                    🔄 Regenerate All Pages
                </a>
                <button id="delete-all-btn" class="btn-delete-all" onclick="confirmDeleteAll()">
                    Удалить все машины
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="cars-grid">
            <?php foreach ($db as $carId => $car): ?>
            <div class="car-card">
                <img src="assets/images/<?php echo $car['images'][0]; ?>" alt="<?php echo htmlspecialchars($car['title']); ?>">
                <div class="car-info">
                    <h3><?php echo htmlspecialchars($car['title']); ?></h3>
                    <p class="car-price">$<?php echo number_format(floatval(str_replace(',', '', $car['price'])), 0, '.', ','); ?></p>
                    <p class="car-meta"><?php echo htmlspecialchars($car['year']); ?> • <?php echo number_format(intval(str_replace(',', '', $car['mileage'])), 0, '.', ','); ?> miles</p>
                    <div class="car-actions">
                        <a href="cars/<?php echo $car['slug']; ?>.html" target="_blank" class="btn-view">Просмотр</a>
                        <a href="?edit=<?php echo $carId; ?>" class="btn-edit">Редактировать</a>
                        <a href="?delete=<?php echo $carId; ?>" class="btn-delete" onclick="return confirm('Удалить эту машину?')">Удалить</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // конец вкладки cars ?>
</div>

<?php if ($currentTab === 'cars'): ?>
<script>
function confirmDeleteAll() {
    const totalCars = <?php echo count($db); ?>;

    if (confirm(`⚠️ ВНИМАНИЕ!\n\nВы действительно хотите удалить ВСЕ машины (${totalCars} шт.)?\n\nЭто действие необратимо!\n\n• Будут удалены все HTML страницы из папки cars/\n• Будут удалены все карточки из inventory.html\n• База данных будет полностью очищена\n\nНажмите ОК для подтверждения или Отмена для отмены.`)) {
        if (confirm(`🔴 ПОСЛЕДНЕЕ ПРЕДУПРЕЖДЕНИЕ!\n\nВы точно уверены что хотите удалить ${totalCars} машин?\n\nДля окончательного подтверждения нажмите ОК.`)) {
            window.location.href = 'admin.php?delete_all=confirm';
        }
    }
}
</script>
<?php endif; ?>
</body>
</html>
