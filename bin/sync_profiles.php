<?php
// /var/www/ct.hsrv.fr/bin/sync_profiles.php

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

echo "========================================================\n";
echo " RATTRAPAGE DES PROFILS D'ENTREPRISES (Yahoo + Finnhub)\n";
echo "========================================================\n";

// On cible spécifiquement les entreprises dont le secteur ou l'industrie est vide
$stmt = $pdo->query("SELECT id, ticker, name FROM companies WHERE sector IS NULL OR industry IS NULL");
$companies = $stmt->fetchAll();

$totalCompanies = count($companies);
echo "Nombre d'entreprises à mettre à jour : {$totalCompanies}\n\n";

if ($totalCompanies === 0) {
    echo "Toutes les entreprises ont déjà leur profil à jour. Terminé.\n";
    exit(0);
}

$apiCalls = 0;
$updatedCount = 0;

foreach ($companies as $company) {
    $ticker = $company['ticker'];
    $companyName = $company['name'];
    $cleanTicker = preg_replace('/[^A-Za-z0-9\-\.]/', '', trim($ticker));
    $cleanTicker = strtoupper($cleanTicker);

    if (empty($cleanTicker)) continue;

    // --- FILTRAGE INTELLIGENT DES INTRUS (Avant appel API) ---
    $isNonCote = false;
    $reason = '';

    if ($cleanTicker === 'NONE' || $cleanTicker === 'NULL' || $cleanTicker === 'N/A') {
        $isNonCote = true;
        $reason = 'Ticker déclaré comme Invalide/None';
    } elseif ($cleanTicker === 'NA' && stripos($companyName, 'Nano Labs') === false) {
        $isNonCote = true;
        $reason = 'Faux ticker NA (Not Applicable)';
    } elseif (strlen($cleanTicker) === 5 && str_ends_with($cleanTicker, 'X')) {
        $isNonCote = true;
        $reason = 'Fonds Commun (Mutual Fund / Interval Fund)';
    }

    if ($isNonCote) {
        // On marque l'entreprise en NON-COTE pour ne plus la traiter à l'avenir
        $stmtUpdateMeta = $pdo->prepare("UPDATE companies SET sector = 'NON-COTE', industry = :rsn WHERE id = :id");
        $stmtUpdateMeta->execute(['rsn' => $reason, 'id' => $company['id']]);
        echo sprintf("[%s] Filtré : %s. Marquage 'NON-COTE'.\n", str_pad($cleanTicker, 5), $reason);
        $updatedCount++;
        continue; // On passe au suivant sans appeler l'API
    }
    // ---------------------------------------------------------

    // APPEL AU NOUVEAU SERVICE MULTI-SOURCES
    $apiCalls++;
    $profile = $profileService->getProfile($cleanTicker);

    if ($profile !== null) {
        $stmtUpdateMeta = $pdo->prepare("UPDATE companies SET sector = :s, shares_outstanding = :so, industry = :i WHERE id = :id");
        $stmtUpdateMeta->execute([
            's' => $profile['sector'],
            'so' => $profile['shares_outstanding'],
            'i' => $profile['industry'],
            'id' => $company['id']
        ]);

        $shortInd = $profile['industry'] ? substr($profile['industry'], 0, 20) : 'N/A';
        $sharesStr = $profile['shares_outstanding'] ? number_format($profile['shares_outstanding']) : 'N/A';

        echo sprintf("[%s] Succès (%s) - Ind: %s | Actions: %s\n", str_pad($cleanTicker, 5), $profile['source'], $shortInd, $sharesStr);
        $updatedCount++;
    } else {
        // AUTO-NETTOYAGE : Introuvable nulle part (ni Yahoo, ni Finnhub)
        $stmtUpdateMeta = $pdo->prepare("UPDATE companies SET sector = 'INCONNU', industry = 'Introuvable sur toutes les sources' WHERE id = :id");
        $stmtUpdateMeta->execute(['id' => $company['id']]);
        echo "[$cleanTicker] Échec complet. Entreprise marquée comme INCONNU.\n";
        $updatedCount++;
    }

    // Pause de 300ms cruciale pour ne pas se faire bannir par l'anti-spam de Yahoo
    usleep(300000);
}

echo "\n========================================================\n";
echo " TERMINÉ\n";
echo " Appels API réalisés : $apiCalls\n";
echo " Profils mis à jour (ou nettoyés) : $updatedCount / $totalCompanies\n";
echo "========================================================\n";