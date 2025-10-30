import os
import subprocess
import time
from playwright.sync_api import sync_playwright

def run():
    # Start a simple web server in the background
    server_process = subprocess.Popen(["python3", "-m", "http.server", "8000"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1)  # Give the server a moment to start

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            page = browser.new_page()
            page.goto("http://localhost:8000/index.html")
            page.set_viewport_size({"width": 375, "height": 667})

            # Wait for the burger button to be visible and click it
            burger_button = page.locator("#burger-menu")
            burger_button.wait_for(state="visible", timeout=5000)
            burger_button.click()

            # Wait for the mobile navigation to appear
            nav_menu = page.locator(".main-nav.active")
            nav_menu.wait_for(state="visible", timeout=2000)

            page.screenshot(path="jules-scratch/verification/verification.png")
            browser.close()
    finally:
        # Ensure the server is terminated
        server_process.kill()

if __name__ == "__main__":
    run()
