<?php
/* Template Name: Вход (LK Login) */
if (!defined('ABSPATH')) exit;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $phone = $_POST['phone'] ?? '';
  $password = $_POST['password'] ?? '';

  if (empty($phone)) {
    $error = 'Введите телефон.';
  } elseif (empty($password)) {
    $error = 'Введите пароль.';
  } elseif (!isset($_POST['lk_login_nonce']) || !wp_verify_nonce($_POST['lk_login_nonce'], 'lk_login')) {
    $error = 'Сессия формы истекла. Обновите страницу.';
  } else {
    try {
      $api = lk_api();
      $sid = $api->login($phone, $password);

      if ($sid > 0) {
        // ✅ Успешный вход

        // 1. Сохраняем sessionID в старую систему (если используется где-то)
        lk_set_session_id($sid);

        // 2. 🔹 Сохраняем SOAP-сессию (sessionID + hash) в user_meta
        $session = $api->getSession();
        if (!empty($session['sessionID']) && !empty($session['sessionHash'])) {
          update_user_meta(get_current_user_id(), '_lkapi_session', $session);
          error_log('✅ SOAP session saved: ' . print_r($session, true));
        } else {
          error_log('⚠️ SOAP session missing in login response');
        }

        // 3. Перенаправление в кабинет
        $cabinet = get_page_by_path('личный-кабинет') ?: get_page_by_path('cabinet');
        wp_safe_redirect($cabinet ? get_permalink($cabinet) : home_url('/'));
        exit;
      } else {
        // ❌ Ошибка логина — интерпретируем код
        switch ($sid) {
          case -1:
            $error = "Ваш телефон не зарегистрирован в системе.";
            break;
          case -2:
            $error = "Неверный пароль. Попробуйте ещё раз.";
            break;
          case -3:
            $error = "Ваша учётная запись заблокирована.";
            break;
          default:
            $error = "Ошибка входа (код $sid). Попробуйте позже.";
        }
      }
    } catch (Throwable $e) {
      error_log('LK Login SOAP: ' . $e->getMessage());
      $error = 'Ошибка связи с сервером. Попробуйте позже.';
    }
  }
}

get_header(); ?>


<div class="lk-wrap">

    <div class="lk-auth">
      <img class="lk-logo" src="<?php echo get_template_directory_uri(); ?>/assets/logo.png" alt="Дарование" />
      <h1 class="lk-auth__title">Вход в личный кабинет</h1>

      <form id="lkLoginForm" class="lk-form" method="POST" novalidate>
        <?php wp_nonce_field('lk_login', 'lk_login_nonce'); ?>

        <!-- Телефон -->
        <div class="lk-form__group">
          <label class="sr-only" for="lk_phone">Ваш логин (телефон)*</label>
          <input id="lk_phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
                placeholder="Ваш логин (телефон)*" required
                value="<?php echo esc_attr($_POST['phone'] ?? ''); ?>">
        </div>

        <!-- Пароль -->
        <div class="lk-form__group lk-password">
          <label class="sr-only" for="lk_password">Пароль*</label>
          <input id="lk_password" name="password" type="password" autocomplete="current-password"
                placeholder="Пароль*" required>
          <button class="lk-eye" type="button" aria-label="Показать пароль"></button>
        </div>

        <!-- Чекбокс и ссылка -->
        <div class="lk-form__row">
          <label class="lk-checkbox">
            <input type="checkbox" name="remember"> Запомнить меня
          </label>
          <?php
          $recovery = get_page_by_path('восстановление-доступа') ?: get_page_by_path('vosstanovlenie-dostupa');
          $recovery_url = $recovery ? get_permalink($recovery) : home_url('/');
          ?>
          <a class="lk-link" href="<?php echo esc_url($recovery_url); ?>">Восстановить пароль</a>
        </div>

        <!-- Кнопки -->
        <div class="lk-form__actions">
          <button class="lk-btn lk-btn--primary" id="lkSubmit" type="submit">Войти</button>
          <a class="lk-btn lk-btn--ghost" href="<?php echo get_permalink(get_page_by_path('регистрация')); ?>">
            Зарегистрироваться
          </a>
        </div>

        <?php if ($error): ?>
          <div class="lk-form__error" style="margin-top:10px;"><?php echo esc_html($error); ?></div>
        <?php endif; ?>

      </form>
    </div>
 
</div>
<?php get_footer();
