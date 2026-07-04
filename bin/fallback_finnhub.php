<?php
// /var/www/ct.hsrv.fr/bin/fallback_finnhub.php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// 1. Initialisation
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];
$finnhubKey = $_ENV['FINNHUB_API_KEY'] ?? '';

if (empty($finnhubKey)) {
    die("ERREUR : La clé FINNHUB_API_KEY est introuvable dans le fichier .env\n");
}

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "========================================================\n";
echo "    TEST DU FALLBACK FINNHUB (Sur les tickers INCONNUS) \n";
echo "========================================================\n";

// On cible uniquement les entreprises marquées comme INCONNU suite à un 404 Yahoo
$stmt = $pdo->query("SELECT id, ticker, name FROM companies WHERE sector = 'INCONNU'");
$companies = $stmt->fetchAll();

$totalCompanies = count($companies);
echo "Nombre d'entreprises à tester avec Finnhub : {$totalCompanies}\n\n";

if ($totalCompanies === 0) {
    echo "Aucune entreprise en statut INCONNU. Test terminé.\n";
    exit(0);
}

$apiCalls = 0;
$successCount = 0;

foreach ($companies as $company) {
    $cleanTicker = strtoupper(trim($company['ticker']));

    // Appel à l'API Company Profile 2 de Finnhub
    $url = "https://finnhub.io/api/v1/stock/profile2?symbol={$cleanTicker}&token={$finnhubKey}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiCalls++;

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);

        // Finnhub renvoie un objet vide {} si le ticker n'existe pas chez eux non plus
        if (!empty($data) && isset($data['finnhubIndustry'])) {
            $industry = $data['finnhubIndustry'];

            // Attention : Finnhub renvoie les sharesOutstanding en MILLIONS (ex: 4375.48 pour 4.3 milliards)
            // On multiplie par 1 000 000 pour correspondre à notre base (BIGINT) et à Yahoo
            $sharesOutstanding = isset($data['shareOutstanding']) ? (int)($data['shareOutstanding'] * 1000000) : null;

            // Mise à jour de la base de données
            $stmtUpdate = $pdo->prepare("UPDATE companies SET sector = :s, industry = :i, shares_outstanding = :so WHERE id = :id");
            // Finnhub ne donne que l'industry, on l'utilise pour le secteur aussi
            $stmtUpdate->execute([
                's' => $industry,
                'i' => $industry . ' (Via Finnhub)',
                'so' => $sharesOutstanding,
                'id' => $company['id']
            ]);

            echo sprintf("✅ [%s] Sauvé par Finnhub ! Ind: %s | Actions: %s\n", str_pad($cleanTicker, 5), substr($industry, 0, 20), $sharesOutstanding ? number_format($sharesOutstanding) : 'N/A');
            $successCount++;
        } else {
            // Finnhub ne connait pas non plus ce ticker
            echo sprintf("❌ [%s] Introuvable même sur Finnhub. Maintien en INCONNU.\n", str_pad($cleanTicker, 5));
        }
    } else {
        echo sprintf("⚠️  [%s] Échec de l'API Finnhub (HTTP %d)\n", str_pad($cleanTicker, 5), $httpCode);
    }

    // Protection Rate Limit Finnhub (60 requêtes / minute max en gratuit)
    // On fait une pause de 1 seconde par sécurité
    usleep(1000000);
}

echo "\n========================================================\n";
echo " BILAN DU TEST FINNHUB\n";
echo " Tickers sauvés : $successCount / $totalCompanies\n";
echo "========================================================\n";