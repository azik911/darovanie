<?php
// tools/api-probe.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$wsdl = 'http://our.darovanie-posad.ru/mobile.asmx?WSDL';

try {
    $client = new SoapClient($wsdl, [
        'trace' => 1,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE,
    ]);

    echo "<h3>Доступные методы:</h3><pre>";
    print_r($client->__getFunctions());
    echo "</pre>";

    echo "<h3>Типы:</h3><pre>";
    print_r($client->__getTypes());
    echo "</pre>";
} catch (Throwable $e) {
    echo "SOAP error: " . $e->getMessage();
}
