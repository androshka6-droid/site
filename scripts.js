// scripts.js
document.addEventListener('DOMContentLoaded', function() {
    const searchButton = document.querySelector('.search-bar button');
    if (searchButton) {
        searchButton.addEventListener('click', function(event) {
            event.preventDefault();
            alert('Search functionality is not yet implemented.');
        });
    }
});