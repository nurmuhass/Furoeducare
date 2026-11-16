<?php
$form = [
    'id' => 'contact-preview',
    'button' => 'Preview enquiry',
    'ready' => 'Your enquiry preview is ready. Your entries stayed in this browser.',
    'fields' => [
        ['id' => 'contact-name', 'label' => 'Full name', 'type' => 'text', 'max' => 100],
        ['id' => 'contact-email', 'label' => 'Email address', 'type' => 'email', 'max' => 254],
        ['id' => 'contact-topic', 'label' => 'Enquiry topic', 'options' => ['Teacher recruitment', 'School vacancies', 'Private tutoring', 'Digital library', 'Training and workshops', 'Other']],
        ['id' => 'contact-message', 'label' => 'What would you like help with?', 'type' => 'textarea', 'max' => 1500],
    ],
];
?>
<section class="page-band">
    <div class="site-shell contact-grid">
        <div>
            <?php require $partials . '/breadcrumb.php'; ?>
            <h1>Contact us</h1>
            <p class="page-lede">Contact Furoeducare about teacher recruitment, tutoring, library access or school training.</p>
            <div class="contact-topics">
                <a class="text-link" href="/services">See available services <span aria-hidden="true">→</span></a>
                <a class="text-link" href="/register">Find your account type <span aria-hidden="true">→</span></a>
            </div>
        </div>
        <div class="form-panel">
            <h2>Enquiry form</h2>
            <?php require $partials . '/preview-form.php'; ?>
        </div>
    </div>
</section>
