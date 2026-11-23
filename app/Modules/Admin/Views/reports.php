<?php
$snapshot = $data['snapshot'];
$allTime = $snapshot['period']['from'] === null;
$export = $root . '/reports/export?' . http_build_query($data['reportQuery']);
?>
<section class="admin-panel" aria-label="Report date selection">
    <form class="row g-3 align-items-end" method="get" action="<?= $root ?>/reports">
        <div class="col-sm-4"><label for="report-from" class="form-label">From date (WAT)</label><input class="form-control" id="report-from" type="date" name="from" value="<?= $escape($data['from']) ?>" min="1000-01-01" max="9998-12-31" required></div>
        <div class="col-sm-4"><label for="report-to" class="form-label">To date (WAT, inclusive)</label><input class="form-control" id="report-to" type="date" name="to" value="<?= $escape($data['to']) ?>" min="1000-01-01" max="9998-12-31" required></div>
        <div class="col-sm-4"><button class="btn button button-primary" type="submit">Apply dates</button></div>
    </form>
    <div class="button-row mt-3"><a href="<?= $root ?>/reports?period=all">All time</a><a href="<?= $root ?>/reports">This month</a><a class="btn button button-outline" href="<?= $escape($export) ?>">Export aggregate CSV</a></div>
</section>
<section class="admin-panel" aria-labelledby="report-scope-heading">
    <h2 id="report-scope-heading" class="h5"><?= $allTime ? 'All-time operational report' : 'Selected reporting period' ?></h2>
    <?php if (!$allTime): ?><p><?= $escape($data['from']) ?> to <?= $escape($data['to']) ?>, inclusive, Africa/Lagos (WAT).</p><?php endif; ?>
    <p>Each measure uses its stated date basis. Current availability, approval and vacancy states are evaluated when this report runs; they are not historical snapshots.</p>
    <p class="small mb-0">Generated <?= $escape($snapshot['generated_at']) ?> UTC. Availability and deadlines use <?= $escape($snapshot['as_of_date']) ?> WAT. Hidden modules are omitted. CSV contains aggregate counts only.</p>
</section>
<?php if ($snapshot['metrics'] === []): ?>
    <?= \Furoeducare\UI\Components::empty('No reporting datasets available', 'Your reporting access does not include any underlying module read permissions. Ask an authorised staff manager to review your assignment.') ?>
<?php else: ?>
    <section class="dashboard-metrics" aria-label="Operational metrics">
        <?php foreach ($snapshot['metrics'] as $metric): ?>
            <?= \Furoeducare\UI\Components::metric($metric['label'], $metric['value'], hint: 'Date basis: ' . $metric['date_basis']) ?>
        <?php endforeach; ?>
    </section>
    <section class="admin-panel" aria-labelledby="report-definitions-heading"><h2 id="report-definitions-heading" class="h5">Measure definitions</h2>
        <?= \Furoeducare\UI\Components::table($snapshot['metrics'], ['label' => 'Measure', 'value' => 'Count', 'date_basis' => 'Date basis', 'definition' => 'What is counted'], 'Operational report definitions') ?>
    </section>
<?php endif; ?>
