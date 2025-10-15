<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
  $is_auth = is_page_template('page-login.php')
          || is_page_template('page-recovery.php')
          || is_page_template('Page Registration.php');
?>
<?php if ($is_auth): ?>
  <main class="lk-wrap">
<?php endif; ?>