<?php get_header(); ?>
  <div class="lk-container">
    <?php
      if (have_posts()) :
        while (have_posts()) : the_post();
          the_content();
        endwhile;
      else:
        echo '<p>Пусто</p>';
      endif;
    ?>
  </div>
<?php get_footer(); ?>
