<?php
// === ФУНКЦИИ ===

// Загрузка БД
function loadDB($file) {
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    return json_decode($json, true) ?: [];
}

// Сохранение БД
function saveDB($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Чтение CSS переменных из styles.css
function getCSSVariables($file) {
    if (!file_exists($file)) {
        return [];
    }

    $css = file_get_contents($file);
    $variables = [];

    // Ищем блок :root { ... }
    if (preg_match('/:root\s*\{([^}]+)\}/s', $css, $matches)) {
        $rootContent = $matches[1];

        // Парсим каждую переменную
        preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/i', $rootContent, $varMatches, PREG_SET_ORDER);

        foreach ($varMatches as $match) {
            $varName = $match[1];
            $varValue = trim($match[2]);

            // Убираем комментарии
            $varValue = preg_replace('/\/\*.*?\*\//', '', $varValue);
            $varValue = trim($varValue);

            $variables[$varName] = $varValue;
        }
    }

    return $variables;
}

// Сохранение CSS переменных в styles.css
function saveCSSVariables($file, $variables) {
    if (!file_exists($file)) {
        return false;
    }

    $css = file_get_contents($file);

    // Создаём новый блок :root
    $newRoot = ":root {\n";
    foreach ($variables as $name => $value) {
        $newRoot .= "  --{$name}: {$value};\n";
    }
    $newRoot .= "}";

    // Заменяем старый блок :root новым
    $css = preg_replace('/:root\s*\{[^}]+\}/s', $newRoot, $css);

    file_put_contents($file, $css);
    return true;
}

// Применение темы к основному сайту (style.css)
function applyThemeToMainSite($themeVars, $fixTemplateOnce = true) {
    $styleFile = 'assets/css/styles.css';
    if (!file_exists($styleFile)) {
        $log_message = "Timestamp: " . date("Y-m-d H:i:s") . "\n";
        $log_message .= "Error: CSS file not found at path: " . $styleFile . "\n";
        $log_message .= "Current working directory: " . getcwd() . "\n";
        file_put_contents('admin_error_log.txt', $log_message, FILE_APPEND);
        return false;
    }

    $css = file_get_contents($styleFile);

    // --- базовые значения (из пресета админки или дефолты) ---
    $bg      = isset($themeVars['bg'])        ? $themeVars['bg']        : '#ffffff';
    $panel   = isset($themeVars['panel'])     ? $themeVars['panel']     : '#f8f9fa';
    $text    = isset($themeVars['text'])      ? $themeVars['text']      : '#1a1a1a';
    $muted   = isset($themeVars['muted'])     ? $themeVars['muted']     : '#666666';
    $brand   = isset($themeVars['accent'])    ? $themeVars['accent']    : '#ff6a00';
    $brand2  = isset($themeVars['accent-2'])  ? $themeVars['accent-2']  : '#ff9140';

    // Конвертируем цвет панели в RGB для создания полупрозрачных версий
    $hex_panel = str_replace('#', '', $panel);
    if (strlen($hex_panel) == 3) {
        $r_panel = hexdec(substr($hex_panel, 0, 1) . substr($hex_panel, 0, 1));
        $g_panel = hexdec(substr($hex_panel, 1, 1) . substr($hex_panel, 1, 1));
        $b_panel = hexdec(substr($hex_panel, 2, 1) . substr($hex_panel, 2, 1));
    } else {
        $r_panel = hexdec(substr($hex_panel, 0, 2));
        $g_panel = hexdec(substr($hex_panel, 2, 2));
        $b_panel = hexdec(substr($hex_panel, 4, 2));
    }
    $panel_rgb = "$r_panel,$g_panel,$b_panel";


    // --- производные/алиасы для страниц (цены/заголовки/кнопки/формы/глоу) ---
    $card      = isset($themeVars['card'])       ? $themeVars['card']       : $panel;
    $stroke    = isset($themeVars['stroke'])     ? $themeVars['stroke']     : 'rgba(0,0,0,.12)';
    $inputBg   = isset($themeVars['input-bg'])   ? $themeVars['input-bg']   : '#ffffff';
    $mediaBg   = isset($themeVars['media-bg'])   ? $themeVars['media-bg']   : '#ece6ff';
    $mediaBg2  = isset($themeVars['media-bg-2']) ? $themeVars['media-bg-2'] : '#e9e1ff';
    $mediaBg3  = isset($themeVars['media-bg-3']) ? $themeVars['media-bg-3'] : '#e3d9ff';

    $btnText   = isset($themeVars['btn-primary-text'])   ? $themeVars['btn-primary-text']   : '#ffffff';
    $btnDark   = isset($themeVars['btn-primary-dark'])   ? $themeVars['btn-primary-dark']   : '#6a22a6';
    $btnBorder = isset($themeVars['btn-primary-border']) ? $themeVars['btn-primary-border'] : '#6822a2';

    // Умная генерация фона для хедера/футера на основе цвета панели
    $headerBg  = isset($themeVars['header-bg'])    ? $themeVars['header-bg']    : 'rgba(' . $panel_rgb . ',.95)';
    $footerBg  = isset($themeVars['footer-bg'])    ? $themeVars['footer-bg']    : 'rgba(' . $panel_rgb . ',.75)';
    $navMobBg  = isset($themeVars['nav-mobile-bg'])? $themeVars['nav-mobile-bg']: 'rgba(' . $panel_rgb . ',.98)';
    $grad1     = isset($themeVars['gradient-1'])   ? $themeVars['gradient-1']   : '#f5ecff';
    $grad2     = isset($themeVars['gradient-2'])   ? $themeVars['gradient-2']   : '#ece0ff';

    // Convert brand color to RGB for the glow effect
    $hex = str_replace('#', '', $brand);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    $glow_rgb = "$r,$g,$b";

    $glow      = '0 0 28px rgba(' . $glow_rgb . ',.35)';

    // --- единый набор vars для сайта ---
    $mainSiteVars = array(
        // базовые
        '--bg'       => $bg,
        '--surface'  => $panel,
        '--text'     => $text,
        '--muted'    => $muted,
        '--brand'    => $brand,
        '--brand2'   => $brand2,
        '--shadow'   => '0 10px 28px rgba(0,0,0,.35)',
        '--maxw'     => '1200px',

        // алиасы/доп.ключи (чтобы красились контакты/футер/цены/кнопки/карточки/глоу)
        '--card'                => $card,
        '--stroke'              => $stroke,
        '--input-bg'            => $inputBg,
        '--media-bg'            => $mediaBg,
        '--media-bg-2'          => $mediaBg2,
        '--media-bg-3'          => $mediaBg3,
        '--gallery-bg'          => $mediaBg,

        '--btn-primary-text'    => $btnText,
        '--btn-primary-dark'    => $btnDark,
        '--btn-primary-border'  => $btnBorder,

        '--heading'             => $text,
        '--price'               => $brand,
        '--btn'                 => $brand,
        '--btn-text'            => $btnText,
        '--glow-shadow'         => $glow,

        '--header-bg'           => $headerBg,
        '--footer-bg'           => $footerBg,
        '--nav-mobile-bg'       => $navMobBg,
        '--gradient-1'          => $grad1,
        '--gradient-2'          => $grad2
    );

    // соберём новый :root
    $newRoot = ':root{';
    foreach ($mainSiteVars as $name => $value) {
        $newRoot .= $name . ':' . $value . ';';
    }
    $newRoot .= '}';

    // удаляем ВСЕ старые :root и вставляем новый в самое начало файла
    $css = preg_replace('/:root\s*\{[^}]*\}\s*/s', '', $css);
    $css = $newRoot . "\n\n" . ltrim($css);
    file_put_contents($styleFile, $css);

    // фиксим template.html: тянет стили сайта и динамический CSS
    if ($fixTemplateOnce && file_exists('template.html')) {
        $tpl = file_get_contents('template.html');
        if (strpos($tpl, 'href="../theme.css.php"') === false) {
            $tpl = preg_replace('~(<link[^>]+href="\.\./style\.css"[^>]*>)~i', '$1' . "\n" . '<link rel="stylesheet" href="../theme.css.php">', $tpl, 1);
        }
        file_put_contents('template.html', $tpl);
    }

        // сохраняем последний пресет для динамического CSS (если используешь theme.css.php)
    @file_put_contents(
        'current_theme.json',
        json_encode($themeVars, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    // Bust the browser cache by updating a version file
    file_put_contents('theme_version.txt', time());

    return true;
}

// Сжатие изображения
function compressImage($sourcePath, $maxWidth = 1920, $quality = 85) {
    if (!file_exists($sourcePath)) return false;

    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) return false;

    $mime = $imageInfo['mime'];
    $width = $imageInfo[0];
    $height = $imageInfo[1];

    // Создаем изображение из источника
    switch ($mime) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $source = imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }

    if (!$source) return false;

    // Вычисляем новые размеры если изображение больше maxWidth
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = intval($height * ($maxWidth / $width));
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    // Создаем новое изображение с нужными размерами
    $dest = imagecreatetruecolor($newWidth, $newHeight);

    // Сохраняем прозрачность для PNG
    if ($mime === 'image/png') {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
    }

    // Копируем с изменением размера
    imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Сохраняем в JPEG для уменьшения размера
    $newPath = preg_replace('/\.\w+$/', '.jpg', $sourcePath);
    imagejpeg($dest, $newPath, $quality);

    imagedestroy($source);
    imagedestroy($dest);

    // Удаляем оригинал если расширение изменилось
    if ($newPath !== $sourcePath) {
        @unlink($sourcePath);
    }

    return basename($newPath);
}

// Скачивание изображений
function downloadImages($imageUrls, $targetFolder) {
    $downloaded = [];
    if (empty($imageUrls)) return $downloaded;

    $urls = json_decode($imageUrls, true);
    if (!$urls || !is_array($urls)) return $downloaded;

    foreach ($urls as $index => $url) {
        $url = trim($url);
        if (empty($url)) continue;

        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($ext)) $ext = 'jpg';

        $filename = uniqid('img_') . '_' . $index . '.' . $ext;
        $filepath = $targetFolder . '/' . $filename;

        $ch = curl_init($url);
        $fp = fopen($filepath, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($httpCode == 200 && file_exists($filepath) && filesize($filepath) > 0) {
            $compressedFilename = compressImage($filepath);
            if ($compressedFilename) {
                $downloaded[] = $compressedFilename;
            } else {
                $downloaded[] = $filename; // Fallback to original if compression fails
            }
        } else {
            // Log error
            $logMessage = "Failed to download image from URL: $url\n";
            $logMessage .= "HTTP Code: $httpCode\n";
            $logMessage .= "cURL Error: $error\n";
            file_put_contents('image_download_log.txt', $logMessage, FILE_APPEND);
            @unlink($filepath);
        }
    }

    return $downloaded;
}

// Генерация страницы из данных
function generateCarPage($carData) {
    $template = file_get_contents('template.html');

    // Загрузка хедера и футера
    $headerContent = file_get_contents('templates/header.html');
    $footerContent = file_get_contents('templates/footer.html');

    // Замена плейсхолдеров
    $template = str_replace('<header id="header-placeholder"></header>', $headerContent, $template);
    $template = str_replace('<footer id="footer-placeholder"></footer>', $footerContent, $template);

    // Basic fields
    $title = htmlspecialchars($carData['title']);
    $price = '$' . number_format(floatval(str_replace(',', '', $carData['price'])), 0, '.', ',');

    // Image gallery
    $images = $carData['images'];
    $mainImageSrc = '../assets/images/' . htmlspecialchars($images[0]);
    $imageCount = count($images);

    $thumbnailsHtml = "";
    foreach ($images as $i => $img) {
        $imgPath = '../assets/images/' . htmlspecialchars($img);
        $activeClass = ($i == 0) ? "active" : "";
        $thumbnailsHtml .= "<div class='thumbnail-item " . $activeClass . "' data-index='$i'>\n";
        $thumbnailsHtml .= "  <img src='$imgPath' alt='$title thumbnail'>\n";
        $thumbnailsHtml .= "</div>\n";
    }

    // Create a JS-safe array of image filenames for the new gallery script
    $imageSourcesJsArray = implode(",", array_map(function($img) { return '"' . addslashes($img) . '"'; }, $images));

    // Description list
    $descriptionListHtml = "";
    $descLines = preg_split('/\r\n|\r|\n/', $carData['description']);
    foreach ($descLines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            $descriptionListHtml .= "<li>" . htmlspecialchars($line) . "</li>\n";
        }
    }

    // Specifications Grid
    $specsMap = [
        'Year' => $carData['year'],
        'Make' => $carData['make'],
        'Model' => $carData['model'],
        'Mileage' => number_format(intval(str_replace(',', '', $carData['mileage'])), 0, '.', ',') . ' mi',
        'Engine' => $carData['engine'],
        'Transmission' => $carData['transmission'],
        'Drive' => $carData['drive_type'] ?? 'N/A', // Assuming 'drive_type' might exist
        'Exterior Color' => $carData['exterior_color'],
        'Interior Color' => $carData['interior_color'],
        'Fuel' => $carData['fuel_type'] ?? 'N/A', // Assuming 'fuel_type' might exist
        'VIN' => $carData['vin'],
        'Title' => $carData['title_status'] ?? 'Clear' // Assuming 'title_status' might exist
    ];

    $specsHtml = "";
    foreach ($specsMap as $label => $value) {
        if (!empty($value)) {
            $specsHtml .= "<div class='spec-item'><div class='label'>" . htmlspecialchars($label) . "</div><div class='value'>" . htmlspecialchars($value) . "</div></div>\n";
        }
    }

    // Replacements array
    $replace = [
        '{{TITLE}}' => $title,
        '{{H1}}' => $title,
        '{{PRICE}}' => $price,
        '{{MAIN_IMAGE_SRC}}' => $mainImageSrc,
        '{{IMAGE_COUNT}}' => $imageCount,
        '{{THUMBNAILS}}' => $thumbnailsHtml,
        '{{IMAGE_SOURCES_JS_ARRAY}}' => $imageSourcesJsArray,
        '{{DESCRIPTION_LIST}}' => $descriptionListHtml,
        '{{SPECS}}' => $specsHtml,
        // Deprecated placeholders, kept for safety, can be removed later
        '{{MAINIMAGE}}' => '',
        '{{IMAGES_THUMBS}}' => '',
        '{{SHORTDESC}}' => '',
        '{{SHORTDESC_TEXT}}' => '',
        '{{SLUG}}' => $carData['slug']
    ];

    $pageHtml = strtr($template, $replace);
    $filename = $carData['slug'] . '.html';

    if (!is_dir('cars')) {
        mkdir('cars', 0755, true);
    }

    file_put_contents("cars/$filename", $pageHtml);

    return $filename;
}

// Генерация карточки
function generateCarCard($carData) {
    $title = $carData['title'];
    $year = $carData['year'];
    $price = floatval(str_replace(',', '', $carData['price']));
    $mileage = intval(str_replace(',', '', $carData['mileage']));
    $mainImage = 'assets/images/' . $carData['images'][0];
    $filename = $carData['slug'] . '.html';

    $priceFormatted = number_format($price, 0, '.', ',');
    $mileageFormatted = number_format($mileage, 0, '.', ',');

    $meta = "$year • $mileageFormatted miles • {$carData['transmission']}";

    $yearNum = intval($year);
    $era = '60s';
    if ($yearNum >= 1950 && $yearNum < 1960) $era = '50s';
    elseif ($yearNum >= 1960 && $yearNum < 1970) $era = '60s';
    elseif ($yearNum >= 1970 && $yearNum < 1980) $era = '70s';
    elseif ($yearNum >= 1980 && $yearNum < 1990) $era = '80s';

    $keywords = strtolower("$year {$carData['make']} {$carData['model']}");

    $cardHtml = '<a href="cars/' . $filename . '" class="vehicle-card" data-era="' . $era . '" data-keywords="' . $keywords . '" data-car-id="' . $carData['id'] . '">' . "\n";
    $cardHtml .= '  <div class="vehicle-media" style="background-image: url(\'' . $mainImage . '\');"></div>' . "\n";
    $cardHtml .= '  <div class="vehicle-body">' . "\n";
    $cardHtml .= '    <h3>' . htmlspecialchars($title) . '</h3>' . "\n";
    $cardHtml .= '    <p>' . htmlspecialchars($meta) . '</p>' . "\n";
    $cardHtml .= '    <div class="vehicle-meta">' . "\n";
    $cardHtml .= '      <span class="price">&#36;' . $priceFormatted . '</span>' . "\n";
    $cardHtml .= '    </div>' . "\n";
    $cardHtml .= '  </div>' . "\n";
    $cardHtml .= '</a>' . "\n";

    return $cardHtml;
}

// Обновление публичных страниц (inventory.html и index.html)
function updatePublicListings() {
    $db = loadDB('cars_db.json');
    $inventoryTemplate = file_get_contents('inventory.template.html');
    $indexTemplate = file_get_contents('index.template.html');

    // Загрузка хедера и футера
    $headerContent = file_get_contents('templates/header.html');
    $footerContent = file_get_contents('templates/footer.html');

    // Сортируем машины по убыванию (новые вверху)
    krsort($db);

    $allCardsHtml = "";
    foreach ($db as $car) {
        $allCardsHtml .= generateCarCard($car);
    }

    // Обновляем inventory.html
    $newInventoryHtml = str_replace('{{CAR_GRID_PLACEHOLDER}}', $allCardsHtml, $inventoryTemplate);
    $newInventoryHtml = str_replace('<div id="header-placeholder"></div>', $headerContent, $newInventoryHtml);
    $newInventoryHtml = str_replace('<div id="footer-placeholder"></div>', $footerContent, $newInventoryHtml);
    file_put_contents('inventory.html', $newInventoryHtml);

    // Обновляем index.html
    $featuredCars = array_slice($db, 0, 3);
    $featuredCardsHtml = "";
    foreach ($featuredCars as $car) {
        $featuredCardsHtml .= generateCarCard($car);
    }

    $newIndexHtml = str_replace('{{FEATURED_CARS_PLACEHOLDER}}', $featuredCardsHtml, $indexTemplate);
    $newIndexHtml = str_replace('<div id="header-placeholder"></div>', $headerContent, $newIndexHtml);
    $newIndexHtml = str_replace('<div id="footer-placeholder"></div>', $footerContent, $newIndexHtml);
    file_put_contents('index.html', $newIndexHtml);
}
?>
