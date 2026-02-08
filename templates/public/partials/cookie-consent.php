<?php declare(strict_types=1); ?>
<div id="cookie-consent" class="cookie-consent" style="display: none;" role="dialog" aria-label="Cookie consent">
    <div class="container">
        <div class="cookie-consent-text">
            <?= $this->e($consentText ?? 'This website uses cookies to enhance your experience. We also use analytics cookies to understand how visitors use our site.') ?>
<?php if (!empty($consentLink)): ?>
            <a href="<?= $this->e($consentLink) ?>">Learn more</a>
<?php endif; ?>
        </div>
        <div class="cookie-consent-buttons">
            <button type="button" id="cookie-accept" class="cookie-consent-accept">Accept</button>
            <button type="button" id="cookie-decline" class="cookie-consent-decline">Decline</button>
        </div>
    </div>
</div>
