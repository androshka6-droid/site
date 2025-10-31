document.addEventListener('DOMContentLoaded', function() {
    // Function to fetch and inject HTML
    const loadComponent = (url, placeholderId) => {
        fetch(url)
            .then(response => response.text())
            .then(data => {
                const placeholder = document.getElementById(placeholderId);
                if (placeholder) {
                    placeholder.innerHTML = data;
                }
                // Re-initialize burger menu logic after header is loaded
                if (placeholderId === 'header-placeholder') {
                    initBurgerMenu();
                }
            })
            .catch(error => console.error(`Error loading ${url}:`, error));
    };

    // Load header and footer
    loadComponent('templates/header.html', 'header-placeholder');
    loadComponent('templates/footer.html', 'footer-placeholder');

    // Burger menu functionality
    const initBurgerMenu = () => {
        const burgerMenuBtn = document.getElementById('burger-menu');
        const mainNav = document.querySelector('.main-nav');

        if (burgerMenuBtn && mainNav) {
            burgerMenuBtn.addEventListener('click', () => {
                mainNav.classList.toggle('active');
            });
        }
    };
});
