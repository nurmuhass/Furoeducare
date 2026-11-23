<?php

use Furoeducare\Modules\Auth\Role;
use Furoeducare\Support\NigerianStates;

$fieldPrefix = 'parent';
require $partials . '/form-fields.php';
$profile = $data['profile'];
$tab = $data['tab'];
$values = ($data['values'] ?? []) + $profile + ['email' => $data['email']];
$errors = $data['errors'] ?? [];
?>
<section class="page-band parent-dashboard">
    <div class="site-shell">
        <div class="page-intro mb-4">
            <p class="page-lede">Parent / Guardian</p>
            <h1><?= $escape($data['title']) ?></h1>
        </div>
        <?php if ($data['notice'] !== ''): ?><p class="auth-notice mb-3" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
        <div class="row g-4 align-items-start">
            <div class="col-12">
                <div class="form-panel parent-panel">
                    <?= \Furoeducare\UI\Components::error($errors) ?>
                    <?php switch ($tab):
                        case 'overview': ?>
                            <h2>Welcome, <?= $escape($profile['first_name']) ?></h2>
                            <p class="mb-3"><?= $escape($profile['city']) ?>, <?= $escape(NigerianStates::NAMES[$profile['state_code']]) ?> · <span class="status-badge">Email verified</span></p>
                            <div class="dashboard-metrics" aria-label="Tutoring request activity">
                                <?= \Furoeducare\UI\Components::metric('Private drafts', (int) $data['counts']['drafts'], '/dashboard/parent?tab=requests') ?>
                                <?= \Furoeducare\UI\Components::metric('Submitted requests', (int) $data['counts']['submitted'], '/dashboard/parent?tab=requests') ?>
                            </div>
                            <div class="button-row">
                                <a class="btn button button-primary" href="/dashboard/parent?tab=request-teacher">Request a lesson teacher</a>
                                <a class="btn button button-outline" href="/dashboard/parent?tab=requests">My requests</a>
                            </div>
                            <?php break;
                        case 'find-teachers': ?>
                            <h2>Public teacher directory</h2>
                            <p>Browse publicly listed teacher profiles.</p>
                            <p class="mt-3"><a class="btn button button-primary" href="/find-teachers">Browse the public directory</a></p>
                            <?php break;
                        case 'saved': ?>
                            <h2>Your saved teachers</h2>
                            <p><a class="btn button button-primary" href="/find-teachers/saved">Open saved teachers</a></p>
                            <?php break;
                        case 'library': ?>
                            <h2>Digital library</h2>
                            <p>Browse learning materials, return to saved resources and review your account's downloads.</p>
                            <div class="button-row mt-3">
                                <a class="btn button button-primary" href="/digital-library">Browse the digital library</a>
                                <a class="btn button button-outline" href="/digital-library/saved">Saved resources</a>
                                <a class="btn button button-outline" href="/digital-library/history">Download history</a>
                            </div>
                            <?php break;
                        case 'request-teacher':
                        case 'requests':
                        case 'arrangements':
                            if ($tab === 'requests'): ?><p class="mb-3"><a href="/candidate-requests">Candidate shortlists and information requests</a></p><?php endif;
                            if ($tab === 'arrangements'): ?><p class="mb-3"><a href="/placements">Manage tutoring placements</a></p><?php endif;
                            require __DIR__ . '/requests.php';
                            break;
                        case 'recommended': ?>
                            <h2>Tutors shortlisted for your requests</h2>
                            <?php if ($data['recommendations'] === []): ?><p>No tutor shortlists yet. Check progress in <a href="/dashboard/parent?tab=requests">My Requests</a>.</p><?php endif; ?>
                            <?php foreach ($data['recommendations'] as $recommendation): ?>
                                <h3 class="h5 mt-3"><a href="/dashboard/parent?tab=requests&amp;id=<?= (int) $recommendation['id'] ?>"><?= $escape($recommendation['subject']) ?></a></h3>
                                <?php if ($recommendation['candidates'] === []): ?><p>Furoeducare is reviewing tutor availability.</p><?php endif; ?>
                                <ul>
                                    <?php foreach ($recommendation['candidates'] as $tutor): ?>
                                        <li><a href="/find-teachers/profile?ref=<?= $escape($tutor['reference']) ?>"><?= $escape($tutor['title']) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endforeach; ?>
                            <?php if ($data['next'] !== null): ?>
                                <a href="/dashboard/parent?tab=recommended&amp;before=<?= (int) $data['next'] ?>">Older recommendations</a>
                            <?php endif; ?>
                            <?php break;
                        case 'notifications': ?>
                            <h2>Tutoring, placement and disclosure updates</h2>
                            <?php if ($data['notifications'] === []): ?><p>No tutoring updates yet.</p><?php endif; ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($data['notifications'] as $notice): ?>
                                    <?php $when = (new DateTimeImmutable($notice['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Lagos')); ?>
                                    <li class="list-group-item">
                                        <strong><?= $escape($notice['title']) ?></strong>
                                        <time class="d-block small" datetime="<?= $escape($when->format(DATE_ATOM)) ?>"><?= $escape($when->format('j M Y, H:i')) ?> WAT</time>
                                        <p class="mb-0"><?= $escape($notice['message']) ?></p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="mt-3"><a href="/dashboard/parent?tab=requests">Open My Requests</a> · <a href="/placements">View placements</a></p>
                            <?php break;
                        case 'settings': ?>
                            <p><a href="/settings/account">Account security, phone, appearance and deactivation requests</a></p>
                            <p><a href="/settings/notifications">Notification settings</a></p>
                            <p><a href="/privacy/controls">Privacy controls and data rights</a></p>
                            <h2>Parent profile</h2>
                            <form class="auth-form" method="post" action="/dashboard/parent/profile">
                                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                                <?php $registration = false; require __DIR__ . '/profile-fields.php'; ?>
                                <button class="btn button button-primary" type="submit">Save parent profile</button>
                            </form>
                            <p class="mt-3"><a href="/forgot-password">Reset password</a></p>
                            <?php foreach ($data['roles'] as $role): ?>
                                <?php if ($role !== Role::Parent): ?><p><a href="<?= $escape($role->dashboard()) ?>"><?= $escape($role->label()) ?> dashboard</a></p><?php endif; ?>
                            <?php endforeach; ?>
                            <?php break;
                    endswitch; ?>
                </div>
            </div>
        </div>
    </div>
</section>
