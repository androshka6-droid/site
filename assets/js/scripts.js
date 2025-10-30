// scripts.js
document.addEventListener('DOMContentLoaded', function() {
    // Inventory Filtering and Sorting
    const makeFilter = document.querySelector('select[name="make"]');
    const modelFilter = document.querySelector('select[name="model"]');
    const sortSelect = document.getElementById('sort');
    const inventoryGrid = document.getElementById('inventoryGrid');

    if (inventoryGrid) {
        const vehicleCards = Array.from(inventoryGrid.querySelectorAll('.vehicle-card'));

        const carModels = {
            ford: ['Mustang', 'F-150'],
            chevrolet: ['Silverado'],
            toyota: ['Camry', 'Highlander'],
            honda: ['Civic']
        };

        makeFilter.addEventListener('change', function() {
            const selectedMake = this.value;
            modelFilter.innerHTML = '<option value="">All Models</option>';
            if (selectedMake && carModels[selectedMake]) {
                carModels[selectedMake].forEach(model => {
                    const option = document.createElement('option');
                    option.value = model.toLowerCase();
                    option.textContent = model;
                    modelFilter.appendChild(option);
                });
            }
            filterAndSort();
        });

        modelFilter.addEventListener('change', filterAndSort);
        sortSelect.addEventListener('change', filterAndSort);

        function filterAndSort() {
            const selectedMake = makeFilter.value;
            const selectedModel = modelFilter.value;
            const sortBy = sortSelect.value;

            // Filter
            let filteredVehicles = vehicleCards.filter(card => {
                const title = card.querySelector('h3').textContent.toLowerCase();
                const makeMatch = !selectedMake || title.includes(selectedMake);
                const modelMatch = !selectedModel || title.includes(selectedModel);
                return makeMatch && modelMatch;
            });

            // Sort
            filteredVehicles.sort((a, b) => {
                const aPrice = parseInt(a.querySelector('p').textContent.replace('$', '').replace(',', ''));
                const bPrice = parseInt(b.querySelector('p').textContent.replace('$', '').replace(',', ''));
                const aYear = parseInt(a.querySelector('h3').textContent.split(' ')[0]);
                const bYear = parseInt(b.querySelector('h3').textContent.split(' ')[0]);

                switch (sortBy) {
                    case 'price-asc':
                        return aPrice - bPrice;
                    case 'price-desc':
                        return bPrice - aPrice;
                    case 'year-desc':
                        return bYear - aYear;
                    case 'year-asc':
                        return aYear - bYear;
                    default:
                        return 0;
                }
            });

            // Render
            inventoryGrid.innerHTML = '';
            filteredVehicles.forEach(card => inventoryGrid.appendChild(card));
        }
    }

    // Contact Form Submission
    const contactForm = document.querySelector('.contact-form');
    if (contactForm) {
        contactForm.addEventListener('submit', function(event) {
            event.preventDefault();
            alert('Thank you for your message. We will get back to you shortly.');
            contactForm.reset();
        });
    }

    // Search Bar
    const searchBar = document.querySelector('.search-bar form');
    if (searchBar) {
        searchBar.addEventListener('submit', function(event) {
            event.preventDefault();
            const searchTerm = searchBar.querySelector('input').value.toLowerCase();
            window.location.href = `inventory.html?search=${searchTerm}`;
        });
    }
});
