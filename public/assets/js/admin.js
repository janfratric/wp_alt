/**
 * LiteCMS Admin JavaScript
 */
document.addEventListener("DOMContentLoaded", function () {
    // --- Sidebar Toggle (Mobile) ---
    var sidebar = document.querySelector(".sidebar");
    var sidebarToggle = document.querySelector(".sidebar-toggle");
    var sidebarOverlay = document.querySelector(".sidebar-overlay");

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener("click", function () {
            sidebar.classList.toggle("open");
            if (sidebarOverlay) {
                sidebarOverlay.classList.toggle("active");
            }
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener("click", function () {
            sidebar.classList.remove("open");
            sidebarOverlay.classList.remove("active");
        });
    }

    // --- Delete Confirmations ---
    document.querySelectorAll("[data-confirm]").forEach(function (el) {
        el.addEventListener("click", function (e) {
            if (!confirm(el.getAttribute("data-confirm"))) {
                e.preventDefault();
            }
        });
    });

    // --- Auto-dismiss flash messages after 5 seconds ---
    document.querySelectorAll(".alert").forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = "opacity 0.3s ease";
            alert.style.opacity = "0";
            setTimeout(function () {
                alert.remove();
            }, 300);
        }, 5000);
    });
});
