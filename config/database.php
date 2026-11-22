<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$DB_HOST = getenv('SMART_KJA_DB_HOST') ?: 'localhost';
$DB_PORT = getenv('SMART_KJA_DB_PORT') ?: '3306';
$DB_NAME = getenv('SMART_KJA_DB_NAME') ?: 'smart_kja_owc';
$DB_USER = getenv('SMART_KJA_DB_USER') ?: 'root';
$DB_PASS = getenv('SMART_KJA_DB_PASS');
if ($DB_PASS === false) {
    $DB_PASS = '';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_PORT, $DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    return $pdo;
}
