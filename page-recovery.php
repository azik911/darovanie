<?php /* Template Name: Recovery Page */ ?>
<?php get_header(); ?>

<div class="lk-wrap">
  <div class="lk-auth">
    <img class="lk-logo" src="<?php echo get_template_directory_uri(); ?>/assets/logo.png" alt="Логотип">

    <h2 class="lk-auth__title">Восстановление доступа</h2>

    <div class="lk-help" style="margin-bottom:16px;">
      <a href="/school_lk/login/" class="lk-link">Вернуться к авторизации</a>
    </div>

    <form class="lk-form" onsubmit="return validateRecoveryForm(event)">
      <div class="lk-form__group">
        <label class="sr-only" for="lk-rec-phone">Мобильный телефон</label>

        <div class="lk-phone-code">
          <input
            type="tel"
            id="lk-rec-phone"
            name="phone"
            placeholder="+7"
            inputmode="tel"
            autocomplete="tel"
            required
          >
          <button
            type="button"
            id="lk-rec-getcode"
            class="lk-btn lk-btn--primary"
            data-context="recovery"
            data-wait="60"
          >Получить код</button>
        </div>
      </div>

      <div class="lk-form__group">
        <input
          type="text"
          id="lk-rec-code"
          name="code"
          placeholder="Код подтверждения из sms*"
          required
          inputmode="numeric"
          autocomplete="one-time-code"
        >
      </div>

      <div class="lk-form__group">
        <div class="lk-password">
          <input id="password" type="password" placeholder="Пароль*" required>
          <button type="button" class="lk-eye" onclick="togglePassword('password')" tabindex="-1"></button>
        </div>
        <div id="password-error" class="lk-form__error" style="display:none;">Пароль не соответствует требованиям.</div>
      </div>

      <div class="lk-help">
        Пароль должен содержать от 8 до 15 символов, включая как минимум 1 заглавную букву, 1 строчную
        букву, 1 цифру и 1 специальный символ ($@#!%*&).
      </div>

      <div class="lk-form__group">
        <div class="lk-password">
          <input id="repeat-password" type="password" placeholder="Повторите пароль*" required>
          <button type="button" class="lk-eye" onclick="togglePassword('repeat-password')" tabindex="-1"></button>
        </div>
        <div id="match-error" class="lk-form__error" style="display:none;">Пароли не совпадают.</div>
      </div>

      <div class="lk-form__row">
        <label style="font-size:13px">
          <input type="checkbox" required>
          Даю согласие на обработку моих персональных данных
        </label>
      </div>

      <div class="lk-form__actions">
        <button type="submit" class="lk-btn lk-btn--primary" id="recover-confirm">Восстановить доступ</button>
      </div>
    </form>

    <!-- Отладка (вынес из формы) -->
    <div id="lk-debug" class="lk-debug" hidden></div>
    <style>
      .lk-debug{
        margin-top:12px;
        font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size:12px; background:#0b1220; color:#d1e0ff;
        padding:10px; border-radius:8px; max-height:240px; overflow:auto;
      }
      .lk-debug b{ color:#93c5fd; }
      .lk-debug pre{ white-space:pre-wrap; margin:6px 0; }
    </style>
  </div>
</div>

<script>
  // Маска телефона
  document.addEventListener('DOMContentLoaded', function () {
    const phoneInput = document.getElementById('lk-rec-phone');
    if (phoneInput) {
      phoneInput.addEventListener('input', function (e) {
        const digits = e.target.value.replace(/\D/g, '');
        const x = digits.match(/(\d{0,1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
        e.target.value = '+7 ' +
          (x[2] ? '(' + x[2] : '') +
          (x[3] ? ') ' + x[3] : '') +
          (x[4] ? '-' + x[4] : '') +
          (x[5] ? '-' + x[5] : '');
      });
    }
  });

  function togglePassword(id) {
    const input = document.getElementById(id);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
  }

  // Правильный URL admin-ajax (учитывает подкаталог)
  const AJAX_URL = "<?php echo esc_url( admin_url('admin-ajax.php') ); ?>";

  // Отладка
  function dbg(title, data){
    const box = document.getElementById('lk-debug');
    if (!box) return;
    box.hidden = false;
    const time = new Date().toLocaleTimeString();
    const wrap = document.createElement('div');
    wrap.innerHTML =
      `<b>[${time}] ${title}</b>` +
      (data !== undefined ? `<pre>${escapeHtml(typeof data === 'string' ? data : JSON.stringify(data, null, 2))}</pre>` : '');
    box.appendChild(wrap);
  }
  function escapeHtml(str){ return String(str).replace(/[&<>]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[s])); }

  // AJAX helper с логом сырого ответа
  const ajax = async (action, data) => {
    const payload = new URLSearchParams({action, ...data});
    dbg('REQUEST', { url: AJAX_URL, action, data });

    let res, text;
    try {
      res  = await fetch(AJAX_URL, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        credentials: 'same-origin',
        body: payload
      });
      text = await res.text();
    } catch (e) {
      dbg('NETWORK ERROR', String(e));
      throw e;
    }

    dbg('RAW RESPONSE', text);

    try {
      return JSON.parse(text);
    } catch (e) {
      const t = text.trim();
      if (t === '0')  dbg('WP HINT', 'Ответ "0" — экшен не найден или был вывод до wp_send_json_*.');
      if (t === '-1') dbg('WP HINT', 'Ответ "-1" — чаще всего неверный nonce.');
      return { success:false, data:{ message:'Unexpected response', raw: text } };
    }
  };

  // Сабмит формы
  async function validateRecoveryForm(e) {
    if (e) e.preventDefault();

    const password = document.getElementById('password').value.trim();
    const repeat   = document.getElementById('repeat-password').value.trim();
    const passwordError = document.getElementById('password-error');
    const matchError    = document.getElementById('match-error');

    passwordError.style.display = 'none';
    matchError.style.display = 'none';

    const pattern = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[$@#!%*&])[A-Za-z\d$@#!%*&]{8,15}$/;

    let valid = true;
    if (!pattern.test(password)) { passwordError.style.display = 'block'; valid = false; }
    if (password !== repeat)     { matchError.style.display    = 'block'; valid = false; }
    if (!valid) return false;

    try {
      const phone = document.querySelector('[name="phone"]').value.trim();
      const code  = document.querySelector('[name="code"]').value.trim();
      dbg('CONFIRM START', { phone, code });

      const resp = await ajax('lk_recover_confirm', { phone, code, password });
      dbg('CONFIRM RESPONSE', resp);

      if (resp.success && (resp.data?.code ?? 0) >= 0) {
        window.location.href = '/school_lk/login/';
      } else {
        alert(resp.data?.message || 'Не удалось восстановить доступ.');
      }
    } catch (err) {
      dbg('CONFIRM ERROR', String(err));
      alert('Ошибка соединения. Повторите попытку.');
    }
    return false;
  }

  // Отправка SMS + таймер
  function smsSender(){
    const btn   = document.getElementById('lk-rec-getcode');
    const phone = document.getElementById('lk-rec-phone');
    if (!btn || !phone) return;

    let left = 0, timer = null;
    const totalWait = parseInt(btn.getAttribute('data-wait') || '60', 10);

    function setDisabled(state){
      btn.disabled = state;
      if (state) btn.classList.add('is-disabled'); else btn.classList.remove('is-disabled');
    }
    function tick(){
      left--;
      if (left <= 0) {
        clearInterval(timer); timer = null;
        btn.textContent = 'Получить код';
        setDisabled(false);
      } else {
        btn.textContent = `Отправлено (${left}с)`;
      }
    }
    } 




    async function send(){
  const raw = (phone.value || '').trim();

  // Берём пароль уже сейчас:
  const password = document.getElementById('password').value.trim();
  const repeat   = document.getElementById('repeat-password').value.trim();

  const pattern = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[$@#!%*&])[A-Za-z\d$@#!%*&]{8,15}$/;
  if (!pattern.test(password)) { alert('Пароль не соответствует требованиям'); return; }
  if (password !== repeat)     { alert('Пароли не совпадают'); return; }

  // Отправляем телефон + ПАРОЛЬ на сервер (на этом шаге):
  const resp = await ajax('lk_send_recovery_code', { phone: raw, password });
  dbg('SEND CODE RESPONSE', resp);

  if (!resp.success) {
    alert(resp.data?.message || 'Не удалось отправить код.');
    return;
  }
  

  // запускаем таймер...
}


</script>

<?php get_footer(); ?>
