/**
 * Layout Editor — toggle element pickers based on mode selection
 * and show/hide option panels based on visibility checkboxes.
 */
(function () {
    'use strict';

    // Header visibility toggle
    var headerVisible = document.getElementById('header_visible');
    var headerOptions = document.getElementById('header-options');
    if (headerVisible && headerOptions) {
        function toggleHeaderOptions() {
            headerOptions.style.display = headerVisible.checked ? '' : 'none';
        }
        headerVisible.addEventListener('change', toggleHeaderOptions);
        toggleHeaderOptions();
    }

    // Footer visibility toggle
    var footerVisible = document.getElementById('footer_visible');
    var footerOptions = document.getElementById('footer-options');
    if (footerVisible && footerOptions) {
        function toggleFooterOptions() {
            footerOptions.style.display = footerVisible.checked ? '' : 'none';
        }
        footerVisible.addEventListener('change', toggleFooterOptions);
        toggleFooterOptions();
    }

    // Header mode → element picker toggle
    var headerPicker = document.getElementById('header-element-picker');
    if (headerPicker) {
        document.querySelectorAll('input[name="header_mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                headerPicker.style.display = this.value === 'block' ? '' : 'none';
            });
        });
    }

    // Footer mode → element picker toggle
    var footerPicker = document.getElementById('footer-element-picker');
    if (footerPicker) {
        document.querySelectorAll('input[name="footer_mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                footerPicker.style.display = this.value === 'block' ? '' : 'none';
            });
        });
    }
})();
