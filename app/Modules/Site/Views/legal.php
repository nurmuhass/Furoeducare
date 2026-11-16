<?php
$privacy = $data['path'] === '/privacy-policy';
$sections = $privacy ? [
    ['Information you share', 'Account registration uses an email address, a password hash, an account category and, for school accounts, a school name. Later service workflows may ask for professional information or documents. Use sample details in the contact preview.'],
    ['Public profiles and private records', 'Public teacher listings are intended to show limited professional information. Personal contact details, original CVs and identity documents are private. Management handles approved access requests under the applicable privacy policy.'],
    ['Website preferences and assets', 'The site remembers your appearance preference in browser storage. It loads Bootstrap styles and fonts from external providers.'],
    ['Retention and privacy enquiries', 'The final policy will explain retention, how to exercise applicable data rights and how to contact Furoeducare about privacy.'],
] : [
    ['Using Furoeducare', 'Furoeducare is designed for teachers and NYSC members, schools and education institutions, and parents and guardians in Nigeria. Users are expected to provide accurate information and respect other people’s privacy.'],
    ['Recruitment and verification', 'Furoeducare manages recruitment, shortlisting and placement. Schools require management approval to post vacancies, and professional verification is required before a teacher’s final placement.'],
    ['Content and library access', 'Published free books can be downloaded by visitors. Different items may have their own access terms and usage rights.'],
    ['Services and availability', 'The initial release is planned as a free service. Availability, account responsibilities and support procedures will be detailed in the reviewed terms.'],
];
?>
<section class="page-band">
    <div class="site-shell page-intro legal-intro">
        <?php require $partials . '/breadcrumb.php'; ?>
        <span class="status-badge">Draft awaiting legal review</span>
        <h1><?= $escape($data['title']) ?></h1>
        <p class="page-lede">This working draft outlines the intended <?= $privacy ? 'privacy practices' : 'conditions of use' ?> for Furoeducare. The published version will follow legal review.</p>
    </div>
</section>
<div class="site-shell section-space">
    <div class="legal-reading">
        <?php foreach ($sections as [$heading, $text]): ?>
            <section><h2><?= $escape($heading) ?></h2><p><?= $escape($text) ?></p></section>
        <?php endforeach; ?>
    </div>
</div>
