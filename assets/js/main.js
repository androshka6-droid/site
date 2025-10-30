document.addEventListener("DOMContentLoaded", function() {
    // --- Cache-Busting for Theme Stylesheet ---
    // Fetches the version from theme_version.txt and appends it to the stylesheet URL
    // to ensure theme changes are not blocked by browser cache.
    const bustCache = () => {
        fetch('/theme_version.txt', { cache: 'no-store' }) // no-store prevents caching of the version file itself
            .then(response => response.text())
            .then(version => {
                const stylesheet = document.getElementById('theme-stylesheet');
                if (stylesheet) {
                    const url = new URL(stylesheet.href);
                    url.searchParams.set('v', version.trim());
                    stylesheet.href = url.href;
                }
            })
            .catch(error => {
                console.warn('Could not bust cache for theme:', error);
                // Fallback: use timestamp if version file fails
                const stylesheet = document.getElementById('theme-stylesheet');
                if (stylesheet) {
                    const url = new URL(stylesheet.href);
                    url.searchParams.set('v', new Date().getTime());
                    stylesheet.href = url.href;
                }
            });
    };

    // Function to initialize burger menu
    const initBurgerMenu = () => {
        const burgerButton = document.getElementById("burger-menu");
        const nav = document.querySelector(".main-nav");
        if (burgerButton && nav) {
            burgerButton.addEventListener("click", () => {
                nav.classList.toggle("active");
            });
        }
    };

    // Load header and then initialize theme toggle and burger menu
    fetch("/templates/header.html")
        .then(response => response.text())
        .then(data => {
            document.getElementById("header-placeholder").innerHTML = data;

            // Initialize burger menu
            initBurgerMenu();
        });

    // Load footer
    fetch("/templates/footer.html")
        .then(response => response.text())
        .then(data => {
            document.getElementById("footer-placeholder").innerHTML = data;
        });

    // Apply cache-busting after the entire window has loaded
    window.onload = bustCache;
});
