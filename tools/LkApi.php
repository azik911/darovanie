<?php
// Защита от двойного подключения одного и того же класса
if (!class_exists('LkApi')) {

class LkApi {
    /** @var SoapClient|null */
    private $client = null;

    /** Текущее состояние сессии */
    private $sessionID        = null;   // int
    private $sessionHash      = null;   // string (тот самый hash2 из логина)
    private $currentFamilyID  = null;   // int|null
    private $currentProjectName = null; // string|null

    
    /** Включить логирование через error_log */
    private $debug = true;






    public function __construct(
        string $wsdl = 'http://darovanie.novalsoftware.com/mobile.asmx?WSDL',
        array $soapOptions = []
    ) {
        try {
            if (!defined('WSDL_CACHE_NONE')) define('WSDL_CACHE_NONE', 0);
    
            $defaults = [
                'trace'              => 1,
                'exceptions'         => true,
                'cache_wsdl'         => WSDL_CACHE_NONE,
                'connection_timeout' => 20,
                'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
                'stream_context'     => stream_context_create([
                    'http' => [
                        'timeout' => 20,
                    ],
                ]),
            ];
    
            $options = $soapOptions + $defaults;
    
            $this->log("🧠 Инициализация SOAP-клиента LkApi ($wsdl)");
            $this->client = new SoapClient($wsdl, $options);
            $this->log("✅ SOAP client initialized successfully: $wsdl");
        } catch (Throwable $e) {
            $this->log("💥 SOAP init error: " . $e->getMessage());
            $this->client = null;
        }
    }

    /* =====================================================
       У Т И Л И Т Ы
       ===================================================== */

    private function log(string $msg, $data = null): void {
        if (!$this->debug) return;
        if ($data !== null) {
            if (!is_scalar($data)) $data = print_r($data, true);
            $msg .= ' ' . $data;
        }
        error_log($msg);
    }

    /** Нормализуем телефон к формату +7XXXXXXXXXX (по возможности) */
    private function normalizePhone(string $phone): string {
        $digits = preg_replace('/[^0-9+]/', '', $phone);
        if (strpos($digits, '+7') === 0) return $digits;
        if ($digits && $digits[0] === '8' && strlen($digits) === 11) return '+7' . substr($digits, 1);
        if ($digits && $digits[0] === '7' && strlen($digits) === 11) return '+' . $digits;
        return $digits;
    }

    /** MD5 для ASCII с HEX в верхнем регистре (как в FinToolCrypto.GetMD5Sum) */
    private function getMD5Sum(string $str): string {
        // строго ASCII, затем md5 в raw, затем HEX UPPER
        $bytes = @iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        if ($bytes === false) $bytes = $str;
        $hash = md5($bytes, true);
        return strtoupper(bin2hex($hash));
    }

    /** Алгоритм из LoginPage.xaml.cs:
     *  hash1 = MD5(password + "Darovanie")
     *  hash2 = MD5(hash1 + phone)
     *  В Login отправляется ПАРАМЕТР `password` = hash2 (да, именно поле "password").
     */
    private function computeLoginHash(string $password, string $phone): string {
        $hash1 = $this->getMD5Sum($password . 'Darovanie');
        $hash2 = $this->getMD5Sum($hash1 . $phone);
        return $hash2;
    }

    /** Общий вызов с «двухтактным» фолбэком:
     *  1) Пробуем с sessionID + hash
     *  2) Если пусто/ошибка — пробуем без hash (как в твоей стабильной версии)
     */
    private function callWithHashFallback(string $method, array $baseParams) {
        if (!$this->client) return null;

        // Попытка №1 — с hash
        if ($this->sessionID && $this->sessionHash) {
            $withHash = $baseParams + ['sessionID' => (int)$this->sessionID, 'hash' => $this->sessionHash];
            try {
                $resp = $this->client->__soapCall($method, [$withHash]);
                $this->log("🔸 {$method} WITH hash request:", $withHash);
                $this->log("🔸 {$method} WITH hash response:", $resp);
                if ($this->hasPayload($resp, $method)) return $resp;
            } catch (Throwable $e) {
                $this->log("{$method} with hash error: " . $e->getMessage());
            }
        }

        // Попытка №2 — без hash
        $noHash = $baseParams + ['sessionID' => (int)$this->sessionID];
        try {
            $resp = $this->client->__soapCall($method, [$noHash]);
            $this->log("🔹 {$method} NO hash request:", $noHash);
            $this->log("🔹 {$method} NO hash response:", $resp);
            return $resp;
        } catch (Throwable $e) {
            $this->log("{$method} without hash error: " . $e->getMessage());
            return null;
        }
    }

    /** Простая эвристика: «есть ли в ответе полезные данные» для основных методов */
    private function hasPayload($resp, string $method): bool {
        if (!is_object($resp)) return false;
        $map = [
            'GetProjectsList'     => 'GetProjectsListResult',
            'GetFamiliesList'     => 'GetFamiliesListResult',
            'GetTransactionsList' => 'GetTransactionsListResult',
        ];
        $root = $map[$method] ?? null;
        if (!$root || !isset($resp->$root)) return false;
        return (bool)count(get_object_vars($resp->$root)); // не пустой stdClass
    }

    /** На всякий случай — проверка времени на сервере */
    public function getServerTime() {
        if (!$this->client) return null;
        try { return $this->client->__soapCall('GetServerTime', [[]]); }
        catch (Throwable $e) { $this->log("GetServerTime error: ".$e->getMessage()); return null; }
    }

    /* =====================================================
       П У Б Л И Ч Н Ы Е   М Е Т О Д Ы
       ===================================================== */

    /** Login(phone, password=hash2) → sessionID */
    public function login(string $phone, string $password) {
        if (!$this->client) return -999;
    
        $phoneNorm = $this->normalizePhone($phone);
    
        // === hash2 как в FinToolCrypto.GetMD5Sum(LoginPage.xaml.cs) ===
        // 1) h1 = MD5( password + 'Darovanie' )
        // 2) h2 = MD5( h1 + phone )
        // 3) отправляем h2 в поле "password"
        $h1 = $this->getMD5Sum($password . 'Darovanie');
        $hash2 = $this->getMD5Sum($h1 . $phoneNorm);
    
        try {
            $params = ['phone' => $phoneNorm, 'password' => $hash2];
            $this->log("🔐 SOAP Login Request:", $params);
    
            $result = $this->client->Login($params);
            $this->log("🔐 SOAP Login Response:", $result);
    
            $sid = $result->LoginResult ?? 0;
            $sid = is_numeric($sid) ? (int)$sid : 0;
    
            if ($sid > 0) {
                $this->sessionID   = $sid;
                $this->sessionHash = $hash2; // КЛЮЧЕВОЕ: этот же hash потом шлём в другие методы
                $this->log("✅ Logged in successfully. SessionID = {$sid}");
                return $sid;
            }
            $this->log("❌ Login failed with code: {$sid}");
            return $sid;
        } catch (Throwable $e) {
            $this->log("SOAP Login error: " . $e->getMessage());
            return -998;
        }
    }

    //ФИО
    public function getName(): ?string
    {
    if (!$this->client || !$this->sessionID || !$this->sessionHash) {
        return null;
    }
    $params = [
        'sessionID' => (int)$this->sessionID,
        'hash'      => $this->sessionHash, // ВАЖНО: тот же hash2, что и в Login
    ];
    $this->log('👤 SOAP GetName Request:', $params);

    try {
        $res = $this->client->__soapCall('GetName', [ $params ]);
        $this->log('👤 SOAP GetName Response:', $res);

        if (is_object($res) && isset($res->GetNameResult)) {
            $name = trim((string)$res->GetNameResult);
            return ($name !== '') ? $name : null;
        }
        if (is_string($res)) {
            $name = trim($res);
            return ($name !== '') ? $name : null;
        }
    } catch (\Throwable $e) {
        $this->log('GetName error: ' . $e->getMessage());
    }
    return null;
    }


    /** GetProjectsList(sessionID[, hash]) */
    public function getProjectsList() {
        if (!$this->client || !$this->sessionID) return null;
    
        $resp = $this->callWithHashFallback('GetProjectsList', []);
    
        // Сохраняем имя проекта (первый по списку)
        if (is_object($resp) && isset($resp->GetProjectsListResult)) {
            $r = $resp->GetProjectsListResult;
    
            // старый вариант
            if (isset($r->Project->Name)) {
                $this->currentProjectName = $r->Project->Name;
            } elseif (isset($r->Project[0]->Name)) {
                $this->currentProjectName = $r->Project[0]->Name;
            }
    
            // НОВЫЙ вариант из вашего ответа: ProjectSummary[]
            if (!$this->currentProjectName) {
                if (isset($r->ProjectSummary) && is_array($r->ProjectSummary) && isset($r->ProjectSummary[0]->name)) {
                    $this->currentProjectName = (string)$r->ProjectSummary[0]->name;
                } elseif (isset($r->ProjectSummary->name)) {
                    $this->currentProjectName = (string)$r->ProjectSummary->name;
                }
            }
        }
    
        return $resp;
    }

    /** GetFamiliesList(sessionID[, hash]) */
    public function getFamiliesList() {
        if (!$this->client || !$this->sessionID || !$this->sessionHash) return null;
        try {
            $params = [
                'sessionID' => (int)$this->sessionID,
                'hash'      => $this->sessionHash, // тот же хеш, что и при логине
            ];
            $this->log("👨‍👩‍👧 GetFamiliesList Request:", $params);
            $resp = $this->client->GetFamiliesList($params);
            $this->log("👨‍👩‍👧 GetFamiliesList Response:", $resp);
            return $resp;
        } catch (Throwable $e) {
            $this->log("SOAP GetFamiliesList error: " . $e->getMessage());
            return null;
        }
    }
    
    /** SelectFamily(sessionID[, hash], familyID) */
    public function selectFamily(int $familyID) {
        if (!$this->client || !$this->sessionID || !$this->sessionHash) return null;
        try {
            $params = [
                'sessionID' => (int)$this->sessionID,
                'hash'      => $this->sessionHash,
                'familyID'  => (int)$familyID,
            ];
            $this->log("🏠 SelectFamily Request:", $params);
            $resp = $this->client->SelectFamily($params);
            $this->log("🏠 SelectFamily Response:", $resp);
            $this->currentFamilyID = $familyID;
            return $resp;
        } catch (Throwable $e) {
            $this->log("SOAP SelectFamily error: " . $e->getMessage());
            return null;
        }
    }
    

    /** GetTransactionsList(sessionID[, hash], projectName) */
    public function getTransactionsList(string $projectName) {
        if (!$this->client || !$this->sessionID || !$this->sessionHash) return null;
    
        try {
            $params = [
                'sessionID'   => (int)$this->sessionID,
                'hash'        => $this->sessionHash,   // ВАЖНО
                'projectName' => (string)$projectName, // по WSDL требуется имя проекта
            ];
            $this->log("💰 GetTransactionsList Request:", $params);
            $resp = $this->client->GetTransactionsList($params);
            $this->log("💰 GetTransactionsList Response:", $resp);
            return $resp;
        } catch (Throwable $e) {
            $this->log("SOAP GetTransactionsList error: " . $e->getMessage());
            return null;
        }
    }
    

    /* Вспомогательные отладочные методы (опционально) */
    public function debugAvailableMethods() {
        if (!$this->client) { $this->log("⚠️ No SOAP client"); return; }
        try {
            $functions = $this->client->__getFunctions();
            $this->log("📚 SOAP FUNCTIONS:", $functions);
        } catch (Throwable $e) {
            $this->log("debugAvailableMethods error: " . $e->getMessage());
        }
    }   



    private function extractProjectNames($resp): array {
    $names = [];
    if (!is_object($resp) || !isset($resp->GetProjectsListResult)) return $names;
    $r = $resp->GetProjectsListResult;

    // новый формат
    if (isset($r->ProjectSummary)) {
        $list = is_array($r->ProjectSummary) ? $r->ProjectSummary : [$r->ProjectSummary];
        foreach ($list as $it) {
            if (isset($it->name) && is_string($it->name)) $names[] = $it->name;
        }
    }

    // старый формат (на всякий)
    if (isset($r->Project)) {
        $list = is_array($r->Project) ? $r->Project : [$r->Project];
        foreach ($list as $it) {
            if (isset($it->Name) && is_string($it->Name)) $names[] = $it->Name;
        }
    }

    return array_values(array_unique($names));
}


    public function restoreSession(array $data): void {
        $this->sessionID   = isset($data['sessionID']) ? (int)$data['sessionID'] : null;
        $this->sessionHash = $data['sessionHash'] ?? null;
        $this->currentFamilyID = $data['familyID'] ?? null;
        $this->currentProjectName = $data['project'] ?? null;
        $this->log("🔁 Session restored manually:", $data);
    }


    public function getSession() {
        return [
            'sessionID' => $this->sessionID,
            'sessionHash' => $this->sessionHash,
            'familyID'  => $this->currentFamilyID,
            'project'   => $this->currentProjectName,
        ];
    }


    public function startRecovery(string $phone, string $newPass): int {
        if (!$this->client) return -999;
        $phone = $this->normalizePhone($phone);
        $hash  = $this->hashPassword($newPass, $phone); // тот же хеш, что при логине
        try {
            $res = $this->client->Register(['phone' => $phone, 'password' => $hash]);
            return (int)($res->RegisterResult ?? -998);
        } catch (Throwable $e) {
            error_log('SOAP Register error: '.$e->getMessage());
            return -998;
        }
        $res = $this->client->Register(['phone'=>$phone,'password'=>$hash]);
        error_log('🔔 Register SOAP response: '.print_r($res,true));

    }
    
    public function confirmRecovery(string $phone, $code): int {
        if (!$this->client) return -999;
        $phone = $this->normalizePhone($phone);
        try {
            $res = $this->client->ConfirmRegistration([
                'phone' => $phone,
                'code'  => (int)$code,
            ]);
            return (int)($res->ConfirmRegistrationResult ?? -998);
        } catch (Throwable $e) {
            error_log('SOAP ConfirmRegistration error: '.$e->getMessage());
            return -998;
        }
    }
    
    public function call(string $method, array $params = []) {
        error_log("📡 SOAP generic call: {$method}(" . json_encode($params) . ")");
        if (!$this->client) {
            $this->initClient(); // убедимся, что SOAP инициализирован
        }
    
        try {
            $response = $this->client->__soapCall($method, [$params]);
            error_log("✅ SOAP {$method} response: " . print_r($response, true));
            return $response;
        } catch (Throwable $e) {
            error_log("💥 SOAP {$method} error: " . $e->getMessage());
            throw $e;
        }
    }
    

}




} // end class_exists guard
