<dl class="row mb-0">
<?php foreach (['category' => 'Category', 'objectives' => 'Learning objectives', 'target_participants' => 'Who should attend', 'duration' => 'Duration', 'delivery_format' => 'Delivery format', 'prerequisites' => 'Prerequisites', 'materials_metadata' => 'Training materials'] as $key => $label): ?>
<dt class="col-sm-4 mt-3"><?= $label ?></dt><dd class="col-sm-8 mt-sm-3 mb-0" style="white-space:pre-line"><?= $escape($key === 'delivery_format' ? ucfirst(str_replace('_', '-', (string) ($module[$key] ?? ''))) : ((string) ($module[$key] ?? '') ?: 'None specified')) ?></dd>
<?php endforeach; ?></dl>
