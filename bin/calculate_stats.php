<?php
// /var/www/ct.hsrv.fr/bin/calculate_stats.php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// 1. Initialisation de l'environnement et de la BDD
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

echo "Démarrage du calcul des statistiques (Win Rates) via la BDD locale...\n";

/**
 * 2. Fonction utilitaire pour chercher le prix local le plus proche.
 * Si le J+90 tombe un dimanche, on prend le lundi qui suit.
 */
function getLocalPrice($pdo, $ticker, $targetDate) {
    $stmt = $pdo->prepare("
        SELECT close_price 
        FROM stock_prices 
        WHERE ticker = :ticker AND price_date >= :target_date 
        ORDER BY price_date ASC 
        LIMIT 1
    ");
    $stmt->execute(['ticker' => $ticker, 'target_date' => $targetDate]);
    return $stmt->fetchColumn(); // Retourne le prix ou false
}

$totalProcessed = 0;

// BOUCLE AUTOMATIQUE : Traitement par lots de 500 pour ne pas saturer la RAM
while (true) {
    $stmtInsiders = $pdo->query("
        SELECT DISTINCT tj.insider_id 
        FROM transactions_jour tj
        LEFT JOIN insider_stats ists ON tj.insider_id = ists.insider_id
        WHERE ists.insider_id IS NULL 
           OR ists.updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        LIMIT 500
    ");
    $insiders = $stmtInsiders->fetchAll(PDO::FETCH_COLUMN);

    if (empty($insiders)) {
        echo "\nTerminé ! Tous les initiés sont à jour.\n";
        break; // On sort de la boucle infinie
    }

    $batchProcessed = 0;

    foreach ($insiders as $insiderId) {
        // Récupérer les transactions agrégées de cet initié
        $stmtTx = $pdo->prepare("
            SELECT tj.transaction_date, tj.transaction_code, tj.avg_price_per_share, c.ticker 
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            WHERE tj.insider_id = :iid
        ");
        $stmtTx->execute(['iid' => $insiderId]);
        $transactions = $stmtTx->fetchAll();

        $stats3m = ['wins' => 0, 'total' => 0, 'returns' => []];
        $stats6m = ['wins' => 0, 'total' => 0, 'returns' => []];

        foreach ($transactions as $tx) {
            $tradeDate = $tx['transaction_date'];
            $basePrice = (float)$tx['avg_price_per_share'];
            $ticker = $tx['ticker'];
            $type = $tx['transaction_code']; // 'P' ou 'S'

            $date3m = date('Y-m-d', strtotime("$tradeDate +90 days"));
            $date6m = date('Y-m-d', strtotime("$tradeDate +180 days"));
            $today = date('Y-m-d');

            // --- Analyse à 3 Mois ---
            if ($date3m <= $today) {
                $price3m = getLocalPrice($pdo, $ticker, $date3m);
                if ($price3m !== false && $basePrice > 0) {
                    $stats3m['total']++;
                    $returnPct = (($price3m - $basePrice) / $basePrice) * 100;
                    if ($type === 'S') $returnPct = -$returnPct; // Sur une vente, la baisse est une victoire

                    $stats3m['returns'][] = $returnPct;
                    if ($returnPct > 0) $stats3m['wins']++;
                }
            }

            // --- Analyse à 6 Mois ---
            if ($date6m <= $today) {
                $price6m = getLocalPrice($pdo, $ticker, $date6m);
                if ($price6m !== false && $basePrice > 0) {
                    $stats6m['total']++;
                    $returnPct = (($price6m - $basePrice) / $basePrice) * 100;
                    if ($type === 'S') $returnPct = -$returnPct;

                    $stats6m['returns'][] = $returnPct;
                    if ($returnPct > 0) $stats6m['wins']++;
                }
            }
        }

        // 4. Calcul des pourcentages finaux
        $winRate3m = $stats3m['total'] > 0 ? round(($stats3m['wins'] / $stats3m['total']) * 100) : null;
        $avgReturn3m = $stats3m['total'] > 0 ? round(array_sum($stats3m['returns']) / $stats3m['total'], 2) : null;

        $winRate6m = $stats6m['total'] > 0 ? round(($stats6m['wins'] / $stats6m['total']) * 100) : null;
        $avgReturn6m = $stats6m['total'] > 0 ? round(array_sum($stats6m['returns']) / $stats6m['total'], 2) : null;

        // 5. Sauvegarde en Base de Données (MÊME SI VIDE, pour éviter la boucle infinie)
        $stmtInsert = $pdo->prepare("
            INSERT INTO insider_stats (insider_id, win_rate_3m, total_trades_3m, avg_return_3m, win_rate_6m, total_trades_6m, avg_return_6m, updated_at)
            VALUES (:id, :wr3, :tt3, :ar3, :wr6, :tt6, :ar6, NOW())
            ON DUPLICATE KEY UPDATE
                win_rate_3m = VALUES(win_rate_3m),
                total_trades_3m = VALUES(total_trades_3m),
                avg_return_3m = VALUES(avg_return_3m),
                win_rate_6m = VALUES(win_rate_6m),
                total_trades_6m = VALUES(total_trades_6m),
                avg_return_6m = VALUES(avg_return_6m),
                updated_at = NOW()
        ");

        $stmtInsert->execute([
            'id' => $insiderId,
            'wr3' => $winRate3m,
            'tt3' => $stats3m['total'],
            'ar3' => $avgReturn3m,
            'wr6' => $winRate6m,
            'tt6' => $stats6m['total'],
            'ar6' => $avgReturn6m,
        ]);

        $batchProcessed++;
        $totalProcessed++;

        // Affichage console propre (écrase la ligne précédente)
        echo "\rTraitement en cours... $totalProcessed initiés analysés.";
    }
}

echo "\nCalcul global terminé avec succès !\n";