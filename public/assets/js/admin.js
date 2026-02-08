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

    // --- Model Management (Settings page) ---
    var modelMgmt = document.getElementById("model-management");
    if (modelMgmt) {
        var fetchBtn = document.getElementById("fetch-models-btn");
        var fetchStatus = document.getElementById("fetch-models-status");
        var modelsList = document.getElementById("models-list");
        var saveBtn = document.getElementById("save-models-btn");
        var saveStatus = document.getElementById("save-models-status");
        var modelSelect = document.getElementById("claude_model");
        var csrfToken = document.querySelector('input[name="_csrf_token"]');
        var csrfValue = csrfToken ? csrfToken.value : "";

        function escapeHtml(text) {
            var div = document.createElement("div");
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        function renderModelCheckboxes(models, enabledIds) {
            if (!models.length) {
                modelsList.innerHTML =
                    '<p class="section-desc">No models returned from API.</p>';
                return;
            }
            var enabledSet = {};
            if (enabledIds) {
                enabledIds.forEach(function (id) {
                    enabledSet[id] = true;
                });
            }
            var html = "";
            models.forEach(function (model) {
                var checked =
                    enabledIds ? (enabledSet[model.id] ? " checked" : "") : "";
                html +=
                    '<label class="model-checkbox-label">' +
                    '<input type="checkbox" class="model-checkbox" value="' +
                    escapeHtml(model.id) +
                    '"' +
                    checked +
                    ">" +
                    '<span class="model-name">' +
                    escapeHtml(model.display_name) +
                    "</span>" +
                    '<span class="model-id">' +
                    escapeHtml(model.id) +
                    "</span>" +
                    "</label>";
            });
            modelsList.innerHTML = html;
            showSaveArea();
        }

        function showSaveArea() {
            var saveArea = document.querySelector(".model-save-area");
            if (!saveArea) {
                var area = document.createElement("div");
                area.className = "model-save-area";
                area.innerHTML =
                    '<button type="button" id="save-models-btn" class="btn btn-primary btn-sm">Save Model Selection</button>' +
                    '<span id="save-models-status" class="status-text"></span>';
                modelMgmt.appendChild(area);
                saveBtn = document.getElementById("save-models-btn");
                saveStatus = document.getElementById("save-models-status");
                bindSaveButton();
            }
        }

        function updateDropdown(enabledIds, allModels) {
            if (!modelSelect || !enabledIds.length) return;
            var currentValue = modelSelect.value;
            var enabledSet = {};
            enabledIds.forEach(function (id) {
                enabledSet[id] = true;
            });
            var dropdownModels = allModels.filter(function (m) {
                return enabledSet[m.id];
            });
            modelSelect.innerHTML = "";
            dropdownModels.forEach(function (model) {
                var option = document.createElement("option");
                option.value = model.id;
                option.textContent = model.display_name;
                if (model.id === currentValue) {
                    option.selected = true;
                }
                modelSelect.appendChild(option);
            });
            // If previous selection is no longer available, select first
            if (
                modelSelect.selectedIndex === -1 &&
                modelSelect.options.length > 0
            ) {
                modelSelect.selectedIndex = 0;
            }
        }

        function getAllModelsFromCheckboxes() {
            var models = [];
            modelsList
                .querySelectorAll(".model-checkbox-label")
                .forEach(function (label) {
                    var checkbox = label.querySelector(".model-checkbox");
                    var nameSpan = label.querySelector(".model-name");
                    if (checkbox && nameSpan) {
                        models.push({
                            id: checkbox.value,
                            display_name: nameSpan.textContent.trim(),
                        });
                    }
                });
            return models;
        }

        // Fetch models from API
        if (fetchBtn) {
            fetchBtn.addEventListener("click", function () {
                fetchBtn.disabled = true;
                fetchStatus.textContent = "Fetching models…";
                fetchStatus.style.color = "";

                fetch("/admin/ai/models/fetch", {
                    method: "POST",
                    headers: {
                        "X-CSRF-Token": csrfValue,
                        "Content-Type": "application/json",
                    },
                    body: "{}",
                })
                    .then(function (res) {
                        return res.json();
                    })
                    .then(function (data) {
                        fetchBtn.disabled = false;
                        if (data.success) {
                            fetchStatus.textContent =
                                data.models.length + " models loaded.";
                            fetchStatus.style.color = "#155724";
                            renderModelCheckboxes(data.models, null);
                        } else {
                            fetchStatus.textContent =
                                "Error: " + (data.error || "Unknown error");
                            fetchStatus.style.color = "#721c24";
                        }
                    })
                    .catch(function (err) {
                        fetchBtn.disabled = false;
                        fetchStatus.textContent = "Network error: " + err.message;
                        fetchStatus.style.color = "#721c24";
                    });
            });
        }

        // Save enabled models
        function bindSaveButton() {
            if (!saveBtn) return;
            saveBtn.addEventListener("click", function () {
                var checked = modelsList.querySelectorAll(
                    ".model-checkbox:checked"
                );
                var ids = [];
                checked.forEach(function (cb) {
                    ids.push(cb.value);
                });

                if (!ids.length) {
                    saveStatus.textContent = "Select at least one model.";
                    saveStatus.style.color = "#856404";
                    return;
                }

                saveBtn.disabled = true;
                saveStatus.textContent = "Saving…";
                saveStatus.style.color = "";

                fetch("/admin/ai/models/enable", {
                    method: "POST",
                    headers: {
                        "X-CSRF-Token": csrfValue,
                        "Content-Type": "application/json",
                    },
                    body: JSON.stringify({ model_ids: ids }),
                })
                    .then(function (res) {
                        return res.json();
                    })
                    .then(function (data) {
                        saveBtn.disabled = false;
                        if (data.success) {
                            saveStatus.textContent = "Model selection saved.";
                            saveStatus.style.color = "#155724";
                            var allModels = getAllModelsFromCheckboxes();
                            updateDropdown(ids, allModels);
                        } else {
                            saveStatus.textContent =
                                "Error: " + (data.error || "Unknown error");
                            saveStatus.style.color = "#721c24";
                        }
                    })
                    .catch(function (err) {
                        saveBtn.disabled = false;
                        saveStatus.textContent = "Network error: " + err.message;
                        saveStatus.style.color = "#721c24";
                    });
            });
        }

        // Bind save button if it already exists in the page
        if (saveBtn) {
            bindSaveButton();
        }
    }
});
