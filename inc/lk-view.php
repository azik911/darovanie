<?php
function lk_render_children(array $kids, array $kidNames = []): void {
  $tpl = get_stylesheet_directory() . '/templates/lk/children.php';

  // Fallback, если шаблон отсутствует — рисуем базовую секцию здесь
  if (!file_exists($tpl)) {
    error_log('lk_render_children: children.php not found at ' . $tpl);
    $toRender = [];

    if (!empty($kids)) {
      foreach ($kids as $name => $sum) $toRender[] = ['name'=>(string)$name, 'sum'=>(float)$sum];
    } elseif (!empty($kidNames)) {
      foreach ($kidNames as $name) if (($name=trim((string)$name))!=='') $toRender[] = ['name'=>$name, 'sum'=>0.0];
    }

    echo '<section class="lk-section"><h2 class="lk-section__title">Дети</h2><div class="lk-cards">';
    if (empty($toRender)) {
      echo '<div class="lk-card lk-kid"><div class="lk-kid__name">Детей не найдено</div><div class="lk-kid__balance">0 ₽</div></div>';
    } else {
      foreach ($toRender as $row) {
        $name = esc_html($row['name']);
        $sum  = (float)$row['sum'];
        $cls  = $sum > 0 ? 'is-positive' : ($sum < 0 ? 'is-negative' : '');
        echo '<div class="lk-card lk-kid" title="'.$name.'">';
        echo '<div class="lk-kid__name">'.$name.'</div>';
        echo '<div class="lk-kid__balance '.$cls.'">'.($sum<0 ? '−' : '').number_format(abs($sum),0,',',' ').' ₽</div>';
        echo '</div>';
      }
    }
    echo '</div></section>';
    return;
  }

  include $tpl;
}

function lk_render_accounts(array $projects): void {
  $tpl = get_stylesheet_directory() . '/templates/lk/accounts.php';
  if (!file_exists($tpl)) { error_log('lk_render_accounts: accounts.php not found'); return; }
  include $tpl;
}

function lk_render_history(array $groups): void {
  $tpl = get_stylesheet_directory() . '/templates/lk/history.php';
  if (!file_exists($tpl)) { error_log('lk_render_history: history.php not found'); return; }
  include $tpl;
}
