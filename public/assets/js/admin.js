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

    // --- Media Upload Zone (drag & drop + click to select) ---
    var zone = document.getElementById("upload-zone");
    var fileInput = document.getElementById("file-input");
    var uploadPreview = document.getElementById("upload-preview");
    var previewImg = document.getElementById("upload-preview-img");
    var previewName = document.getElementById("upload-preview-name");
    var previewSize = document.getElementById("upload-preview-size");
    var uploadCancel = document.getElementById("upload-cancel");

    if (zone && fileInput) {
        zone.addEventListener("click", function () {
            fileInput.click();
        });

        zone.addEventListener("dragover", function (e) {
            e.preventDefault();
            zone.classList.add("drag-over");
        });

        zone.addEventListener("dragleave", function () {
            zone.classList.remove("drag-over");
        });

        zone.addEventListener("drop", function (e) {
            e.preventDefault();
            zone.classList.remove("drag-over");
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                showUploadPreview(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener("change", function () {
            if (fileInput.files.length) {
                showUploadPreview(fileInput.files[0]);
            }
        });

        if (uploadCancel) {
            uploadCancel.addEventListener("click", function () {
                fileInput.value = "";
                uploadPreview.style.display = "none";
                zone.style.display = "";
            });
        }

        function showUploadPreview(file) {
            if (previewName) previewName.textContent = file.name;
            if (previewSize) previewSize.textContent = formatUploadSize(file.size);
            zone.style.display = "none";
            if (uploadPreview) uploadPreview.style.display = "block";

            if (file.type.startsWith("image/") && previewImg) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    previewImg.src = e.target.result;
                    previewImg.style.display = "block";
                };
                reader.readAsDataURL(file);
            } else if (previewImg) {
                previewImg.style.display = "none";
            }
        }

        function formatUploadSize(bytes) {
            if (bytes < 1024) return bytes + " B";
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
            return (bytes / 1048576).toFixed(1) + " MB";
        }
    }
});
