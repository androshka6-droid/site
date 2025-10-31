document.addEventListener('DOMContentLoaded', function() {
    const burgerMenuBtn = document.getElementById('burger-menu');
    const mainNav = document.querySelector('.main-nav');

    if (burgerMenuBtn && mainNav) {
        burgerMenuBtn.addEventListener('click', function() {
            mainNav.classList.toggle('active');
        });
    }
});
