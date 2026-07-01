<?php
// /var/www/ct.hsrv.fr/bin/cron.php

// Ce script est conçu pour être exécuté en ligne de commande (CLI) par le système.
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\IngestionModel;
use App\Services\SecFetcherService;

// 1. Initialisation de l'environnement et connexion BDD
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$db   = $_ENV['DB_NAME'] ?? 'cleartrade';
$user = $_ENV['DB_USER'] ?? 'ct_user';
$pass = $_ENV['DB_PASS'] ?? '';

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("[" . date('Y-m-d H:i:s') . "] ERREUR CRITIQUE : Connexion BDD impossible - " . $e->getMessage() . "\n");
}

$model = new IngestionModel($pdo);
$fetcher = new SecFetcherService();

echo "[" . date('Y-m-d H:i:s') . "] Début de l'ingestion automatique SEC...\n";

// 2. Exécution de la logique d'ingestion
try {
    $lastProcessedId = $model->getLastProcessedId();
    $newEntries = $fetcher->fetchNewFilings($lastProcessedId);

    if (empty($newEntries)) {
        echo "[" . date('Y-m-d H:i:s') . "] Aucun nouveau formulaire.\n";
        exit(0);
    }

    $processedCount = 0;
    $totalTransactionsInserted = 0;

    foreach ($newEntries as $entry) {
        $rawId = (string)$entry->id;

        // Extraction de l'Accession Number
        if (preg_match('/([0-9]{10}-[0-9]{2}-[0-9]{6})/', $rawId, $matches)) {
            $accessionNumber = $matches[1];
        } else {
            $accessionNumber = preg_replace('/^urn:.*:/', '', $rawId);
        }

        $link = '';
        foreach ($entry->link as $l) {
            if (isset($l['href'])) {
                $link = (string)$l['href'];
                break;
            }
        }

        if (empty($link)) continue;

        // Téléchargement
        $xml = $fetcher->fetchForm4Xml($link);
        usleep(200000); // Pause de 200ms pour préserver le serveur de la SEC

        if (!$xml) {
            $model->setLastProcessedId($accessionNumber);
            continue;
        }

        // Préparation des données
        $issuerData = [
            'cik' => (string)$xml->issuer->issuerCik,
            'name' => (string)$xml->issuer->issuerName,
            'ticker' => (string)$xml->issuer->issuerTradingSymbol
        ];

        $ownerData = [
            'cik' => (string)$xml->reportingOwner->reportingOwnerId->rptOwnerCik,
            'name' => (string)$xml->reportingOwner->reportingOwnerId->rptOwnerName
        ];

        // --- NOUVEAU : Extraction des Remarks et Footnotes ---
        $remarks = isset($xml->remarks) ? trim((string)$xml->remarks) : null;

        $footnotesMap = [];
        if (isset($xml->footnotes->footnote)) {
            foreach ($xml->footnotes->footnote as $fn) {
                $id = (string)$fn['id'];
                $footnotesMap[$id] = trim((string)$fn);
            }
        }
        // -----------------------------------------------------

        if (!isset($xml->nonDerivativeTable->nonDerivativeTransaction)) {
            $model->setLastProcessedId($accessionNumber);
            $processedCount++;
            continue;
        }

        $transactionsData = [];
        $title = isset($reportingOwner->reportingOwnerRelationship->officerTitle)
            ? (string)$reportingOwner->reportingOwnerRelationship->officerTitle
            : 'Director';

        $index = 0;
        foreach ($xml->nonDerivativeTable->nonDerivativeTransaction as $tx) {
            $tx_code = (string)$tx->transactionCoding->transactionCode;
            $price = (float)($tx->transactionAmounts->transactionPricePerShare->value ?? 0);

            // FILTRE STRICT : Uniquement les achats/ventes (P/S) sur le marché libre avec un prix valide
            if (!in_array($tx_code, ['P', 'S']) || $price <= 0) {
                $index++;
                continue;
            }

            if (isset($tx->transactionAmounts->transactionShares->value)) {

                // --- NOUVEAU : Mapping des notes de bas de page ---
                $lineFootnotes = [];
                $footnoteNodes = $tx->xpath('.//footnoteId');
                if ($footnoteNodes !== false) {
                    foreach ($footnoteNodes as $fnNode) {
                        $fnId = (string)$fnNode['id'];
                        if (isset($footnotesMap[$fnId])) {
                            $lineFootnotes[] = "[Note {$fnId}] : " . $footnotesMap[$fnId];
                        }
                    }
                }
                $footnotesText = !empty($lineFootnotes) ? implode("\n", $lineFootnotes) : null;
                // --------------------------------------------------

                $transactionsData[] = [
                    'line_idx' => $index,
                    'tx_date'  => (string)$tx->transactionDate->value,
                    'tx_code'  => $tx_code,
                    'shares'   => (int)$tx->transactionAmounts->transactionShares->value,
                    'price'    => $price,
                    'title'    => $title,
                    'remarks'  => $remarks, // Ajout
                    'footnotes'=> $footnotesText // Ajout
                ];
            }
            $index++;
        }

        // Insertion
        if (!empty($transactionsData)) {
            $insertedCount = $model->processForm4($accessionNumber, $issuerData, $ownerData, $transactionsData);
            $totalTransactionsInserted += $insertedCount;
        }

        $model->setLastProcessedId($accessionNumber);
        $processedCount++;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Succès : $processedCount formulaires lus, $totalTransactionsInserted transactions insérées.\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERREUR LORS DE L'INGESTION : " . $e->getMessage() . "\n";
    exit(1);
}