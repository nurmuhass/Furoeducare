<?php
$staff = $data['staff'];
$root = $staff ? '/dashboard/admin/training' : '/training-workshops';
$limit = $staff ? 20 : 12;
?>
<section class="page-band"><div class="site-shell"><p class="eyebrow">Professional development</p><h1><?= $escape($data['title']) ?></h1><p>Explore training modules and coordinated workshop packages for your school team.</p>
<nav class="d-flex gap-3 flex-wrap" aria-label="Training navigation"><a href="/training-workshops">Public catalogue</a><?php if ($staff): ?><a href="<?= $root ?>?kind=module&amp;new=1">Create module</a><a href="<?= $root ?>?kind=package&amp;new=1">Create package</a><a href="<?= $root ?>/requests">Training requests</a><?php else: ?><a href="/dashboard/school/training">My school's requests</a><?php endif; ?></nav></div></section>
<div class="site-shell section-space">
<?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
<?php if (!$staff): ?><form class="form-panel mb-4" role="search" method="get" action="<?= $root ?>"><label class="form-label" for="training-q">Search training</label><div class="d-flex gap-2"><input class="form-control" id="training-q" type="search" name="q" maxlength="200" value="<?= $escape($data['q']) ?>"><button class="btn button button-primary" type="submit">Search</button></div></form><?php endif; ?>
<?php foreach (['modules' => 'Training modules', 'packages' => 'Workshop packages'] as $group => $heading): $kind = $group === 'modules' ? 'module' : 'package'; $rows = $data[$group]; ?>
<section class="mb-5"><h2 class="h3 mb-3"><?= $heading ?></h2>
<?php if ($rows === []): ?><div class="form-panel" data-empty-state><p class="mb-0"><?= $staff ? 'No ' . $group . ' have been created.' : 'No published ' . $group . ' match your search. Please check back for new training opportunities.' ?></p></div><?php else: ?>
<div class="row g-4"><?php foreach (array_slice($rows, 0, $limit) as $item): $link = $staff ? $root . '?kind=' . $kind . '&id=' . (int) $item['id'] : $root . '/' . $kind . '?id=' . (int) $item['id']; ?>
<div class="col-md-6 col-xl-4"><article class="form-panel h-100 d-flex flex-column"><?php if ($staff): ?><p><span class="status-badge"><?= $escape(ucfirst($item['status'])) ?></span></p><?php endif; ?><h3 class="h4"><a href="<?= $escape($link) ?>"><?= $escape($item['title']) ?></a></h3><p><?= $escape($item['description']) ?></p>
<?php if ($kind === 'module'): ?><p class="small"><?= $escape($item['category']) ?> · <?= $escape($item['duration']) ?> · <?= $escape(ucfirst(str_replace('_', '-', $item['delivery_format']))) ?></p><?php endif; ?><p class="mt-auto mb-0"><a href="<?= $escape($link) ?>"><?= $staff ? 'Manage ' . $kind : 'View ' . $kind ?> &rarr;</a></p></article></div>
<?php endforeach; ?></div><?php endif; ?>
<?php if (count($rows) > $limit): $params = ($data['cursors'] ?? []) + ['q' => $data['q']]; $params[$group . '_before'] = (int) $rows[$limit - 1]['id']; ?>
<a class="btn button button-outline mt-4" href="<?= $escape($root . '?' . http_build_query($params)) ?>">More <?= $group ?></a>
<?php endif; ?></section>
<?php endforeach; ?>
</div>
