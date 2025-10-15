<?php
// inc/lk-helpers.php
if (!function_exists('lk_money')) {
  function lk_money($v){ $n = is_numeric($v) ? (float)$v : 0.0; return number_format($n, 0, ',', ' ') . ' ₽'; }
}
if (!function_exists('lk_ru_month')) {
  function lk_ru_month($n){ static $m=['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря']; $n=(int)$n; return $m[$n-1]??''; }
}
if (!function_exists('lk_human_date')) {
  function lk_human_date($iso){ $ts=strtotime($iso); if(!$ts) return esc_html((string)$iso); return date('j ', $ts).lk_ru_month((int)date('n',$ts)); }
}
