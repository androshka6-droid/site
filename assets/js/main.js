document.addEventListener("DOMContentLoaded", function() {
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
});
