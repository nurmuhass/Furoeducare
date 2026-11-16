<section class="home-hero">
    <div class="site-shell hero-grid">
        <div class="hero-copy">
            <p class="eyebrow"><span aria-hidden="true"></span> Education recruitment in Nigeria</p>
            <h1>Connecting teachers, schools and <em>families.</em></h1>
            <p class="page-lede">Find teaching support, explore vacancies and access learning resources for your classroom or home.</p>
            <div class="button-row">
                <a class="btn button button-primary" href="/find-teachers">Find a teacher <span aria-hidden="true">→</span></a>
                <a class="btn button button-outline" href="/teaching-jobs">Explore teaching jobs</a>
            </div>
        </div>
        <div class="hero-media">
            <img src="/assets/classroom.jpg" width="1448" height="1086" fetchpriority="high"
                 alt="A teacher helps two pupils work together at a classroom desk.">
        </div>
    </div>
</section>
<section class="site-shell section-space" aria-labelledby="home-services">
    <div class="section-heading">
        <div>
            <h2 id="home-services">Our services</h2>
            <p>Recruitment, tutoring, learning resources and professional development.</p>
        </div>
        <a class="text-link" href="/services">View all services <span aria-hidden="true">→</span></a>
    </div>
    <div class="services-grid">
        <?php foreach (require dirname(__DIR__) . '/service-cards.php' as $card): ?>
            <?php require $partials . '/service-card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<section class="site-shell section-end">
    <div class="audience-banner">
        <div>
            <h2>Find the right account for you</h2>
            <p>Explore the options for teachers and NYSC members, schools and institutions, and parents and guardians.</p>
        </div>
        <a class="btn button button-light" href="/register">Explore account options <span aria-hidden="true">→</span></a>
    </div>
</section>
