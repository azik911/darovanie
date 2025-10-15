<?php
/** @var array $lk_projects */
$projects = $lk_projects ?? [];
if (!$projects) return;
?>
<section class="lk-section lk-section--accounts">
  <h2 class="lk-section__title">Счета</h2>

  <div class="lk-cards lk-cards--accounts">
    <?php foreach ($projects as $p):
      $name = (string)($p['name'] ?? 'Счёт');
      $bal  = (float)($p['balance'] ?? 0);
      $cls  = $bal > 0 ? 'is-positive' : ($bal < 0 ? 'is-negative' : '');
    ?>
      <div class="lk-card lk-account" data-project="<?= esc_attr($name) ?>">
        <div class="lk-account__head">
          <div class="lk-account__balance <?= $cls ?>">
            <?= $bal < 0 ? '−' : '' ?><?= number_format(abs($bal), 0, ',', ' ') ?> ₽
          </div>

          <!-- Кнопка вопроса -->
          <a href="#"
             class="lk-help-link ask-question"
             data-project="<?= esc_attr($name) ?>"
             data-transaction-id="<?= esc_attr($p['transactionID'] ?? 0) ?>">
            <span class="lk-help-icon" aria-hidden="true"></span>
            Задать вопрос
          </a>
        </div>

        <button type="button"
                class="lk-account__qr"
                title="Код для оплаты (общий)"
                onclick="LKPay.open({project:'<?= esc_js($name) ?>', amountRub:0, purpose:'Оплата по счёту <?= esc_js($name) ?>'})">
          <span class="lk-qr-icon" aria-hidden="true"></span>
        </button>

        <div class="lk-account__type">
          <span class="lk-account__icon" aria-hidden="true"></span>
          <span><?= esc_html($name ?: 'Счёт') ?></span>
        </div>

        <a class="lk-pay-code" href="#"
           onclick="LKPay.open({project:'<?= esc_js($name) ?>', amountRub:0, purpose:'Оплата по счёту <?= esc_js($name) ?>'});return false;">
           Код для оплаты
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>


<!-- ✅ Модальное окно "Задать вопрос бухгалтерии" -->
<div id="questionModal" class="lk-modal" style="display: none;">
  <div class="lk-modal__overlay" onclick="closeQuestionModal()"></div>

  <div class="lk-modal__content">
    <div class="lk-modal__header">
      <img
        id="questionIcon"
        src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/accountant.png' ); ?>"
        alt="Бухгалтерия"
        class="lk-modal__avatar"
      >
      <div>
        <h3 id="questionProject" class="lk-modal__title">Вопрос по счёту:</h3>
        <p class="lk-modal__subtitle">
          Задайте вопрос бухгалтерии — ответ придёт на ваш телефон
        </p>
      </div>
    </div>

    <textarea
      id="questionMessage"
      placeholder="Введите ваш вопрос..."
      rows="4"
      class="lk-textarea"
    ></textarea>

    <div class="lk-modal__actions">
      <button id="closeQuestionBtn" class="lk-btn lk-btn--ghost" type="button">
        Отмена
      </button>
      <button id="sendQuestionBtn" class="lk-btn lk-btn--primary" type="button">
        Отправить
      </button>
    </div>
  </div>
</div>


<script>
const AJAX_URL = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";

document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('questionModal');
  const messageInput = document.getElementById('questionMessage');
  const sendBtn = document.getElementById('sendQuestionBtn');
  const closeBtn = document.getElementById('closeQuestionBtn');
  const projectLabel = document.getElementById('questionProject');
  const iconEl = document.getElementById('questionIcon');
  let currentProject = null;
  let currentTransactionID = 0;

  const noticeBox = document.createElement('div');
  noticeBox.className = 'lk-notice';
  modal.querySelector('.lk-modal__content').appendChild(noticeBox);

  document.querySelectorAll('.ask-question').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      currentProject = btn.dataset.project || 'Неизвестный счёт';
      currentTransactionID = btn.dataset.transactionId || 0;
      const iconPath = btn.dataset.icon || '<?php echo get_template_directory_uri(); ?>/assets/icons/default.svg';

      projectLabel.textContent = 'Вопрос по счёту: ' + currentProject;
      iconEl.src = iconPath;
      iconEl.alt = currentProject;

      messageInput.value = '';
      noticeBox.textContent = '';
      noticeBox.className = 'lk-notice';
      modal.style.display = 'flex';
      messageInput.focus();
    });
  });

  closeBtn.addEventListener('click', () => {
    modal.style.display = 'none';
    messageInput.value = '';
    noticeBox.textContent = '';
  });

  sendBtn.addEventListener('click', async () => {
    const message = messageInput.value.trim();
    if (!message) {
      showNotice('⚠️ Введите текст вопроса.', 'error');
      return;
    }

    sendBtn.disabled = true;
    sendBtn.textContent = 'Отправка...';
    showNotice('⏳ Отправляем вопрос...', 'info');

    try {
      const resp = await fetch(AJAX_URL, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({
          action: 'lk_send_question',
          transactionID: currentTransactionID,
          message
        })
      });

      const data = await resp.json();

      if (data.success) {
        showNotice('✅ Вопрос успешно отправлен бухгалтерии!', 'success');
        messageInput.value = '';
        setTimeout(() => { modal.style.display = 'none'; }, 2500);
      } else {
        showNotice('⚠️ ' + (data.data?.message || 'Ошибка при отправке.'), 'error');
      }
    } catch (e) {
      showNotice('💥 Ошибка соединения: ' + e.message, 'error');
    } finally {
      sendBtn.disabled = false;
      sendBtn.textContent = 'Отправить';
    }
  });

  function showNotice(text, type = 'info') {
    noticeBox.textContent = text;
    noticeBox.className = 'lk-notice ' + type;
  }
});
</script>
