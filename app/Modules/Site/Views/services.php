<section class="page-band">
    <div class="site-shell page-intro">
        <?php require $partials . '/breadcrumb.php'; ?>
        <h1>Our services</h1>
        <p class="page-lede">Choose the support you need for recruitment, teaching, learning or professional development.</p>
    </div>
</section>
<section class="site-shell section-space" aria-labelledby="explore-services">
    <div class="section-heading"><h2 id="explore-services">Explore the platform</h2></div>
    <div class="services-grid">
        <?php foreach (require dirname(__DIR__) . '/service-cards.php' as $card): ?>
            <?php require $partials . '/service-card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<section class="site-shell section-end" id="tutoring">
    <div class="audience-banner">
        <div>
            <h2>Private tutoring for families</h2>
            <p>Parents and guardians can use their accounts to tell Furoeducare about a learner’s tutoring needs. Requests are handled privately by the team.</p>
        </div>
        <a class="btn button button-light" href="/register">Explore parent accounts <span aria-hidden="true">→</span></a>
    </div>
</section>
