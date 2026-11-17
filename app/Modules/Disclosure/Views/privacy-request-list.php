<?php if ($data['requests'] === []): ?>
    <p>No privacy requests on this page.</p>
<?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach (array_slice($data['requests'], 0, $pageSize) as $request): ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                <a href="<?= $escape($requestBase) ?>?id=<?= (int) $request['id'] ?>">
                    #<?= (int) $request['id'] ?> · <?= $escape($data['rights'][$request['right_type']]) ?>
                </a>
                <time><?= $escape(substr($request['created_at'], 0, 10)) ?></time>
                <span class="status-badge"><?= $request['status'] === 'resolved' ? 'Resolved' : 'Submitted' ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
