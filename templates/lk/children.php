<?php
/** @var array $kids     // map: 'ФИО' => сумма
  * @var array $kidNames // list: ['ФИО', 'ФИО2', ...] (из API)
  */
$toRender = [];
if (!empty($kids)) {
  foreach ($kids as $name => $sum) $toRender[] = ['name'=>(string)$name, 'sum'=>(float)$sum];
} elseif (!empty($kidNames)) {
  foreach ($kidNames as $name) if (($name=trim((string)$name))!=='') $toRender[] = ['name'=>$name, 'sum'=>0.0];
}
?>
<section class="lk-section">
  <h2 class="lk-section__title">Дети</h2>
  <div class="lk-cards">
    <?php if (empty($toRender)): ?>
      <div class="lk-card lk-kid">
        <div class="lk-kid__name">Детей не найдено</div>
        <div class="lk-kid__balance">0 ₽</div>
      </div>
    <?php else: ?>
      <?php foreach ($toRender as $row):
        $name = $row['name']; $sum = (float)$row['sum'];
        $cls  = $sum > 0 ? 'is-positive' : ($sum < 0 ? 'is-negative' : '');
      ?>
        <div class="lk-card lk-kid" title="<?= esc_attr($name) ?>">
          <div class="lk-kid__name"><?= esc_html($name) ?></div>
          <div class="lk-kid__balance <?= $cls ?>">
            <?= $sum < 0 ? '−' : '' ?><?= number_format(abs($sum), 0, ',', ' ') ?> ₽
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
