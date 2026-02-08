<?php $this->layout('public/layout'); ?>

<div class="contact-page">
    <h1><?= $this->e($title) ?></h1>

<?php if (!empty($success)): ?>
    <div class="flash-success"><?= $this->e($success) ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="flash-error"><?= $this->e($error) ?></div>
<?php endif; ?>

    <form method="POST" action="/contact" class="contact-form" novalidate>
        <?= $this->csrfField() ?>

        <div class="form-group">
            <label for="contact-name">Name <span aria-hidden="true">*</span></label>
            <input type="text" id="contact-name" name="name" required maxlength="100"
                   value="<?= $this->e($old['name'] ?? '') ?>"
                   aria-required="true">
        </div>

        <div class="form-group">
            <label for="contact-email">Email <span aria-hidden="true">*</span></label>
            <input type="email" id="contact-email" name="email" required maxlength="255"
                   value="<?= $this->e($old['email'] ?? '') ?>"
                   aria-required="true">
        </div>

        <div class="form-group">
            <label for="contact-subject">Subject</label>
            <input type="text" id="contact-subject" name="subject" maxlength="255"
                   value="<?= $this->e($old['subject'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="contact-message">Message <span aria-hidden="true">*</span></label>
            <textarea id="contact-message" name="message" required maxlength="5000"
                      aria-required="true"><?= $this->e($old['message'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="form-submit">Send Message</button>
    </form>
</div>
