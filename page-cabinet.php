<?php
/**
 * Template Name: Личный кабинет (стабильная сборка с оплатой)
 */

get_header();

// === сервисы ===
require_once get_stylesheet_directory() . '/tools/LkApi.php';
require_once get_stylesheet_directory() . '/tools/LkService.php';

// --- креды (можно вынести в wp-config.php как константы) ---
$login    = defined('DAROVANIE_LK_LOGIN')    ? DAROVANIE_LK_LOGIN    : '+79037957011';
$password = defined('DAROVANIE_LK_PASSWORD') ? DAROVANIE_LK_PASSWORD : 'Test-1234';

$service = new LkService();
$service->setCredentials($login, $password);

$errors   = [];
$projects = [];
$kids     = [];
$kidNames = [];
$groups   = [];

try {
  $service->ensureSession();

  // ФИО (один вызов) + лог в debug.log
  $fio = null;
  try {
      $fio = $service->getUserName(); // string|null
      if ($fio !== null && $fio !== '') {
          error_log('👤 FIO from GetName: ' . $fio);
      } else {
          error_log('👤 FIO from GetName: <empty>');
      }
  } catch (\Throwable $e) {
      error_log('👤 getUserName() error: ' . $e->getMessage());
  }

  // данные для шапок/блоков
  $projects = $service->getProjects();
  $allTx    = $service->getAllTransactions();
  $kids     = $service->getKidsWithSums();
  $kidNames = $service->getPupilsFromApi();
  $groups   = $service->groupTransactionsByDate($allTx);

} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}
?>
<main class="lk">
  <div class="lk-grid">
    <!-- Левое меню -->
    <aside class="lk-aside">
  <div class="lk-brand">
    <img class="lk-brand__logo"
         src="<?= esc_url( get_stylesheet_directory_uri().'/assets/icons/logo_white.svg' ) ?>"
         alt="Дарование">
  </div>
  <nav class="lk-menu">
    <a class="lk-menu__item" href="#">
      <span class="i i-user"></span><?= esc_html($fio ?: 'Имя Отчество') ?>
    </a>
    <a class="lk-menu__item" href="<?= esc_url( wp_logout_url( home_url('/') ) ) ?>">
      <span class="i i-exit"></span> Выйти
    </a>
  </nav>
</aside>

    <!-- Контент -->
<section class="lk-content">
  <?php if ($errors): ?>
    <div class="lk-alert lk-alert--error"><?= esc_html(implode(' | ', $errors)) ?></div>
  <?php endif; ?>

  <!-- Дети -->
  <section class="lk-section lk-section--children">
    <h2 class="lk-section__title">Дети</h2>
    <div class="lk-cards">
      <?php if ($kids): ?>
        <?php foreach ($kids as $kid => $sum): ?>
          <div class="lk-card">
            <div class="lk-kid">
              <div class="lk-kid__name"><?= esc_html($kid) ?></div>
              <div class="lk-kid__sum"><?= $sum < 0 ? '−' : '' ?><?= number_format(abs($sum), 0, ',', ' ') ?> ₽</div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="lk-card">
          <div class="lk-kid">
            <div class="lk-kid__name">Детей не найдено</div>
            <div class="lk-kid__sum">0 ₽</div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Счета -->
  <section class="lk-section lk-section--accounts">
    <h2 class="lk-section__title">Счета</h2>
    <?php
      $lk_projects = $projects;
      include get_stylesheet_directory() . '/templates/lk/accounts.php';
    ?>
  </section>

  <!-- История -->
  <?php
    $lk_groups = $groups;
    $closingBalances = $closingBalances ?? [];
    include get_stylesheet_directory() . '/templates/lk/history.php';
  ?>
</section>
</div>
</main>

<!-- ======================== МОДАЛКА ОПЛАТЫ ======================== -->
<div id="lk-pay-modal" class="lk-modal" hidden>
  <!-- затемнение -->
  <div class="lk-modal__backdrop" onclick="LKPay.close()"></div>

  <!-- окно -->
  <div class="lk-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lk-pay-title" onclick="event.stopPropagation()">
    <button class="lk-modal__close" type="button" aria-label="Закрыть" onclick="LKPay.close()">×</button>

    <h3 id="lk-pay-title" class="lk-modal__title">Оплата по QR</h3>

    <div class="lk-pay__banner">
      <strong>Важно:</strong> этот QR распознаётся только в <u>приложении вашего банка</u>.
      Откройте Сбер/ВТБ/Тинькофф/Альфа → «Оплата по QR» и наведите камеру.
    </div>

    <div class="lk-pay">
      <div class="lk-pay__qr">
        <img id="lk-pay-qr-img" alt="QR" style="display:none;max-width:260px">
        <pre id="lk-pay-qr-text" class="lk-pay__raw" style="display:none"></pre>

        <div class="lk-pay__actions">
          <button type="button" class="lk-btn" onclick="LKPay.copy()">Скопировать строку</button>
          <button type="button" class="lk-btn" onclick="LKPay.download()">Скачать QR (PNG)</button>
          <button type="button" class="lk-btn lk-btn--secondary" onclick="LKPay.close()">Закрыть</button>
        </div>

        <div class="lk-pay__hint">
          Сканируйте в приложении банка. Обычная камера телефона банковский формат (ST00012) не распознаёт.
        </div>
      </div>

      <div class="lk-pay__meta">
        <div><b>Получатель:</b> <span id="lk-pay-recipient"></span></div>
        <div><b>Банк:</b> <span id="lk-pay-bank"></span></div>
        <div><b>Счёт:</b> <span id="lk-pay-acc"></span></div>
        <div><b>ИНН/КПП:</b> <span id="lk-pay-innkpp"></span></div>
        <div><b>Назначение:</b> <span id="lk-pay-purpose"></span></div>
        <div><b>Сумма:</b> <span id="lk-pay-amount"></span></div>
      </div>
    </div>
  </div>
</div>



<script>
/**
 * Реквизиты для QR-оплаты (АНОО / ИП / Лицевой счёт).
 * Ключ — название проекта/счёта, как в блоке «Счета» и/или в истории.
 */
window.LK_PAY_REQUISITES = {
  'АНОО': {
    Name:        'Общеобразовательная автономная некоммерческая организация «Школа «Дарование»»',
    PayeeINN:    '5042147283',
    KPP:         '504201001',
    PersonalAcc: '40703810040000003102',
    BankName:    'ПАО «Сбербанк»',
    BIC:         '044525225',
    CorrespAcc:  '30101810400000000225'
  },
  'МБА': {
    Name:        'Индивидуальный предприниматель Бурлакова Юлия Петровна',
    PayeeINN:    '504224868700',
    PersonalAcc: '40802810425340000513',
    BankName:    'ФИЛИАЛ «ЦЕНТРАЛЬНЫЙ» БАНКА ВТБ (ПАО)',
    BIC:         '044525411',
    CorrespAcc:  '30101810145250000411'
  },
  'Лицевой счёт': { // при необходимости направляем на ИП
    Name:        'Индивидуальный предприниматель Бурлакова Юлия Петровна',
    PayeeINN:    '504224868700',
    PersonalAcc: '40802810425340000513',
    BankName:    'ФИЛИАЛ «ЦЕНТРАЛЬНЫЙ» БАНКА ВТБ (ПАО)',
    BIC:         '044525411',
    CorrespAcc:  '30101810145250000411'
  }
};

// Алиас, чтобы сборщик payload видел маппинг
window.PAY_CONFIG = window.PAY_CONFIG || window.LK_PAY_REQUISITES;
</script>

<script>
/**
 * Лёгкая реализация оплаты: сборка ST00012 и показ модалки.
 * Совместима со старым onclick="openQrModal(project, amount, purpose)".
 */
(function () {
  const LKPay = {
    _payload: '',
    _escHandler: null,

    // Сборка из маппинга проектов
    _buildFromProject({ project = 'Лицевой счёт', amountRub = 0, purpose = '' } = {}) {
      const cfgMap = (window.PAY_CONFIG || window.LK_PAY_REQUISITES || {});
      const cfg = cfgMap[project] || cfgMap['Лицевой счёт'];
      if (!cfg) return null;

      const data = {
        ST: 'ST00012',
        Name:        cfg.Name,
        PersonalAcc: cfg.PersonalAcc,
        BankName:    cfg.BankName,
        BIC:         cfg.BIC,
        CorrespAcc:  cfg.CorrespAcc,
        PayeeINN:    cfg.PayeeINN
      };
      if (cfg.KPP) data.KPP = cfg.KPP;
      if (purpose) data.Purpose = purpose;
      if (amountRub > 0) data.Sum = String(Math.round(amountRub * 100)); // копейки

      const payload = Object.entries(data)
        .map(([k,v]) => `${k}=${String(v)}`).join('|');

      const fields = {
        recipient:  cfg.Name,
        bank:       cfg.BankName,
        account:    cfg.PersonalAcc,
        innkpp:     cfg.PayeeINN + (cfg.KPP ? (' / ' + cfg.KPP) : ''),
        purpose:    purpose || 'Оплата',
        amountText: amountRub ? amountRub.toLocaleString('ru-RU') + ' ₽' : '—'
      };

      return { payload, fields };
    },

    /**
     * open(options):
     * 1) open({ project, amountRub, purpose })
     * 2) open({ payload, recipient, bank, account, innkpp, purpose, amountText })
     */
    open(opts = {}) {
      let payload = '';
      let fields  = null;

      if (opts.payload) {
        payload = String(opts.payload);
        fields = {
          recipient:  opts.recipient,
          bank:       opts.bank,
          account:    opts.account,
          innkpp:     opts.innkpp,
          purpose:    opts.purpose,
          amountText: opts.amountText
        };
      } else {
        const built = this._buildFromProject(opts);
        if (!built) { console.error('LKPay: нет реквизитов для', opts); return; }
        payload = built.payload;
        fields  = built.fields;
      }

      this._payload = payload;

      // Видимые поля справа
      const setText = (id, v) => { if (v != null) { const el = document.getElementById(id); if (el) el.textContent = v; } };
      if (fields) {
        setText('lk-pay-recipient', fields.recipient);
        setText('lk-pay-bank',      fields.bank);
        setText('lk-pay-acc',       fields.account);
        setText('lk-pay-innkpp',    fields.innkpp);
        setText('lk-pay-purpose',   fields.purpose);
        setText('lk-pay-amount',    fields.amountText);
      }

      // QR-картинка (если не загрузилась — покажем сырую строку)
      const img = document.getElementById('lk-pay-qr-img');
      const txt = document.getElementById('lk-pay-qr-text');
      if (img && txt) {
        img.onerror = () => { img.style.display='none'; txt.style.display='block'; txt.textContent=this._payload; };
        img.onload  = () => { img.style.display='block'; txt.style.display='none'; };
        img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' + encodeURIComponent(this._payload);
      }

      // Показ модалки + Esc
      const modal = document.getElementById('lk-pay-modal');
      if (modal) {
        modal.hidden = false;
        document.body.classList.add('lk-modal-open');
      }
      this._escHandler = (e)=>{ if (e.key === 'Escape') this.close(); };
      document.addEventListener('keydown', this._escHandler);
    },

    close() {
      const modal = document.getElementById('lk-pay-modal');
      if (modal) modal.hidden = true;
      document.body.classList.remove('lk-modal-open');
      if (this._escHandler) {
        document.removeEventListener('keydown', this._escHandler);
        this._escHandler = null;
      }
    },

    copy() {
      if (!this._payload) return;
      if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(this._payload).catch(()=>{});
      } else {
        const ta = document.createElement('textarea');
        ta.value = this._payload; document.body.appendChild(ta);
        ta.select(); try { document.execCommand('copy'); } catch(e) {}
        ta.remove();
      }
    },

    async download() {
      if (!this._payload) return;
      const url = 'https://api.qrserver.com/v1/create-qr-code/?size=480x480&data=' + encodeURIComponent(this._payload);
      try {
        const blob = await fetch(url).then(r=>r.blob());
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'pay-qr.png';
        document.body.appendChild(a);
        a.click();
        URL.revokeObjectURL(a.href);
        a.remove();
      } catch(e) {
        console.error('LKPay: ошибка скачивания', e);
      }
    }
  };

  // Совместимость со старым вариантом:
  window.openQrModal = function(project, amount, purpose){
    const amt = parseFloat(amount) || 0;
    LKPay.open({ project, amountRub: amt, purpose: purpose || '' });
    return false;
  };

  window.LKPay = LKPay;
})();
</script>



<?php get_footer(); ?>
