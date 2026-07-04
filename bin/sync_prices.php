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

// NOUVEAU : Instanciation du service multi-sources
$finnhubKey = $_ENV['FINNHUB_API_KEY'] ?? '';
$profileService = new \App\Services\CompanyProfileService($finnhubKey);

echo "Démarrage de la synchronisation intelligente via YAHOO FINANCE...\n";

// NOUVEAU : On ajoute une colonne invisible 'meta_updated_at' virtuellement gérée
// Pour savoir quelles entreprises ont besoin d'un rafraîchissement des métadonnées
// (On va utiliser la date de création de l'entreprise si le secteur est NULL)
$stmt = $pdo->query("
    SELECT c.id, c.ticker, c.sector 
    FROM companies c
    JOIN transactions_jour tj ON c.id = tj.company_id
    GROUP BY c.id, c.ticker, c.sector
");
$companies = $stmt->fetchAll();

echo "Nombre d'entreprises à vérifier : " . count($companies) . "\n";

$apiCalls = 0;
$totalInserted = 0;
$today = time();

foreach ($companies as $company) {
    $ticker = $company['ticker'];
    $cleanTicker = preg_replace('/[^A-Za-z0-9\-\.]/', '', trim($ticker));
    $cleanTicker = strtoupper($cleanTicker);

    if (empty($cleanTicker)) continue;

    // NOUVEAU : PROTECTION ANTI-SPAM POUR YAHOO
    // Si l'entreprise est marquée NON-COTE ou INCONNU, on ne tente même pas de récupérer les prix !
    if ($cleanTicker === 'NON-COTE' || $company['sector'] === 'NON-COTE' || $company['sector'] === 'INCONNU') {
        continue;
    }

    // OPTIMISATION : On ne télécharge les métadonnées QUE SI le secteur est inconnu
    if (empty($company['sector'])) {
        $apiCalls++;
        $profile = $profileService->getProfile($cleanTicker);

        if ($profile !== null) {
            // MISE A JOUR MAJEURE : On sauvegarde 'shares_outstanding' au lieu de 'market_cap'
            $stmtUpdateMeta = $pdo->prepare("UPDATE companies SET sector = :s, shares_outstanding = :so, industry = :i WHERE id = :id");
            $stmtUpdateMeta->execute([
                's' => $profile['sector'],
                'so' => $profile['shares_outstanding'],
                'i' => $profile['industry'],
                'id' => $company['id']
            ]);
            echo "[$cleanTicker] Métadonnées acquises ({$profile['source']}). ";
        } else {
            // AUTO-NETTOYAGE : Introuvable sur tous les fournisseurs, on le marque INCONNU pour l'ignorer demain
            $stmtUpdateMeta = $pdo->prepare("UPDATE companies SET sector = 'INCONNU', industry = 'Introuvable sur toutes les sources' WHERE id = :id");
            $stmtUpdateMeta->execute(['id' => $company['id']]);
            echo "[$cleanTicker] Profil introuvable. Classé INCONNU.\n";
            continue; // On passe à l'entreprise suivante sans chercher les prix (qui feront 404 de toute façon)
        }
        usleep(300000); // Pause anti-spam
    }

    // INITIATIVE : Smart Delta Sync pour les prix
    $stmtDate = $pdo->prepare("SELECT MAX(price_date) FROM stock_prices WHERE ticker = :ticker");
    $stmtDate->execute(['ticker' => $cleanTicker]);
    $lastDate = $stmtDate->fetchColumn();

    if ($lastDate) {
        $from = strtotime($lastDate);
        if (date('Y-m-d', $from) === date('Y-m-d', $today)) {
            echo "[$cleanTicker] Déjà à jour. Ignoré.\n";
            continue;
        }
        echo "[$cleanTicker] MàJ depuis le $lastDate... ";
    } else {
        // MODIFICATION ICI POUR 2025 :
        // On passe à 36 mois en arrière pour garantir 200 jours de cours avant toute transaction de 2025.
        $from = strtotime('-36 months', $today);
        echo "[$cleanTicker] Nouvel ajout historique (36 mois)... ";
    }

    $to = $today;

    // 3. Appel à l'API de prix Yahoo
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$cleanTicker}?period1={$from}&period2={$to}&interval=1d";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $apiCalls++;

    $data = json_decode($response, true);
    $result = $data['chart']['result'][0] ?? null;

    if ($httpCode === 200 && $result && isset($result['timestamp'])) {
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
            echo "-> Échec SQL : " . $e->getMessage() . "\n";
        }
    } else {
        if ($httpCode === 404) echo "-> Ignoré : Introuvable.\n";
        else echo "-> Échec HTTP $httpCode\n";
    }

    usleep(500000);
}

echo "\n--- TERMINÉ ---\n";
echo "Appels API réalisés : $apiCalls\n";
echo "Lignes de cotations traitées : $totalInserted\n";