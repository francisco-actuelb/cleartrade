<?php
// /var/www/ct.hsrv.fr/bin/debug_db.php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// 1. Initialisation
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
} catch (Exception $e) {
    die("ERREUR FATALE DE CONNEXION : " . $e->getMessage() . "\n");
}

echo "========================================================\n";
echo "       DIAGNOSTIC DE LA BASE DE DONNEES CLEARTRADE      \n";
echo "========================================================\n\n";

// TEST 1 : Comptage des lignes de chaque table
echo "--- 1. ÉTAT DES TABLES ---\n";
$tables = ['companies', 'insiders', 'transactions', 'transactions_jour', 'stock_prices'];
foreach ($tables as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo str_pad("Table '$table'", 30) . ": $count ligne(s)\n";
    } catch (Exception $e) {
        echo str_pad("Table '$table'", 30) . ": ERREUR (" . $e->getMessage() . ")\n";
    }
}

// TEST 2 : Test de la jointure principale (HomeModel)
echo "\n--- 2. TEST DE JOINTURE INTERNE ---\n";
try {
    $stmt = $pdo->query("
        SELECT tj.id 
        FROM transactions_jour tj
        JOIN companies c ON tj.company_id = c.id
        JOIN insiders i ON tj.insider_id = i.id
        LIMIT 5
    ");
    $rows = $stmt->fetchAll();
    echo "Liaison (Transactions <-> Entreprises <-> Initiés) : " . count($rows) . " ligne(s) trouvée(s) sur les 5 demandées.\n";
    if (count($rows) === 0) {
        echo "⚠️ ALERTE : La jointure échoue. Vos transactions_jour ne sont plus liées à des entreprises ou initiés valides.\n";
    }
} catch (Exception $e) {
    echo "Erreur SQL lors de la jointure : " . $e->getMessage() . "\n";
}

// TEST 3 : Focus sur INFQ
echo "\n--- 3. TEST SPÉCIFIQUE (INFQ) ---\n";
$stmt = $pdo->query("SELECT id, ticker FROM companies WHERE ticker LIKE '%INFQ%'");
$company = $stmt->fetch();
if ($company) {
    echo "✅ Entreprise INFQ trouvée (ID interne: {$company['id']})\n";

    $stmtTx = $pdo->query("SELECT COUNT(*) FROM transactions_jour WHERE company_id = {$company['id']}");
    $countTx = $stmtTx->fetchColumn();
    echo "   Transactions (Agrégées) pour INFQ : $countTx\n";

    $stmtSp = $pdo->query("SELECT COUNT(*) FROM stock_prices WHERE ticker = 'INFQ'");
    $countSp = $stmtSp->fetchColumn();
    echo "   Jours de cotations (Prix) pour INFQ : $countSp\n";
} else {
    echo "❌ ERREUR : Le Ticker INFQ a disparu de la table 'companies' !\n";
}

// TEST 4 : Collation
echo "\n--- 4. VÉRIFICATION ENCODAGE ---\n";
$stmt = $pdo->query("SHOW VARIABLES LIKE 'collation_connection'");
$col = $stmt->fetch();
echo "Collation de la connexion PHP/PDO : " . $col['Value'] . "\n";

echo "\n========================================================\n";
echo " Fin du diagnostic.\n";
echo "========================================================\n";