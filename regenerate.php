<?php
// Temporary script to regenerate all car pages
require_once 'admin_functions.php';

// Define the database file since it's normally in admin.php
$dbFile = 'cars_db.json';

echo "Starting regeneration...\n";
$db = loadDB($dbFile);
if (empty($db)) {
    echo "Database is empty. No pages to regenerate.\n";
    exit;
}

$regeneratedCount = 0;
foreach ($db as $carData) {
    if (isset($carData['title'])) {
        generateCarPage($carData);
        $regeneratedCount++;
        echo "Regenerated page for: " . $carData['title'] . "\n";
    }
}
echo "✅ Success! Regenerated $regeneratedCount car pages.\n";
