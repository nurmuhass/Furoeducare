<?php $profile = $data['profile']; ?>
<section class="page-band">
    <div class="site-shell page-intro">
        <a href="<?= $escape($data['root']) ?>">Back to candidate requests</a>
        <h1 class="mt-3">Approved professional profile</h1>
        <p>Use this profile only for the purpose in the candidate request. Keep it within the recipient account and delete any copy when access expires or is revoked.</p>
    </div>
</section>
<div class="site-shell section-space">
    <?php require __DIR__ . '/candidate-profile-facts.php'; ?>
</div>
