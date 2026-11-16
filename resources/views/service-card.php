<article class="card service-card">
    <span class="service-icon tone-<?= $escape($card['tone']) ?> icon-<?= $escape($card['icon']) ?>" aria-hidden="true"></span>
    <h3><?= $escape($card['title']) ?></h3>
    <p><?= $escape($card['text']) ?></p>
    <a class="text-link stretched-link" href="<?= $escape($card['href']) ?>"><?= $escape($card['action']) ?> <span aria-hidden="true">→</span></a>
</article>
