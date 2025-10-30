document.addEventListener("DOMContentLoaded", function() {
    // Function to set the theme
    const setTheme = (theme) => {
        document.body.classList.toggle("light-theme", theme === "light");
        localStorage.setItem("theme", theme);
        updateToggleIcon(theme);
    };

    // Function to update the theme toggle icon
    const updateToggleIcon = (theme) => {
        const toggleButton = document.getElementById("theme-toggle");
        if (toggleButton) {
            toggleButton.innerHTML = theme === "light" ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        }
    };

    // Load header and then initialize theme toggle
    fetch("templates/header.html")
        .then(response => response.text())
        .then(data => {
            document.getElementById("header-placeholder").innerHTML = data;

            // Initialize theme toggle logic after header is loaded
            const toggleButton = document.getElementById("theme-toggle");
            if (toggleButton) {
                toggleButton.addEventListener("click", () => {
                    const currentTheme = localStorage.getItem("theme") || "dark";
                    const newTheme = currentTheme === "light" ? "dark" : "light";
                    setTheme(newTheme);
                });
            }

            // Apply the saved theme on page load
            const savedTheme = localStorage.getItem("theme") || "dark";
            setTheme(savedTheme);
        });

    // Load footer
    fetch("templates/footer.html")
        .then(response => response.text())
        .then(data => {
            document.getElementById("footer-placeholder").innerHTML = data;
        });

    // Inject the light theme stylesheet
    const lightThemeLink = document.createElement("link");
    lightThemeLink.rel = "stylesheet";
    lightThemeLink.href = "assets/css/light-theme.css";
    document.head.appendChild(lightThemeLink);
});
