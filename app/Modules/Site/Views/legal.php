<?php
$privacy = $data['path'] === '/privacy-policy';
$policy = $data['disclosurePolicy'] ?? null;
$approvedNotice = $privacy && $policy !== null && $policy->active();
$sections = $privacy ? [
    ['Account information', 'We use the email address, password hash and account category to secure account access. Teachers supply their names and contact details. Schools supply institution and representative details. Parents and guardians supply their own name, email, phone number, state and city. Registration asks for the parent or guardian’s information only.'],
    ['Account and professional verification', 'We hold registration draft details and a password hash in a private session until confirmation. Verified profiles record the Terms of Use and Privacy Notice revisions acknowledged at signup. Teachers submit professional profile information and may upload a CV or verification documents for staff review. Public listings use limited professional fields. Account contact details and private documents stay out of public listings.'],
    ['Recruitment and tutoring requests', 'Schools and parents or guardians submit recruitment or tutoring details for staff to assess. Requests can include a purpose, requirements and a teacher’s public reference. Tutoring requests can describe learning objectives, education level, learner count, lesson format, general location and scheduling preferences. Leave out learner names, identifying details and street addresses.'],
    ['Proposed managed disclosures', 'Managed disclosures are disabled while the policy is under review. The proposed process limits eligible recipients to verified school accounts and parent or guardian accounts with an eligible case. A disclosure needs the teacher’s separate, documented consent for a specific recipient, purpose and scope. Original CV access needs a separate approval for the exact current document. Identity documents are excluded. Access ends at expiry or revocation. Recipients may retain copies they previously viewed or downloaded.'],
    ['Website preferences and assets', 'The site remembers your appearance preference in browser storage. It loads Bootstrap styles and fonts from external providers.'],
    ['Retention', 'The reviewed notice will explain how long we keep each category of data and when we remove it.'],
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
        <span class="status-badge"><?= $approvedNotice ? 'Approved disclosure notice available' : 'Draft awaiting legal review' ?></span>
        <h1><?= $escape($data['title']) ?></h1>
        <?php if ($approvedNotice): ?>
            <p class="page-lede"><a href="<?= $escape($policy->noticeUrl) ?>" rel="noreferrer">Read the approved disclosure notice</a> (version <?= $escape($policy->version) ?>).</p>
        <?php else: ?>
            <p class="page-lede">This working draft outlines the intended <?= $privacy ? 'privacy practices' : 'conditions of use' ?> for Furoeducare. The published version will follow legal review.</p>
        <?php endif; ?>
    </div>
</section>
<div class="site-shell section-space">
    <div class="legal-reading">
        <?php if (!$approvedNotice): ?>
            <?php foreach ($sections as [$heading, $text]): ?>
                <section><h2><?= $escape($heading) ?></h2><p><?= $escape($text) ?></p></section>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($privacy): ?>
            <section>
                <h2>Privacy requests and disclosure controls</h2>
                <p>Open <a href="/privacy/controls">Privacy controls</a> to request access, correction, erasure or restriction, object to processing or withdraw consent. Teachers can also see their disclosure history and pause sharing to end current grants.</p>
                <?php if ($policy !== null && $policy->contact !== ''): ?>
                    <p><a href="<?= $escape($policy->contactUrl()) ?>">Contact the privacy team</a> for help exercising your rights.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
