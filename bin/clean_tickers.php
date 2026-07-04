<?php
// /var/www/ct.hsrv.fr/bin/clean_tickers.php

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

echo "========================================================\n";
echo "    NETTOYAGE ET NORMALISATION DES TICKERS (SEC -> YF)  \n";
echo "========================================================\n";

// VOS MODIFICATIONS : On cible uniquement ceux qui ont fait un 404
$stmt = $pdo->query("SELECT id, ticker, name FROM companies WHERE industry = 'Introuvable sur Yahoo'");
$companies = $stmt->fetchAll();

echo "Nombre total d'entreprises 404 à analyser : " . count($companies) . "\n\n";

if (count($companies) === 0) {
    echo "Aucune entreprise à nettoyer. Terminé.\n";
    exit(0);
}

$cleanedCount = 0;
$garbageCount = 0;

foreach ($companies as $company) {
    $id = $company['id'];
    $originalTicker = trim($company['ticker']);
    $companyName = $company['name'];

    $t = $originalTicker;

    // 1. Nettoyage des préfixes de bourse (ex: NYSE: VTEX -> VTEX)
    $t = preg_replace('/^(NYSE|NASDAQ|ASX|AMEX|OTC|CBOE):\s*/i', '', $t);

    // 2. Séparation des multi-tickers (ex: "GEF, GEF-B" ou "Z AND ZG" ou "JUSH/JUSHF" ou "CRDA CRDB")
    if (preg_match('/^([A-Z0-9\-\.]+)(?:\s+|,|\/|AND)/i', $t, $matches)) {
        $t = $matches[1];
    }

    // Exception Nano Labs (vrai ticker NA)
    if (strtoupper($t) === 'NA' && stripos($companyName, 'Nano Labs') !== false) {
        // C'est le vrai Nano Labs, on valide
    } else {
        // 3. Normalisation des classes d'actions pour Yahoo Finance (ex: BRK.B -> BRK-B)
        $t = preg_replace('/\.([A-Z0-9])$/i', '-$1', $t);
    }

    $t = strtoupper(trim($t));

    // 4. Détection des valeurs "Poubelles" / Erreurs de saisie
    $isGarbage = false;
    $garbageReason = '';

    if (empty($t) || $t === 'NONE' || $t === 'NULL' || $t === 'N/A' || $t === 'NON-ASSIGN' || $t === 'AB-LEND') {
        $isGarbage = true;
        $garbageReason = 'Ticker invalide textuel';
    } elseif (is_numeric($t) || strlen($t) > 8) {
        $isGarbage = true;
        $garbageReason = 'Numéro CIK ou code erroné';
    } elseif ($t === 'NA' && stripos($companyName, 'Nano Labs') === false) {
        $isGarbage = true;
        $garbageReason = 'Faux ticker NA (Not Applicable)';
    } elseif (preg_match('/-(PR[A-Z]?|P[A-Z]?|W|R|U)$/i', $t)) {
        // NOUVEAU : Détection des Actions Préférentielles (ex: CFTR-PRA, OAK-PA), Warrants (-W)
        $isGarbage = true;
        $garbageReason = 'Actions préférentielles / Warrants (Non supporté)';
    } elseif (stripos($companyName, 'Fund') !== false || stripos($companyName, 'Trust') !== false) {
        // NOUVEAU : Détection des Fonds / Trusts bizarres (ex: ECF-B, GRX-E)
        $isGarbage = true;
        $garbageReason = 'Fonds d\'investissement / Trust non standard';
    }

    // 5. Application des changements en Base de données
    if ($isGarbage) {
        // On confirme le statut NON-COTE
        $stmtUpdate = $pdo->prepare("UPDATE companies SET ticker = 'NON-COTE', sector = 'NON-COTE', industry = :reason WHERE id = :id");
        $stmtUpdate->execute(['reason' => $garbageReason, 'id' => $id]);
        echo sprintf("🗑️  [%s] -> Maintenu en quarantaine (%s) | %s\n", $originalTicker, $garbageReason, substr($companyName, 0, 30));
        $garbageCount++;
    } elseif ($t !== $originalTicker) {
        // VOS MODIFICATIONS : Le ticker a été nettoyé, on remet sector et industry à NULL pour déclencher un nouveau fetch Yahoo !
        $stmtUpdate = $pdo->prepare("UPDATE companies SET ticker = :new_ticker, sector = NULL, industry = NULL WHERE id = :id");
        $stmtUpdate->execute(['new_ticker' => $t, 'id' => $id]);
        echo sprintf("✨ [%s] -> Corrigé en [%s] et replacé en file d'attente | %s\n", str_pad($originalTicker, 12), str_pad($t, 6), substr($companyName, 0, 30));
        $cleanedCount++;
    } else {
        // NOUVEAU : Le ticker est propre mais Yahoo renvoie TOUJOURS 404. C'est donc un actif mort.
        $stmtUpdate = $pdo->prepare("UPDATE companies SET sector = 'NON-COTE', industry = 'Radié ou non supporté par Yahoo' WHERE id = :id");
        $stmtUpdate->execute(['id' => $id]);
        echo sprintf("🛑 [%s] -> Incurable (404 confirmé). Classé NON-COTE | %s\n", str_pad($originalTicker, 12), substr($companyName, 0, 30));
        $garbageCount++;
    }
}

echo "\n========================================================\n";
echo " FIN DU NETTOYAGE\n";
echo " Tickers corrigés et replacés en attente : $cleanedCount\n";
echo " Tickers radiés / maintenus en quarantaine : $garbageCount\n";
echo "========================================================\n";