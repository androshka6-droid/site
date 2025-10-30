<?php
require_once 'admin_functions.php';
$dbFile = 'cars_db.json';
$cars = loadDB($dbFile);
// Get the last 3 cars added
$featured_cars = array_slice($cars, -3, 3, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - Dean's Auto Sales</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div id="header-placeholder"></div>
    <main>
        <section class="hero" style="background-image: url('https://placehold.co/1920x1080/2a2a2a/ffffff?text=HERO+IMAGE');">
            <div class="container">
                <a href="inventory.html" class="btn-hero">BROWSE INV</a>
            </div>
        </section>
        <section class="featured-vehicles">
            <div class="container">
                <h2>Featured Vehicles</h2>
                <div class="vehicle-grid">
                    <?php if (empty($featured_cars)): ?>
                        <p>No featured vehicles at this time. Check back soon!</p>
                    <?php else: ?>
                        <?php foreach (array_reverse($featured_cars) as $car): ?>
                            <a href="cars/<?php echo htmlspecialchars($car['slug']); ?>.html" class="vehicle-card">
                                <div class="vehicle-media" style="background-image: url('assets/images/<?php echo htmlspecialchars($car['images'][0]); ?>');"></div>
                                <div class="vehicle-body">
                                    <h3><?php echo htmlspecialchars($car['title']); ?></h3>
                                    <div class="vehicle-meta">
                                        <span class="price">$<?php echo number_format($car['price']); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
    <div id="footer-placeholder"></div>
    <script src="assets/js/main.js"></script>
</body>
</html>
