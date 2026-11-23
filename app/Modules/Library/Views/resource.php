<?php $resource = $data['resource']; $ref = rawurlencode($resource['reference']); ?>
<section class="page-band"><div class="site-shell"><a href="/digital-library">Back to digital library</a><h1 class="mt-3"><?= $escape($resource['title']) ?></h1><p><?= $resource['author'] !== '' ? 'By ' . $escape($resource['author']) : 'Author not supplied' ?></p></div></section>
<div class="site-shell section-space">
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <div class="row g-4">
        <div class="col-md-4">
            <div class="form-panel text-center">
                <?php if (!empty($resource['has_cover'])): ?><img class="img-fluid" src="/digital-library/cover?ref=<?= $escape($ref) ?>" alt="Cover of <?= $escape($resource['title']) ?>" style="max-height:420px">
                <?php else: ?><div class="bg-light py-5 rounded"><span class="display-1" aria-hidden="true">&#128214;</span><p class="mt-3 mb-0"><?= $escape(strtoupper($resource['material_extension'] ?? 'Document')) ?> resource</p></div><?php endif; ?>
            </div>
        </div>
        <div class="col-md-8">
            <section class="form-panel mb-4">
                <h2 class="h4">About this resource</h2>
                <p style="white-space:pre-line"><?= $resource['description'] !== '' ? $escape($resource['description']) : 'No description supplied.' ?></p>
                <dl class="row mb-0">
                    <?php foreach (['publisher' => 'Publisher', 'publication_year' => 'Publication year', 'edition' => 'Edition', 'isbn' => 'ISBN', 'licence' => 'Licence', 'rights_expires_on' => 'Rights expiry'] as $key => $label): ?>
                        <?php if (($resource[$key] ?? '') !== '' && ($resource[$key] ?? null) !== null): ?><dt class="col-sm-4"><?= $escape($label) ?></dt><dd class="col-sm-8"><?= $escape((string) $resource[$key]) ?></dd><?php endif; ?>
                    <?php endforeach; ?>
                    <dt class="col-sm-4">Format</dt><dd class="col-sm-8"><?= $escape(strtoupper($resource['material_extension'])) ?> · <?= $escape(number_format((int) $resource['material_bytes'] / 1048576, 1)) ?> MiB</dd>
                    <?php foreach (['categories' => 'Categories', 'subjects' => 'Subjects', 'levels' => 'Levels'] as $key => $label): ?>
                        <?php if (!empty($resource['tags'][$key])): ?><dt class="col-sm-4"><?= $escape($label) ?></dt><dd class="col-sm-8"><?= $escape(implode(', ', $resource['tags'][$key])) ?></dd><?php endif; ?>
                    <?php endforeach; ?>
                </dl>
            </section>
            <section class="form-panel" aria-labelledby="library-access"><h2 class="h4" id="library-access">Resource access</h2>
                <?php if ($resource['access'] === 'future_paid'): ?>
                    <p>This resource is reserved for future paid access. Downloads are currently unavailable.</p><button type="button" class="btn button button-primary" disabled>Download unavailable</button>
                <?php elseif ($data['canDownload']): ?>
                    <p><?= $resource['access'] === 'free' ? 'Free to download, including without an account.' : 'Your account has access to this resource.' ?> Use this material under its stated licence.</p>
                    <?php if (!empty($resource['grant_expires_at'])): ?><p>Your access expires <?= $escape($data['wat']($resource['grant_expires_at'])) ?> (WAT).</p><?php endif; ?>
                    <a class="btn button button-primary" href="/digital-library/download?ref=<?= $escape($ref) ?>">Download <?= $escape(strtoupper($resource['material_extension'])) ?></a>
                <?php else: ?>
                    <p><?= $resource['access'] === 'restricted' ? 'Downloads require an active access grant from Furoeducare.' : 'Download rights for this resource are currently unavailable.' ?></p>
                    <?php if (!$data['signedIn'] && $resource['access'] === 'restricted'): ?><a class="btn button button-primary" href="/login">Sign in to check access</a><?php else: ?><button type="button" class="btn button button-primary" disabled>Download unavailable</button><?php endif; ?>
                <?php endif; ?>
                <?php if ($data['signedIn']): ?>
                    <form method="post" action="/digital-library/save" class="mt-3 d-flex gap-2 flex-wrap">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>"><input type="hidden" name="ref" value="<?= $escape($resource['reference']) ?>">
                        <button class="btn button button-outline" name="action" value="save">Save resource</button><button class="btn btn-link" name="action" value="remove">Remove from saved</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
