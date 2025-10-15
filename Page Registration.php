<?php /* Template Name: Registration Page */ ?>
<?php get_header(); ?>

<div class="lk-wrap">
  <div class="lk-auth">
    <img class="lk-logo" src="<?php echo get_template_directory_uri(); ?>/assets/logo.png" alt="Логотип">

    <h2 class="lk-auth__title">Регистрация</h2>

    <div class="lk-help" style="margin-bottom: 16px;">
      Уже есть аккаунт? <a href="/school_lk/login/" class="lk-link">Войти</a>
    </div>

    <form class="lk-form" onsubmit="return confirmRegister(event)">
      <!-- Телефон -->
      <div class="lk-form__group">
        <div class="lk-phone-code">
          <input id="reg-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7 ___ ___ __ __" required>
          <button type="button" class="lk-btn lk-btn--primary" id="getCodeBtn">Получить код</button>
        </div>
      </div>

      <!-- Код подтверждения -->
      <div class="lk-form__group">
        <input id="reg-code" name="code" type="text" inputmode="numeric" placeholder="Код подтверждения из SMS*" required>
      </div>

      <!-- Логин -->
      <div class="lk-form__group">
        <input id="reg-username" name="username" type="text" placeholder="Логин*" required>
      </div>

      <!-- Пароль -->
      <div class="lk-form__group">
        <div class="lk-password">
          <input id="reg-password" name="password" type="password" placeholder="Пароль*" required>
          <button type="button" class="lk-eye" onclick="togglePassword('reg-password')" tabindex="-1"></button>
        </div>
        <div id="password-error" class="lk-form__error" style="display:none;">Пароль не соответствует требованиям.</div>
      </div>

      <div class="lk-help">
        Пароль должен содержать от 6 до 15 латинских символов и цифр (без спецсимволов).
      </div>

      <!-- Повтор пароля -->
      <div class="lk-form__group">
        <div class="lk-password">
          <input id="reg-password-repeat" type="password" placeholder="Повторите пароль*" required>
          <button type="button" class="lk-eye" onclick="togglePassword('reg-password-repeat')" tabindex="-1"></button>
        </div>
        <div id="match-error" class="lk-form__error" style="display:none;">Пароли не совпадают.</div>
      </div>

      <div class="lk-form__row">
        <label style="font-size:13px">
          <input type="checkbox" required> Даю согласие на обработку моих персональных данных
        </label>
      </div>

      <div class="lk-form__actions">
        <button type="submit" class="lk-btn lk-btn--primary">Зарегистрироваться</button>
      </div>
    </form>

    <!-- DEBUG панель -->
    <?php if ( current_user_can('administrator') || strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ) : ?>
      <div id="debug-panel" style="background:#0d1117;color:#9efc9e;font-family:monospace;font-size:13px;padding:10px;margin-top:20px;border-radius:8px;max-height:250px;overflow:auto;">
        <div style="color:#58a6ff;margin-bottom:6px;">🧩 DEBUG LOG (AJAX)</div>
        <div id="debug-log">Ожидание действий...</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
const AJAX_URL = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";

function logDebug(msg, color = '#9efc9e') {
  const log = document.getElementById('debug-log');
  if (!log) return;
  const line = document.createElement('div');
  line.innerHTML = `<span style="color:${color}">${msg}</span>`;
  log.prepend(line);
}

// Показ/скрытие пароля
function togglePassword(id) {
  const input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
}

// Формат номера
function formatPhone(v) {
  const x = v.replace(/\D/g,'').match(/(\d?)(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
  return '+7 ' +
    (x[2] ? '(' + x[2] : '') +
    (x[3] ? ') ' + x[3] : '') +
    (x[4] ? '-' + x[4] : '') +
    (x[5] ? '-' + x[5] : '');
}

document.addEventListener('DOMContentLoaded', function () {
  const phoneInput = document.getElementById('reg-phone');
  const getCodeBtn = document.getElementById('getCodeBtn');

  function updateBtnState() {
    const ready = phoneInput.value.length >= 18;
    if (!getCodeBtn.dataset.timer) {
      getCodeBtn.disabled = !ready;
    }
  }

  phoneInput.addEventListener('input', e => {
    e.target.value = formatPhone(e.target.value);
    updateBtnState();
  });

  updateBtnState();

  // Отправка кода
  getCodeBtn.addEventListener('click', async () => {
    const phone = phoneInput.value.trim();
    const password = document.getElementById('reg-password').value.trim();

    if (!password) {
      alert('Введите пароль перед получением кода.');
      return;
    }

    logDebug(`📤 Отправка запроса lk_send_register_code<br>📱 ${phone}`, '#58a6ff');
    getCodeBtn.disabled = true;
    getCodeBtn.textContent = 'Отправка...';

    try {
      const resp = await fetch(AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({ action: 'lk_send_register_code', phone, password })
      });

      const data = await resp.json();
      logDebug(`📨 RAW RESPONSE:<br>${JSON.stringify(data)}`, '#fca');

      if (data.success) {
        alert('Код отправлен!');
        startTimer();
      } else {
        alert(data.data?.message || 'Ошибка отправки кода');
        getCodeBtn.disabled = false;
        getCodeBtn.textContent = 'Получить код';
      }

    } catch (e) {
      alert('Ошибка соединения.');
      logDebug(`💥 Ошибка: ${e.message}`, '#ff6');
      getCodeBtn.disabled = false;
      getCodeBtn.textContent = 'Получить код';
    }
  });

  // Таймер 60 секунд
  function startTimer() {
    let seconds = 60;
    getCodeBtn.dataset.timer = "true";
    getCodeBtn.textContent = `Ожидайте(${seconds})`;

    const timer = setInterval(() => {
      seconds--;
      getCodeBtn.textContent = `Ожидайте(${seconds})`;

      if (seconds <= 0) {
        clearInterval(timer);
        getCodeBtn.disabled = false;
        getCodeBtn.dataset.timer = "";
        getCodeBtn.textContent = "Получить код";
        updateBtnState();
      }
    }, 1000);
  }
});

// Подтверждение регистрации
async function confirmRegister(e) {
  e.preventDefault();

  const password = document.getElementById('reg-password').value.trim();
  const repeat = document.getElementById('reg-password-repeat').value.trim();
  const phone = document.getElementById('reg-phone').value.trim();
  const code = document.getElementById('reg-code').value.trim();

  const pattern = /^[A-Za-z0-9]{6,15}$/; // как на сервере

  if (!pattern.test(password)) {
    alert('Пароль не соответствует требованиям (только латиница и цифры, 6–15 символов).');
    logDebug('❌ Неверный формат пароля', '#ff6');
    return false;
  }

  if (password !== repeat) {
    alert('Пароли не совпадают.');
    logDebug('❌ Пароли не совпадают', '#ff6');
    return false;
  }

  logDebug(`📤 Отправка запроса lk_register_confirm<br>📱 ${phone} | Код: ${code}`, '#58a6ff');

  try {
    const resp = await fetch(AJAX_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: new URLSearchParams({ action: 'lk_register_confirm', phone, code, password })
    });

    const data = await resp.json();
    logDebug(`📨 RAW RESPONSE:<br>${JSON.stringify(data)}`, '#fca');

    if (data.success) {
      alert('Регистрация успешно завершена!');
      logDebug('✅ Регистрация успешна, переход...', '#9efc9e');
      window.location.href = '/school_lk/cabinet/';
    } else {
      alert(data.data?.message || 'Ошибка при подтверждении регистрации');
    }

  } catch (e) {
    logDebug(`💥 Ошибка AJAX: ${e.message}`, '#ff6');
    alert('Ошибка соединения.');
  }
}
</script>

<?php get_footer(); ?>
