<?php
// tools/LkService.php
if (!class_exists('LkService')) {

class LkService
{
    /** @var LkApi */
    private $api;

    /** creds */
    private string $phone = '';
    private string $password = '';

    /** session */
    private ?int $sid = null;

    /** cached data */
    private array $projects = [];          // [ ['name','description','currency','balance'], ... ]
    private array $txByProject = [];       // name => TransactionSummary[]
    private array $allTx = [];             // плоский список (со свойством __projectName)

            /** Синонимы названий проектов (нормализация) */
    private array $qrKeySynonyms = [
        'аноо'         => 'АНОО',
        'лицевой счет' => 'Лицевой счёт',
        'лицевой счёт' => 'Лицевой счёт',
        'мба'          => 'МБА',
    ];

    /** Нормализация имени проекта к ключу qrRequisites */
    private function normalizeProjectKey(string $name): ?string {
        $norm = function(string $s): string {
            $s = strtr($s, ['Ё'=>'Е','ё'=>'е']);
            return mb_strtolower($s, 'UTF-8');
        };
        $src = $norm($name);

        // точное совпадение по норме среди имеющихся ключей
        foreach (array_keys($this->qrRequisites) as $k) {
            if ($norm($k) === $src) return $k;
        }
        // по синонимам
        return $this->qrKeySynonyms[$src] ?? null;
    }



        /** Реквизиты получателя по проектам (названия — как в транзакциях) */
    private array $qrRequisites = [
        'АНОО' => [
            'Name'        => 'АНОО «Школа «Дарование»»',
            'PersonalAcc' => '40703810040000003102',
            'BankName'    => 'ПАО «Сбербанк»',
            'BIC'         => '044525225',
            'CorrespAcc'  => '30101810400000000225',
            'PayeeINN'    => '5042147283',
            'KPP'         => '504201001',
            'TechCode'    => '08',
        ],
        'Лицевой счёт' => [
            'Name'        => 'ИП Бурлакова Юлия Петровна',
            'PersonalAcc' => '40802810425340000513',
            'BankName'    => 'ФИЛИАЛ «ЦЕНТРАЛЬНЫЙ» БАНКА ВТБ (ПАО)',
            'BIC'         => '044525411',
            'CorrespAcc'  => '30101810145250000411',
            'PayeeINN'    => '504224868700',
            'TechCode'    => '08',
        ],
        // 'МБА' => [ ... ] // при необходимости
    ];

    /** Собрать строку ST00012 (UTF-8, разделитель |) строго по ГОСТ */
    private function buildGost56042(array $req): string
    {
        $sep = '|';
        $hdr = 'ST00012' . $sep;

        $san = function(?string $v, ?int $max = null) use ($sep): string {
            $v = (string)$v;
            $v = str_replace(["\r","\n",$sep], ' ', $v);
            if ($max !== null && mb_strlen($v,'UTF-8') > $max) {
                $v = mb_substr($v, 0, $max, 'UTF-8');
            }
            return $v;
        };

        $parts   = [];
        $parts[] = 'Name='        . $san($req['Name']        ?? '', 160);
        $parts[] = 'PersonalAcc=' . preg_replace('~\D~', '', (string)($req['PersonalAcc'] ?? ''));
        $parts[] = 'BankName='    . $san($req['BankName']    ?? '', 45);
        $parts[] = 'BIC='         . preg_replace('~\D~', '', (string)($req['BIC'] ?? ''));
        $parts[] = 'CorrespAcc='  . preg_replace('~\D~', '', (string)($req['CorrespAcc'] ?? '0'));

        if (!empty($req['PayeeINN'])) $parts[] = 'PayeeINN=' . preg_replace('~\D~', '', (string)$req['PayeeINN']);
        if (!empty($req['KPP']))      $parts[] = 'KPP='      . preg_replace('~\D~', '', (string)$req['KPP']);
        if (!empty($req['Sum']))      $parts[] = 'Sum='      . (int)$req['Sum'];                 // копейки
        if (!empty($req['Purpose']))  $parts[] = 'Purpose='  . $san($req['Purpose'], 210);

        foreach (['ChildFio','PaymPeriod','PaymTerm','Contract','TechCode'] as $k) {
            if (!empty($req[$k])) $parts[] = $k.'='.$san((string)$req[$k]);
        }
        return $hdr . implode($sep, $parts);
    }

    /**
     * Сформировать payload для QR по проекту/сумме/назначению.
     * @param string $project   Название проекта (как в транзакциях и GetProjectsList)
     * @param float  $amountRub Сумма в рублях (положительная)
     * @param string $purpose   Назначение платежа
     * @param array  $extra     Доп. поля ГОСТ (ChildFio, PaymPeriod, TechCode…)
     * @return string|null      Строка ST00012 или null, если нет реквизитов
     */
    public function makeQrPayload(string $project, float $amountRub, string $purpose, array $extra = []): ?string
    {
        $key = $this->normalizeProjectKey($project) ?? $project;
        $base = $this->qrRequisites[$key] ?? null;
        if (!$base) return null;
    
        $req = $base + [];
        $req['Sum']     = (int) round(max(0, $amountRub) * 100);
        $req['Purpose'] = $purpose;
        foreach ($extra as $k=>$v) $req[$k] = $v;
    
        foreach (['Name','PersonalAcc','BankName','BIC','CorrespAcc'] as $k) {
            if (empty($req[$k])) return null;
        }
        return $this->buildGost56042($req);
    }
    
    public function __construct(?LkApi $api = null, ?string $phone = null, ?string $password = null)
    {
        $this->api = $api ?: new LkApi();
        if ($phone)    $this->phone = $phone;
        if ($password) $this->password = $password;
    }

    /** Задать учётные */
    public function setCredentials(string $phone, string $password): void
    {
        $this->phone = $phone;
        $this->password = $password;
    }

    /** Телефон (для сайдбара/маскировки) */
    public function getPhone(): string
    {
        return $this->phone;
    }

    /** Гарантировать логин; вернуть SID */
    public function ensureSession(): int
    {
        if ($this->sid) return $this->sid;
        if ($this->phone === '' || $this->password === '') {
            throw new \RuntimeException('Credentials are empty');
        }
        $sid = (int)$this->api->login($this->phone, $this->password);
        if ($sid <= 0) throw new \RuntimeException('Login failed');
        $this->sid = $sid;
        return $sid;
    }

    /** Список проектов (с кэшем) */
    public function getProjects(): array
    {
        if (!empty($this->projects)) return $this->projects;
        $this->ensureSession();

        $resp = $this->api->getProjectsList();
        $out = [];

        if ($resp && isset($resp->GetProjectsListResult->ProjectSummary)) {
            $list = is_array($resp->GetProjectsListResult->ProjectSummary)
                  ? $resp->GetProjectsListResult->ProjectSummary
                  : [$resp->GetProjectsListResult->ProjectSummary];
            foreach ($list as $it) {
                $out[] = [
                    'name'        => (string)($it->name ?? ''),
                    'description' => (string)($it->description ?? ''),
                    'currency'    => (int)($it->currency ?? 0),
                    'balance'     => (float)($it->balance ?? 0),
                ];
            }
        }

        // старый формат на всякий
        if ($resp && isset($resp->GetProjectsListResult->Project)) {
            $list = is_array($resp->GetProjectsListResult->Project)
                  ? $resp->GetProjectsListResult->Project
                  : [$resp->GetProjectsListResult->Project];
            foreach ($list as $it) {
                $out[] = [
                    'name'        => (string)($it->Name ?? ''),
                    'description' => (string)($it->Description ?? ''),
                    'currency'    => (int)($it->Currency ?? 0),
                    'balance'     => (float)($it->Balance ?? 0),
                ];
            }
        }

        // уникальность по имени
        $seen = [];
        $this->projects = array_values(array_filter($out, function($p) use (&$seen) {
            $n = $p['name'] ?? '';
            if ($n === '' || isset($seen[$n])) return false;
            $seen[$n] = true;
            return true;
        }));

        return $this->projects;
    }



    public function getUserName(): ?string
    {
        $sid = $this->ensureSession();
        if (isset($this->api) && method_exists($this->api, 'getName')) {
            try { return $this->api->getName($sid) ?: null; }
            catch (\Throwable $e) { error_log('LkService::getUserName failed: '.$e->getMessage()); }
        }
        return null;
    }


    /** Только имена проектов */
    public function getProjectNames(): array
    {
        return array_map(fn($p) => $p['name'], $this->getProjects());
    }

    /** Транзакции по проекту (с кэшем) */
    public function getTransactionsByProject(string $projectName): array
    {
        if (isset($this->txByProject[$projectName])) return $this->txByProject[$projectName];
        $this->ensureSession();

        $resp = $this->api->getTransactionsList($projectName);
        $items = [];
        if ($resp && isset($resp->GetTransactionsListResult->TransactionSummary)) {
            $items = is_array($resp->GetTransactionsListResult->TransactionSummary)
                   ? $resp->GetTransactionsListResult->TransactionSummary
                   : [$resp->GetTransactionsListResult->TransactionSummary];
        }

        foreach ($items as $it) {
            // для подписей в истории
            $it->__projectName = $projectName;
        }

        return $this->txByProject[$projectName] = $items;
    }

    /** Все транзакции по всем проектам (плоский список) */
    public function getAllTransactions(): array
    {
        if (!empty($this->allTx)) return $this->allTx;

        foreach ($this->getProjectNames() as $name) {
            foreach ($this->getTransactionsByProject($name) as $it) {
                $this->allTx[] = $it;
            }
        }
        return $this->allTx;
    }

    /**
     * Дети из API: GetFamiliesList → FamilySummary → pupils (ArrayOfString)
     * Возвращает массив ФИО (строк).
     */
    public function getPupilsFromApi(): array
    {
        $this->ensureSession();
        $resp = $this->api->getFamiliesList();
        $names = [];

        if ($resp && isset($resp->GetFamiliesListResult)) {
            $R = $resp->GetFamiliesListResult;

            $families = [];
            if (isset($R->FamilySummary)) {
                $families = is_array($R->FamilySummary) ? $R->FamilySummary : [$R->FamilySummary];
            } elseif (isset($R->Family)) {
                $families = is_array($R->Family) ? $R->Family : [$R->Family];
            }

            foreach ($families as $fam) {
                if (!isset($fam->pupils)) continue;
                $p = $fam->pupils;

                if (is_array($p)) {
                    foreach ($p as $s) $names[] = trim((string)$s);
                } elseif (is_object($p) && isset($p->string)) {
                    $arr = is_array($p->string) ? $p->string : [$p->string];
                    foreach ($arr as $s) $names[] = trim((string)$s);
                } else {
                    $names[] = trim((string)$p);
                }
            }
        }

        // уникальные, не пустые
        $names = array_values(array_unique(array_filter($names, fn($x) => $x !== '')));
        return $names;
    }

    /**
     * Вернуть map: "ФИО ребёнка" => сумма по всем транзакциям (по всем проектам).
     * Приоритет источников имён:
     *  1) pupils[] из API (GetFamiliesList)
     *  2) фолбэк: имена в скобках (… (Имя) …) из описаний транзакций,
     *     отфильтрованные по эвристике «похоже на ФИО ребёнка».
     */
    public function getKidsWithSums(): array
{
    $pupils = $this->getPupilsFromApi();  // [] или ["Эмилия","Майя",...]
    $tx     = $this->getAllTransactions();

    // Определим поле суммы один раз (приоритет value, затем amount)
    $sumField = null;
    foreach ($tx as $t) {
        if (isset($t->value))  { $sumField = 'value';  break; }
        if (isset($t->amount)) { $sumField = 'amount'; break; }
    }
    if (!$sumField) $sumField = 'value';

    // === ВЕТКА A: есть имена из API — считаем суммы по вхождению в description|name ===
    if (!empty($pupils)) {
        $sum = array_fill_keys($pupils, 0.0);

        foreach ($tx as $t) {
            $text = (string)($t->description ?? $t->name ?? '');
            $val  = (float)($t->{$sumField} ?? 0);

            foreach ($pupils as $kid) {
                if ($kid === '') continue;
                // слово целиком (Unicode)
                $pattern = '/(?<!\p{L})' . preg_quote($kid, '/') . '(?!\p{L})/u';
                if (preg_match($pattern, $text)) {
                    $sum[$kid] += $val;
                }
            }
        }

        ksort($sum, SORT_NATURAL);
        return $sum; // показываем даже нули
    }

    // === ВЕТКА B (фолбэк): FamiliesList пуст — берём ФИО прямо из поля name транзакций ===
    $sum = [];
    foreach ($tx as $t) {
        $rawName = (string)($t->name ?? '');
        $val     = (float)($t->{$sumField} ?? 0);

        // иногда name может быть пустым — тогда попробуем достать из description в скобках
        if ($rawName === '') {
            foreach ($this->extractNamesFromDescription((string)($t->description ?? '')) as $cand) {
                if (!isset($sum[$cand])) $sum[$cand] = 0.0;
                $sum[$cand] += $val;
            }
            continue;
        }

        $kid = trim($rawName);
        if (!$this->isLikelyChildName($kid)) {
            // если это не похоже на ФИО, попробуем из description
            foreach ($this->extractNamesFromDescription((string)($t->description ?? '')) as $cand) {
                if (!isset($sum[$cand])) $sum[$cand] = 0.0;
                $sum[$cand] += $val;
            }
            continue;
        }

        if (!isset($sum[$kid])) $sum[$kid] = 0.0;
        $sum[$kid] += $val;
    }

    ksort($sum, SORT_NATURAL);
    return $sum;
}


private function extractNamesFromDescription(string $desc): array
{
    $out = [];
    if (preg_match_all('/\(([^)]+)\)/u', $desc, $m)) {
        foreach ($m[1] as $chunk) {
            $parts = preg_split('/[,;\/]|(?:\s+и\s+)/u', $chunk);
            foreach ($parts as $cand) {
                $cand = trim($cand);
                if ($this->isLikelyChildName($cand)) $out[] = $cand;
            }
        }
    }
    // уникальные
    return array_values(array_unique($out));
}




    private function isLikelyChildName(string $s): bool
    {
        $s = trim($s);
        if ($s === '' || mb_strlen($s) > 40) return false;

        static $STOP = [
            'индивидуальные','индивидуально','занятия','занятие','урок','уроки',
            'лицевой','счёт','счет','оно','аноо','мба','начисление','оплата','взнос',
            'за','по','код','для','оплаты','платеж','баланс','абонемент','подписка',
            'января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря',
        ];
        $low = mb_strtolower($s);
        foreach ($STOP as $w) {
            if ($w !== '' && mb_strpos($low, $w) !== false) return false;
        }

        // 1–3 слова, каждое: Заглавная + строчные, допускается дефис-слово
        $re = '/^(?=.{2,40}$)(?:[А-ЯЁA-Z][а-яёa-z]+(?:-[А-ЯЁA-Z][а-яёa-z]+)?)(?:\s+[А-ЯЁA-Z][а-яёa-z]+(?:-[А-ЯЁA-Z][а-яёa-z]+)?){0,2}$/u';
        return (bool)preg_match($re, $s);
    }

    /** Группировка истории по дате (YYYY-MM-DD) → [date => rows[]], новые сверху */
    public function groupTransactionsByDate(array $tx): array
    {
        $g = [];
        foreach ($tx as $it) {
            $d = substr((string)($it->date ?? ''), 0, 10) ?: '0000-00-00';
            $g[$d][] = $it;
        }
        krsort($g);
        return $g;
    }


public function recoverSendCode(string $phone, string $pass): int {
    return $this->api->startRecovery($phone, $pass);
}
public function recoverConfirm(string $phone, string $code): int {
    return $this->api->confirmRecovery($phone, $code);
}

}

} // class_exists guard
