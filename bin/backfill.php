<?php
// /var/www/ct.hsrv.fr/bin/backfill.php

// Ce script est conçu pour être exécuté en ligne de commande (CLI).
// Il télécharge l'historique massif des formulaires SEC.
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\IngestionModel;

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
    die("ERREUR CRITIQUE : Connexion BDD impossible - " . $e->getMessage() . "\n");
}

$model = new IngestionModel($pdo);

// 2. Configuration du rattrapage via les arguments CLI (Ligne de commande)
if ($argc !== 3) {
    echo "=================================================\n";
    echo " ERREUR : Paramètres manquants\n";
    echo " Usage   : php bin/backfill.php <date_debut> <date_fin>\n";
    echo " Exemple : php bin/backfill.php 2025-07-01 2025-07-31\n";
    echo "=================================================\n";
    exit(1);
}

try {
    $startDate = new DateTime($argv[1]);
    $endDate = new DateTime($argv[2]);
} catch (Exception $e) {
    die("ERREUR : Format de date invalide. Veuillez utiliser AAAA-MM-JJ.\n");
}

if ($startDate > $endDate) {
    die("ERREUR : La date de début doit être antérieure ou égale à la date de fin.\n");
}

echo "=================================================\n";
echo " DÉMARRAGE DU RATTRAPAGE SEC (BACKFILL)\n";
echo " Période : " . $startDate->format('Y-m-d') . " au " . $endDate->format('Y-m-d') . "\n";
echo "=================================================\n";

$interval = new DateInterval('P1D');
// On clone $endDate pour ne pas la modifier de façon permanente lors de l'ajout du jour supplémentaire
$period = new DatePeriod($startDate, $interval, (clone $endDate)->modify('+1 day'));

$optionsHttp = [
    'http' => [
        'header' => "User-Agent: ClearTrade Backfill (contact@hsrv.fr)\r\n"
    ]
];
$context = stream_context_create($optionsHttp);

// Fonction locale pour parser le XML depuis un lien TXT direct
function fetchAndParseXmlFromTxt($txtUrl, $context) {
    $rawText = @file_get_contents($txtUrl, false, $context);
    if (!$rawText) return null;

    if (preg_match('/<XML>(.*?)<\/XML>/s', $rawText, $matches)) {
        $xmlString = trim($matches[1]);
        $xmlString = preg_replace('/<\?xml.*\?>/', '', $xmlString);
        try {
            return new \SimpleXMLElement($xmlString);
        } catch (\Exception $e) {
            return null;
        }
    }
    return null;
}

$totalJours = 0;
$totalFormulaires = 0;
$totalTransactions = 0;

// 3. Boucle sur chaque jour de la période
foreach ($period as $date) {
    $year = $date->format('Y');
    $month = (int)$date->format('m');
    $dateStr = $date->format('Ymd');

    // Calcul du trimestre (Q1, Q2, Q3, Q4) pour l'URL SEC
    $quarter = ceil($month / 3);
    $qStr = "QTR" . $quarter;

    // URL de l'index quotidien de la SEC
    $idxUrl = "https://www.sec.gov/Archives/edgar/daily-index/$year/$qStr/form.$dateStr.idx";

    echo "[" . $date->format('Y-m-d') . "] Téléchargement de l'index... ";

    $idxContent = @file_get_contents($idxUrl, false, $context);
    usleep(150000); // Pause respectueuse (150ms)

    if (!$idxContent) {
        echo "Aucun fichier (probablement un week-end/jour férié).\n";
        continue;
    }

    $totalJours++;
    $lignes = explode("\n", $idxContent);
    $form4Urls = [];

    // On cherche les lignes qui commencent exactement par "4 " (le formulaire Form 4)
    foreach ($lignes as $ligne) {
        if (strpos($ligne, '4 ') === 0) {
            // L'URL se trouve à la fin de la ligne, ex: edgar/data/320193/0000320193-25-000001.txt
            if (preg_match('/(edgar\/data\/[0-9]+\/[0-9\-]+\.txt)/', $ligne, $matches)) {
                $form4Urls[] = "https://www.sec.gov/Archives/" . $matches[1];
            }
        }
    }

    $nbForms = count($form4Urls);
    echo "$nbForms Form 4 trouvés.\n";

    if ($nbForms === 0) continue;

    $insertedCeJour = 0;

    // 4. Traitement de chaque Form 4 de la journée
    foreach ($form4Urls as $i => $txtUrl) {
        // Affichage de la progression (% calculé)
        if ($i % 50 === 0) {
            $pct = round(($i / $nbForms) * 100);
            echo "   -> Progression jour : $pct% ($i/$nbForms)\n";
        }

        // Extraction du Accession Number depuis l'URL
        $accessionNumber = '';
        if (preg_match('/([0-9]{10}-[0-9]{2}-[0-9]{6})\.txt$/', $txtUrl, $matches)) {
            $accessionNumber = $matches[1];
        } else {
            continue;
        }

        $xml = fetchAndParseXmlFromTxt($txtUrl, $context);
        usleep(150000); // 150ms de pause obligatoire = max ~6 requêtes par seconde

        if (!$xml) continue;

        // Préparation des données
        $issuerData = [
            'cik' => (string)$xml->issuer->issuerCik,
            'name' => (string)$xml->issuer->issuerName,
            'ticker' => (string)$xml->issuer->issuerTradingSymbol
        ];

        $reportingOwner = isset($xml->reportingOwner[0]) ? $xml->reportingOwner[0] : $xml->reportingOwner;

        $ownerData = [
            'cik' => (string)$xml->reportingOwner->reportingOwnerId->rptOwnerCik,
            'name' => (string)$xml->reportingOwner->reportingOwnerId->rptOwnerName
        ];

        // Si le CIK est introuvable, c'est un formulaire invalide, on passe au suivant.
        if (empty($issuerData['cik']) || empty($ownerData['cik'])) {
            continue;
        }

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
            continue; // Pas de transactions classiques, on ignore
        }

        $transactionsData = [];
        $title = isset($xml->reportingOwner->reportingOwnerRelationship->officerTitle)
            ? (string)$xml->reportingOwner->reportingOwnerRelationship->officerTitle
            : 'Director';

        $index = 0;
        foreach ($xml->nonDerivativeTable->nonDerivativeTransaction as $tx) {
            $tx_code = (string)$tx->transactionCoding->transactionCode;

            // FILTRE STRATÉGIQUE : On ne garde QUE les Achats (P) et les Ventes (S) sur le marché ouvert
            if (!in_array($tx_code, ['P', 'S'])) {
                continue;
            }

            if (isset($tx->transactionAmounts->transactionShares->value)) {

                // --- NOUVEAU : Trouver les notes de bas de page liées à cette transaction ---
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
                // -----------------------------------------------------------------------------

                $transactionsData[] = [
                    'line_idx' => $index,
                    'tx_date'  => (string)$tx->transactionDate->value,
                    'tx_code'  => $tx_code,
                    'shares'   => (int)$tx->transactionAmounts->transactionShares->value,
                    'price'    => (float)($tx->transactionAmounts->transactionPricePerShare->value ?? 0),
                    'title'    => $title,
                    'remarks'  => $remarks, // Ajout
                    'footnotes'=> $footnotesText // Ajout
                ];
            }
            $index++;
        }

        if (!empty($transactionsData)) {
            $insertedCount = $model->processForm4($accessionNumber, $issuerData, $ownerData, $transactionsData);
            $insertedCeJour += $insertedCount;
            $totalTransactions += $insertedCount;
        }
        $totalFormulaires++;
    }

    echo "   => Fin de journée : $insertedCeJour transactions pertinentes (P/S) ajoutées.\n";
}

echo "=================================================\n";
echo " RATTRAPAGE TERMINÉ ! \n";
echo " Jours avec activité SEC : $totalJours\n";
echo " Formulaires analysés    : $totalFormulaires\n";
echo " Transactions insérées   : $totalTransactions\n";
echo "=================================================\n";