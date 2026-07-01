<?php
// /var/www/ct.hsrv.fr/bin/sync_prices.php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// 1. Initialisation
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Démarrage de la synchronisation intelligente via YAHOO FINANCE...\n";

// 2. Récupérer la liste des entreprises uniques ayant des transactions
$stmt = $pdo->query("
    SELECT DISTINCT c.ticker 
    FROM companies c
    JOIN transactions_jour tj ON c.id = tj.company_id
    -- WHERE c.ticker > 'ADTN'
");
$tickers = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Nombre d'entreprises à vérifier : " . count($tickers) . "\n";

$apiCalls = 0;
$totalInserted = 0;

foreach ($tickers as $ticker) {
    // Nettoyage TRÈS strict du ticker
    $cleanTicker = preg_replace('/[^A-Za-z0-9\-\.]/', '', trim($ticker));
    $cleanTicker = strtoupper($cleanTicker);

    if (empty($cleanTicker)) continue;

    // INITIATIVE : Smart Delta Sync
    $stmtDate = $pdo->prepare("SELECT MAX(price_date) FROM stock_prices WHERE ticker = :ticker");
    $stmtDate->execute(['ticker' => $cleanTicker]);
    $lastDate = $stmtDate->fetchColumn();

    $today = time();

    if ($lastDate) {
        $from = strtotime($lastDate);
        if (date('Y-m-d', $from) === date('Y-m-d', $today)) {
            echo "[$cleanTicker] Déjà à jour. Ignoré.\n";
            continue;
        }
        echo "[$cleanTicker] Mise à jour depuis le $lastDate... ";
    } else {
        // Yahoo Finance gère très bien des historiques longs. Remontons d'un an et demi par sécurité.
        $from = strtotime('-18 months', $today);
        echo "[$cleanTicker] Nouvel ajout. Téléchargement de l'historique... ";
    }

    $to = $today;

    // 3. Appel à l'API (non-officielle mais publique) de Yahoo Finance
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$cleanTicker}?period1={$from}&period2={$to}&interval=1d";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Yahoo bloque les bots sans User-Agent. On se fait passer pour Chrome.
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $apiCalls++;

    $data = json_decode($response, true);
    $result = $data['chart']['result'][0] ?? null;

    if ($httpCode === 200 && $result && isset($result['timestamp'])) {

        // PROTECTION : Try...Catch pour éviter tout crash (ex: valeurs hors limites)
        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare("
                INSERT INTO stock_prices (ticker, price_date, open_price, high_price, low_price, close_price, volume)
                VALUES (:t, :pd, :op, :hp, :lp, :cp, :vol)
                ON DUPLICATE KEY UPDATE
                    open_price = VALUES(open_price),
                    high_price = VALUES(high_price),
                    low_price = VALUES(low_price),
                    close_price = VALUES(close_price),
                    volume = VALUES(volume)
            ");

            $count = 0;
            $timestamps = $result['timestamp'];
            $quotes = $result['indicators']['quote'][0];

            foreach ($timestamps as $index => $timestamp) {
                // Parfois Yahoo retourne des valeurs nulles si la bourse a fermé plus tôt, on les ignore
                if (!isset($quotes['close'][$index])) continue;

                $insertStmt->execute([
                    't'   => $cleanTicker,
                    'pd'  => date('Y-m-d', $timestamp),
                    'op'  => $quotes['open'][$index] ?? null,
                    'hp'  => $quotes['high'][$index] ?? null,
                    'lp'  => $quotes['low'][$index] ?? null,
                    'cp'  => $quotes['close'][$index],
                    'vol' => $quotes['volume'][$index] ?? null
                ]);
                $count++;
            }

            $pdo->commit();
            $totalInserted += $count;
            echo "$count jours enregistrés.\n";

        } catch (\PDOException $e) {
            $pdo->rollBack();
            echo "-> Échec SQL (Ignoré) : " . $e->getMessage() . "\n";
        }

    } else {
        if ($httpCode === 404) {
            echo "-> Ignoré : Ticker introuvable chez Yahoo Finance (Probablement racheté ou faillite).\n";
        } else {
            $errorMsg = $data['chart']['error']['description'] ?? "Erreur HTTP $httpCode";
            echo "-> Échec : $errorMsg\n";
        }
    }

    // Pause de sécurité : Yahoo est très tolérant, 0.5s suffisent amplement.
    usleep(500000);
}

echo "\n--- TERMINÉ ---\n";
echo "Appels API réalisés : $apiCalls\n";
echo "Lignes de cotations traitées : $totalInserted\n";