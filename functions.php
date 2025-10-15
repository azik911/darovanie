<?php
// =========================
//   functions.php (Darovanie LK)
// =========================

// Безопасность
if (!defined('ABSPATH')) exit;
error_log("✅ functions.php загружен Дарование");

// =========================
//   Подключение стилей и скриптов
// =========================
function school_enqueue_assets() {
    error_log("🎨 Инициализация подключения стилей и скриптов");

    // Google Fonts
    wp_enqueue_style(
        'darovanie-fonts',
        'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Merriweather:wght@700&display=swap',
        [],
        null
    );

    // Основной стиль темы
    $style_path = get_stylesheet_directory() . '/style.css';
    wp_enqueue_style(
        'theme-style',
        get_stylesheet_uri(),
        ['darovanie-fonts'],
        file_exists($style_path) ? filemtime($style_path) : time()
    );

    // Стили личного кабинета
    if (is_page_template('page-cabinet.php')) {
        $cabinet_path = get_template_directory() . '/cabinet.css';
        wp_enqueue_style(
            'cabinet-style',
            get_template_directory_uri() . '/cabinet.css',
            ['theme-style'],
            file_exists($cabinet_path) ? filemtime($cabinet_path) : time()
        );
    }

    // Inputmask
    wp_enqueue_script(
        'inputmask',
        'https://unpkg.com/inputmask@5.0.8/dist/inputmask.min.js',
        [],
        null,
        true
    );

    // Основной JS
    $app_js = get_template_directory() . '/assets/app.js';
    if (file_exists($app_js)) {
        wp_enqueue_script(
            'lk-js',
            get_template_directory_uri() . '/assets/app.js',
            [],
            filemtime($app_js),
            true
        );
    }

    error_log("✅ Стили и скрипты подключены");
}
add_action('wp_enqueue_scripts', 'school_enqueue_assets', 99);

// =========================
//   Поддержка <title>
// =========================
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    error_log("🧩 Тема поддерживает <title>");
});

// =========================
//   Сессии и хелперы
// =========================
function lk_set_session_id($sessionId) {
    if (!session_id()) session_start();
    $_SESSION['lk_session_id'] = $sessionId;
}

function lk_get_session_id() {
    if (!session_id()) session_start();
    return $_SESSION['lk_session_id'] ?? null;
}

function lk_clear_session_id() {
    if (!session_id()) session_start();
    unset($_SESSION['lk_session_id']);
}

// =========================
//   SOAP / API
// =========================
error_log('LkApi path: ' . get_template_directory() . '/tools/LkApi.php');

require_once get_template_directory() . '/tools/LkApi.php';
require_once get_template_directory() . '/tools/LkService.php';

function lk_api(): LkApi {
    static $api = null;
    if ($api) return $api;

    require_once get_template_directory() . '/tools/LkApi.php';
    $api = new LkApi();

    // 🧠 Восстанавливаем сессию пользователя, если она сохранена
    $user_id = get_current_user_id();
    if ($user_id) {
        $session = get_user_meta($user_id, '_lkapi_session', true);
        if (is_array($session) && !empty($session['sessionID']) && !empty($session['sessionHash'])) {
            $api->restoreSession($session);
            error_log('🔁 SOAP session restored: ' . print_r($session, true));
        }
    }

    return $api;
}



// =========================
//   Выход из личного кабинета
// =========================
add_action('init', function () {
    if (isset($_GET['lk_logout']) && $_GET['lk_logout'] == '1') {
        error_log("🚪 Пользователь вышел из ЛК");
        lk_clear_session_id();
        wp_safe_redirect(home_url('/'));
        exit;
    }
});

// =========================
//   ВОССТАНОВЛЕНИЕ / РЕГИСТРАЦИЯ (через SOAP Register/ConfirmRegistration)
// =========================

// DEBUG регистрация хуков
error_log("🧩 Регистрирую AJAX хуки lk_send_recovery_code и lk_recover_confirm");

// AJAX: Отправка SMS с кодом (в мобильном API это Register)
add_action('wp_ajax_nopriv_lk_send_recovery_code', 'lk_send_recovery_code');
add_action('wp_ajax_lk_send_recovery_code', 'lk_send_recovery_code');

function lk_send_recovery_code() {
    error_log("🚀 AJAX вызван: lk_send_recovery_code");
    error_log("📞 POST DATA: " . print_r($_POST, true));

    $phone    = sanitize_text_field($_POST['phone'] ?? '');
    $password = sanitize_text_field($_POST['password'] ?? '');

    if (!$phone || !$password) {
        error_log("❌ Не переданы обязательные поля phone/password");
        wp_send_json_error(['message' => 'Укажите телефон и новый пароль']);
        return;
    }

    try {
        // как в мобильном приложении: hash = MD5(password + "Darovanie")
        $hash = md5($password . 'Darovanie');
        error_log("🔐 Генерация hash для $phone: $hash");

        $api = lk_api();
        error_log("📡 SOAP вызов: Register($phone)");

        $res = $api->call('Register', [
            'phone'    => $phone,
            'password' => $hash
        ]);
        
        // Если пришёл объект, вытаскиваем результат:
        if (is_object($res) && isset($res->RegisterResult)) {
            $res = $res->RegisterResult;
        }

        error_log("📨 Ответ Register: " . print_r($res, true));

        if (is_numeric($res) && $res >= 0) {
            wp_send_json_success(['code' => $res]);
        } else {
            wp_send_json_error(['message' => "Ошибка регистрации (код $res)"]);
        }
    } catch (Throwable $e) {
        error_log("💥 Ошибка в lk_send_recovery_code: " . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// AJAX: Подтверждение кода из SMS (ConfirmRegistration)
add_action('wp_ajax_nopriv_lk_recover_confirm', 'lk_recover_confirm');
function lk_recover_confirm() {
    error_log("🚀 AJAX вызван: lk_recover_confirm");

    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $code  = sanitize_text_field($_POST['code'] ?? '');

    if (!$phone || !$code) {
        error_log("❌ Не переданы обязательные поля phone/code");
        wp_send_json_error(['message' => 'Укажите телефон и код из SMS']);
        return;
    }

    try {
        $api = lk_api();
        error_log("📡 SOAP вызов: ConfirmRegistration($phone, $code)");

        $res = $api->call('Register', [
            'phone'        => $phone,
            'passwordHash' => $hash,
            'deviceType'   => 'web',
            'appVersion'   => '1.0'
        ]);
        error_log("📨 Ответ ConfirmRegistration: " . print_r($res, true));

        if ($res == 0) {
            error_log("📩 SMS запрос успешно выполнен (ожидается реальная отправка на номер {$phone})");
            wp_send_json_success(['message' => 'Код отправлен. Проверьте SMS.', 'code' => 0]);
        } elseif ($res == -1) {
            wp_send_json_error(['message' => 'Номер не найден в системе']);
        } elseif ($res == -2) {
            wp_send_json_error(['message' => 'Ошибка SMS-шлюза']);
        } elseif ($res == -3) {
            wp_send_json_error(['message' => 'Нет прав для восстановления']);
        } else {
            wp_send_json_error(['message' => "Неизвестная ошибка (код $res)"]);
        }
    } catch (Throwable $e) {
        error_log("💥 Ошибка в lk_recover_confirm: " . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// =========================
//   DEBUG: проверка регистрации хуков
// =========================
add_action('init', function() {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        error_log("🔥 AJAX запущен. Проверка наличия хука lk_send_recovery_code:");
        if (isset($GLOBALS['wp_filter']['wp_ajax_nopriv_lk_send_recovery_code'])) {
            error_log("✅ Найден action wp_ajax_nopriv_lk_send_recovery_code");
        } else {
            error_log("⚠️ Action wp_ajax_nopriv_lk_send_recovery_code НЕ найден!");
        }
    }
});


// =========================
//   РЕГИСТРАЦИЯ
// =========================
// =========================
//   РЕГИСТРАЦИЯ
// =========================
add_action('wp_ajax_nopriv_lk_send_register_code', 'lk_send_register_code');
add_action('wp_ajax_lk_send_register_code', 'lk_send_register_code');

function lk_send_register_code() {
    error_log("🚀 AJAX вызван: lk_send_register_code");
    error_log("📞 POST DATA: " . print_r($_POST, true));

    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $password = sanitize_text_field($_POST['password'] ?? '');

    if (!$phone || !$password) {
        wp_send_json_error(['message' => 'Укажите телефон и пароль']);
        return;
    }

    try {
        $hash = md5($password . 'Darovanie');
        error_log("🔐 Генерация hash для $phone: $hash");

        $api = lk_api();
        error_log("📡 SOAP вызов: Register($phone)");

        $res = $api->call('Register', ['phone' => $phone, 'password' => $hash]);
        error_log("🧠 Ответ SOAP: " . print_r($res, true));

        if (is_object($res) && isset($res->RegisterResult)) {
            $res = $res->RegisterResult;
        }

        if ($res >= 0) {
            wp_send_json_success(['message' => "Код отправлен (ответ: $res)"]);
        } else {
            wp_send_json_error(['message' => "Ошибка регистрации (код $res)"]);
        }
    } catch (Throwable $e) {
        error_log("💥 Ошибка регистрации: " . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// =========================
//   ПОДТВЕРЖДЕНИЕ РЕГИСТРАЦИИ
// =========================
add_action('wp_ajax_nopriv_lk_register_confirm', 'lk_register_confirm');
add_action('wp_ajax_lk_register_confirm', 'lk_register_confirm');

function lk_register_confirm() {
    error_log("🚀 AJAX вызван: lk_register_confirm");
    error_log("📞 POST DATA: " . print_r($_POST, true));

    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $code  = sanitize_text_field($_POST['code'] ?? '');
    $password = sanitize_text_field($_POST['password'] ?? '');

    if (!$phone || !$code) {
        wp_send_json_error(['message' => 'Не указан телефон или код']);
        return;
    }

    try {
        $api = lk_api();
        error_log("📡 SOAP вызов: ConfirmRegistration($phone, $code)");

        $res = $api->call('ConfirmRegistration', [
            'phone' => $phone,
            'code'  => $code
        ]);
        error_log("🧠 Ответ SOAP: " . print_r($res, true));

        if (is_object($res) && isset($res->ConfirmRegistrationResult)) {
            $res = $res->ConfirmRegistrationResult;
        }

        if ($res == 0) {
            error_log("✅ Регистрация подтверждена, выполняем вход...");

            // 🔐 Авторизация
            $hash = md5($password . 'Darovanie');
            $login = $api->call('Login', ['phone' => $phone, 'password' => $hash]);
            error_log("🔑 Login result: " . print_r($login, true));

            if (is_object($login) && isset($login->LoginResult)) {
                $login = $login->LoginResult;
            }

            if ($login > 0) {
                lk_set_session_id($login);
                wp_send_json_success(['message' => 'Регистрация успешно подтверждена']);
            } else {
                wp_send_json_error(['message' => "Ошибка авторизации после регистрации (код $login)"]);
            }
        } elseif ($res == -2) {
            wp_send_json_error(['message' => 'Неверный код из SMS']);
        } elseif ($res == -3) {
            wp_send_json_error(['message' => 'Нет прав для подтверждения']);
        } else {
            wp_send_json_error(['message' => "Ошибка регистрации (код $res)"]);
        }
    } catch (Throwable $e) {
        error_log("💥 Ошибка в lk_register_confirm: " . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}



add_action('wp_ajax_lk_send_question', 'lk_send_question');
add_action('wp_ajax_nopriv_lk_send_question', 'lk_send_question');

function lk_send_question() {
    $message = sanitize_text_field($_POST['message'] ?? '');
    $transactionID = (int)($_POST['transactionID'] ?? 0);

    if (!$message) {
        wp_send_json_error(['message' => 'Введите текст вопроса.']);
    }

    $api = lk_api();
    $session = $api->getSession();
    if (empty($session['sessionID']) || empty($session['sessionHash'])) {
        wp_send_json_error(['message' => 'Нет активной сессии. Попробуйте войти заново.']);
    }

    try {
        $res = $api->call('SendQuestion', [
            'sessionID' => $session['sessionID'],
            'hash' => $session['sessionHash'],
            'message' => $message,
            'transactionID' => $transactionID,
        ]);

        $result = (int)($res->SendQuestionResult ?? -999);
        if ($result === 0) {
            wp_send_json_success(['message' => 'Вопрос отправлен бухгалтерии.']);
        } else {
            wp_send_json_error(['message' => "Ошибка при отправке (код $result)"]);
        }
    } catch (Throwable $e) {
        error_log('💥 SOAP SendQuestion error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Ошибка связи с сервером.']);
    }
}
