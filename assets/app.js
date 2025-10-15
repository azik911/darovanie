document.addEventListener("DOMContentLoaded", function () {
  const phone = document.getElementById("lk_phone");
  const password = document.getElementById("lk_password");
  const eyeBtn = document.querySelector(".lk-eye");
  const form = document.getElementById("lkLoginForm");
  const error = document.getElementById("lkError");


(function(){
  // Маска телефона (если на сайте уже подключён Inputmask)
  if (window.Inputmask) {
    try { Inputmask({mask: "+7 (999) 999-99-99"}).mask(document.querySelectorAll('.js-sms-phone')); } catch(e){}
  }

  const ajaxUrl = (window.darovanie && darovanie.ajax_url) || '/wp-admin/admin-ajax.php';

  // Универсальный биндер для всех кнопок "Получить код"
  document.querySelectorAll('.js-sms-btn').forEach((btn) => {
    let timerId = null;
    let left = 0;
    const wait = parseInt(btn.dataset.wait || '60', 10);

    const wrap  = btn.closest('.form-row') || document;
    const phone = wrap.querySelector('.js-sms-phone');
    const code  = document.getElementById('rec-code');

    function setText(disabled, text){
      btn.disabled = !!disabled;
      btn.textContent = text;
    }
    function tick(){
      left -= 1;
      if (left <= 0){
        clearInterval(timerId); timerId = null;
        setText(false, 'Получить код');
      } else {
        setText(true, `Отправлено (${left} c)`);
      }
    }

    btn.addEventListener('click', async () => {
      if (timerId) return;                 // уже тикаем — не спамим
      if (!phone)  return;
      const raw = (phone.value || '').trim();
      if (raw.length < 10) { phone.focus(); return; }

      try {
        // отправка на бэкенд (AJAX). Экшен назови как в своём functions.php
        const body = new URLSearchParams({
          action: 'lk_send_restore_code',   // <-- этот action добавим в functions.php (или используй свой существующий)
          phone: raw
        });
        const res = await fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
        const json = await res.json().catch(()=>({}));

        if (!json || json.ok !== true){
          throw new Error(json && json.message ? json.message : 'Не удалось отправить код. Попробуйте позже.');
        }

        // стартуем таймер
        left = wait;
        setText(true, `Отправлено (${left} c)`);
        timerId = setInterval(tick, 1000);
        if (code) code.focus();
      } catch (e){
        alert(e.message || 'Ошибка отправки кода');
      }
    });
  });
})();



  // === Показать/скрыть пароль ===
  if (eyeBtn && password) {
    eyeBtn.addEventListener("click", function () {
      password.type = password.type === "password" ? "text" : "password";
      eyeBtn.classList.toggle("is-active");
    });
  }

  // === Валидация формы ===
  if (form) {
    form.addEventListener("submit", function (e) {
      if (error) {
        error.hidden = true;
        error.textContent = "";
      }
  
      let rawValue = phone ? phone.value.trim() : "";
  
      // Проверка телефона (или логина)
      if (rawValue === "") {
        if (error) {
          error.textContent = "Введите логин (телефон или имя пользователя).";
          error.hidden = false;
        }
        e.preventDefault();
        return;
      }
    });
  }
  
});
