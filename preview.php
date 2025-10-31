<?php
// Live Preview Page
require_once 'admin_functions.php';

if (isset($_GET['id'])) {
    $carId = $_GET['id'];
    $db = loadDB('cars_db.json');

    if (isset($db[$carId])) {
        $carData = $db[$carId];

        // Generate the HTML for the car page, but don't save it.
        // We'll just echo it out.
        $template = file_get_contents('template.html');

        // Add cache-busting version to CSS link to reflect theme changes
        $themeVersion = file_exists('theme_version.txt') ? trim(file_get_contents('theme_version.txt')) : '1';
        $template = str_replace(
            'href="../assets/css/styles.css"',
            'href="../assets/css/styles.css?v=' . $themeVersion . '"',
            $template
        );

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
            'Drive' => $carData['drive_type'] ?? 'N/A',
            'Exterior Color' => $carData['exterior_color'],
            'Interior Color' => $carData['interior_color'],
            'Fuel' => $carData['fuel_type'] ?? 'N/A',
            'VIN' => $carData['vin'],
            'Title' => $carData['title_status'] ?? 'Clear'
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
            '{{SLUG}}' => $carData['slug']
        ];

        $pageHtml = strtr($template, $replace);

        echo $pageHtml;

    } else {
        echo "Car not found.";
    }
} else {
    echo "No car ID provided.";
}
