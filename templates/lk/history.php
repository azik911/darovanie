<?php
/** @var array $lk_groups */
$groups = $lk_groups ?? [];
if (!$groups) return;

$fmtMoney = function(float $v): string {
  $sign = $v < 0 ? '−' : '';
  return $sign . number_format(abs($v), 0, ',', ' ') . ' ₽';
};
$ru_month = ['01'=>'января','02'=>'февраля','03'=>'марта','04'=>'апреля','05'=>'мая','06'=>'июня','07'=>'июля','08'=>'августа','09'=>'сентября','10'=>'октября','11'=>'ноября','12'=>'декабря'];
$ruDate = function(string $d) use ($ru_month): string {
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return $d;
  return ltrim($m[3], '0') . ' ' . ($ru_month[$m[2]] ?? $m[2]) . ' ' . $m[1];
};
?>
<section class="lk-section lk-section--history" id="lk-history">
  <h2 class="lk-section__title">История</h2>

  <div class="lk-history-controls">
    <div class="lk-dropdown" id="lk-filter-type">
      <button class="lk-dropdown__toggle" type="button">Что показывать ▾</button>
      <ul class="lk-dropdown__menu">
        <li data-filter="all">Все операции</li>
        <li data-filter="debit">Списания</li>
        <li data-filter="credit">Поступления</li>
      </ul>
    </div>

    <div class="lk-dropdown" id="lk-filter-period">
      <button class="lk-dropdown__toggle" type="button">Период ▾</button>
      <ul class="lk-dropdown__menu">
        <li data-period="all">За всё время</li>
        <li data-period="7d">За последние 7 дней</li>
        <li data-period="1m">За последний месяц</li>
      </ul>
    </div>
  </div>

  <?php foreach ($groups as $date => $rows): ?>
    <div class="lk-history-day">
      <div class="lk-history-day__title"><?= esc_html($ruDate($date)) ?></div>
      <ul class="lk-history-list">
        <?php foreach ($rows as $row):
          $amount = (float)($row->value ?? $row->amount ?? 0);
          $type   = $amount < 0 ? 'debit' : ($amount > 0 ? 'credit' : 'zero');
          $title  = (string)($row->description ?? $row->name ?? 'Операция');
          $account= (string)($row->__projectName ?? '');
          $rowDate= (string)($row->date ?? $date);
        ?>
          <li class="lk-history-item"
              data-type="<?= esc_attr($type) ?>"
              data-amount="<?= esc_attr($amount) ?>"
              data-date="<?= esc_attr($rowDate) ?>">
            <div class="lk-history-item__title">
              <span class="lk-history-item__icon" aria-hidden="true"></span>
              <?= esc_html($title) ?>
            </div>

            <div class="lk-history-item__meta">
              <?php if ($account !== ''): ?>
                <div class="lk-history-item__account"><?= esc_html($account) ?></div>
              <?php endif; ?>
              <div class="lk-history-item__amount <?= $amount < 0 ? 'is-negative' : 'is-positive' ?>">
                <?= $fmtMoney($amount) ?>
              </div>
            </div>

            <?php if ($amount < 0): ?>
              <a href="#"
                 class="lk-history-item__pay"
                 onclick="LKPay.open({project:'<?= esc_js($account ?: 'АНОО') ?>', amountRub:<?= esc_js(abs((float)$amount)) ?>, purpose:'<?= esc_js($title) ?>'}); return false;">
                 Код для оплаты
              </a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
</section>

<script>
(function(){
  const root = document.getElementById('lk-history');
  if (!root) return;

  // dropdowns
  document.querySelectorAll('.lk-dropdown').forEach(dd=>{
    const btn = dd.querySelector('.lk-dropdown__toggle');
    btn?.addEventListener('click', ()=> dd.classList.toggle('open'));
    dd.addEventListener('mouseleave', ()=> dd.classList.remove('open'));
  });

  let filterType = 'all';
  let periodMode = 'all';

  document.querySelectorAll('#lk-filter-type [data-filter]').forEach(li=>{
    li.addEventListener('click', ()=>{
      filterType = li.dataset.filter || 'all';
      li.closest('.lk-dropdown').classList.remove('open');
      apply();
    });
  });
  document.querySelectorAll('#lk-filter-period [data-period]').forEach(li=>{
    li.addEventListener('click', ()=>{
      periodMode = li.dataset.period || 'all';
      li.closest('.lk-dropdown').classList.remove('open');
      apply();
    });
  });

  const parseISO = s => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s||''));
    return m ? new Date(+m[1], +m[2]-1, +m[3]) : null;
  };
  function inPeriod(iso) {
    if (periodMode==='all') return true;
    const d = parseISO(iso);
    if (!d) return true;
    const today = new Date(); today.setHours(0,0,0,0);
    if (periodMode==='7d') {
      const start = new Date(today); start.setDate(start.getDate()-6);
      return d>=start && d<=today;
    }
    if (periodMode==='1m') {
      const start = new Date(today); start.setMonth(start.getMonth()-1);
      return d>=start && d<=today;
    }
    return true;
  }

  function apply(){
    const items = root.querySelectorAll('.lk-history-item');
    items.forEach(li=>{
      const amt = parseFloat(li.dataset.amount||'0');
      const iso = li.dataset.date||'';
      const type = li.dataset.type||'zero';
      const okType = (filterType==='all') || (filterType==='debit' && amt<0) || (filterType==='credit' && amt>0);
      const okPeriod = inPeriod(iso);
      li.style.display = (okType && okPeriod) ? '' : 'none';
    });
    // скрывать дни без записей
    root.querySelectorAll('.lk-history-day').forEach(day=>{
      const any = Array.from(day.querySelectorAll('.lk-history-item')).some(li=>li.style.display!=='none');
      day.style.display = any ? '' : 'none';
    });
  }
  apply();
})();
</script>
