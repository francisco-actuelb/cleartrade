<?php
// /var/www/ct.hsrv.fr/bin/backfill.php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\IngestionModel;
use App\Services\SecFetcherService;

if ($argc < 3) {
    die("Usage: php backfill.php YYYY-MM-DD YYYY-MM-DD\nExemple: php backfill.php 2025-07-01 2025-12-31\n");
}

$startDate = $argv[1];
$endDate = $argv[2];

// Initialisation
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

$model = new IngestionModel($pdo);
$fetcher = new SecFetcherService();

echo "========================================================\n";
echo " BACKFILL HISTORIQUE SEC ({$startDate} au {$endDate})\n";
echo "========================================================\n";

// NOUVELLE STRATÉGIE : On itère sur les entreprises valides de notre BDD
// Cela permet de contourner la limitation "getcurrent" de la SEC
$stmt = $pdo->query("SELECT id, cik, ticker, name FROM companies WHERE sector != 'NON-COTE' AND sector != 'INCONNU' ORDER BY ticker");
$companies = $stmt->fetchAll();

echo "Nombre d'entreprises à scanner : " . count($companies) . "\n\n";

$dateEndFormatted = str_replace('-', '', $endDate);
$options = ['http' => ['header' => "User-Agent: ClearTrade (contact@hsrv.fr)\r\n"]];
$context = stream_context_create($options);

$totalFormsProcessed = 0;
$totalTransactionsInserted = 0;
$apiCalls = 0;

foreach ($companies as $company) {
    $cik = $company['cik'];
    $ticker = $company['ticker'];

    // Le CIK doit souvent être paddé à 10 chiffres pour l'URL
    $cikPad = str_pad($cik, 10, '0', STR_PAD_LEFT);

    $startOffset = 0;
    $keepFetching = true;
    $companyFormsCount = 0;

    echo "Scan de [$ticker]... ";

    while ($keepFetching) {
        // Changement crucial : action=getcompany permet de remonter le temps avec dateb
        $url = "https://www.sec.gov/cgi-bin/browse-edgar?action=getcompany&CIK={$cikPad}&type=4&dateb={$dateEndFormatted}&owner=include&start={$startOffset}&count=100&output=atom";

        $rssContent = @file_get_contents($url, false, $context);
        $apiCalls++;
        usleep(150000); // Pause de 150ms obligatoire (Max 10 requêtes / sec chez la SEC)

        if (!$rssContent) break;

        $xmlRss = new \SimpleXMLElement($rssContent);
        if (!isset($xmlRss->entry) || count($xmlRss->entry) == 0) {
            break; // Plus aucun résultat pour cette entreprise
        }

        foreach ($xmlRss->entry as $entry) {
            $updatedStr = (string)$entry->updated;
            $entryDate = substr($updatedStr, 0, 10);

            // Le flux est trié du plus récent au plus ancien
            if ($entryDate < $startDate) {
                $keepFetching = false;
                break; // On est remonté trop loin, on passe à l'entreprise suivante
            }

            // Le formulaire est dans notre fenêtre de tir !
            if ($entryDate >= $startDate && $entryDate <= $endDate) {
                $rawId = (string)$entry->id;
                if (preg_match('/([0-9]{10}-[0-9]{2}-[0-9]{6})/', $rawId, $matches)) {
                    $accessionNumber = $matches[1];
                } else {
                    $accessionNumber = preg_replace('/^urn:.*:/', '', $rawId);
                }

                $link = '';
                foreach ($entry->link as $l) {
                    if (isset($l['href'])) { $link = (string)$l['href']; break; }
                }
                if (empty($link)) continue;

                // Téléchargement du XML complet
                $xml = $fetcher->fetchForm4Xml($link);
                $apiCalls++;
                usleep(150000);

                if (!$xml) continue;

                // CORRECTION : Extraction sécurisée des données de l'émetteur
                $issuerData = [
                    'cik' => isset($xml->issuer->issuerCik) ? (string)$xml->issuer->issuerCik : '',
                    'name' => isset($xml->issuer->issuerName) ? (string)$xml->issuer->issuerName : 'UNKNOWN',
                    'ticker' => isset($xml->issuer->issuerTradingSymbol) ? (string)$xml->issuer->issuerTradingSymbol : 'UNKNOWN'
                ];

                // CORRECTION DES WARNINGS : On vérifie la chaîne complète
                // En PHP, isset($a->b->c) protège contre l'erreur "property on null" typique avec SimpleXML
                if (!isset($xml->reportingOwner[0]->reportingOwnerId)) {
                    continue;
                }

                $ownerCik = isset($xml->reportingOwner[0]->reportingOwnerId->rptOwnerCik) ? (string)$xml->reportingOwner[0]->reportingOwnerId->rptOwnerCik : '0000000000';
                $ownerName = isset($xml->reportingOwner[0]->reportingOwnerId->rptOwnerName) ? (string)$xml->reportingOwner[0]->reportingOwnerId->rptOwnerName : 'UNKNOWN';

                $ownerData = [
                    'cik' => $ownerCik,
                    'name' => $ownerName
                ];

                $remarks = isset($xml->remarks) ? trim((string)$xml->remarks) : null;
                $footnotesMap = [];
                if (isset($xml->footnotes->footnote)) {
                    foreach ($xml->footnotes->footnote as $fn) {
                        $footnotesMap[(string)$fn['id']] = trim((string)$fn);
                    }
                }

                if (!isset($xml->nonDerivativeTable->nonDerivativeTransaction)) continue;

                $transactionsData = [];

                // CORRECTION : Sécurisation du rôle de la même manière
                $title = 'Director';
                if (isset($xml->reportingOwner[0]->reportingOwnerRelationship->officerTitle)) {
                    $title = (string)$xml->reportingOwner[0]->reportingOwnerRelationship->officerTitle;
                }

                $index = 0;
                foreach ($xml->nonDerivativeTable->nonDerivativeTransaction as $tx) {
                    $tx_code = (string)$tx->transactionCoding->transactionCode;
                    $price = (float)($tx->transactionAmounts->transactionPricePerShare->value ?? 0);

                    if (!in_array($tx_code, ['P', 'S']) || $price <= 0) {
                        $index++; continue;
                    }

                    if (isset($tx->transactionAmounts->transactionShares->value)) {
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

                        $sharesFollowing = isset($tx->postTransactionAmounts->sharesOwnedFollowingTransaction->value)
                            ? (int)$tx->postTransactionAmounts->sharesOwnedFollowingTransaction->value : 0;

                        $dirInd = isset($tx->ownershipNature->directOrIndirectOwnership->value)
                            ? (string)$tx->ownershipNature->directOrIndirectOwnership->value : 'D';

                        $is10b51 = 0;
                        if (isset($tx->rule10b51Transaction) && in_array(strtolower((string)$tx->rule10b51Transaction), ['1', 'true'])) {
                            $is10b51 = 1;
                        }
                        if (!$is10b51) {
                            $combinedText = ($footnotesText ?? '') . ' ' . ($remarks ?? '');
                            if (preg_match('/10b5[- ]1/i', $combinedText)) $is10b51 = 1;
                        }

                        $transactionsData[] = [
                            'line_idx' => $index,
                            'tx_date'  => (string)$tx->transactionDate->value,
                            'tx_code'  => $tx_code,
                            'shares'   => (int)$tx->transactionAmounts->transactionShares->value,
                            'price'    => $price,
                            'title'    => $title,
                            'remarks'  => $remarks,
                            'footnotes'=> $footnotesText,
                            'shares_following' => $sharesFollowing,
                            'direct_or_indirect' => $dirInd,
                            'is_10b51' => $is10b51
                        ];
                    }
                    $index++;
                }

                if (!empty($transactionsData)) {
                    $insertedCount = $model->processForm4($accessionNumber, $issuerData, $ownerData, $transactionsData);
                    $totalTransactionsInserted += $insertedCount;
                    $companyFormsCount++;
                    $totalFormsProcessed++;
                }
            }
        }

        if ($keepFetching) {
            $startOffset += 100;
            // Sécurité anti-boucle infinie (2000 formulaires max par entreprise sur la période)
            if ($startOffset >= 2000) break;
        }
    }

    if ($companyFormsCount > 0) echo "$companyFormsCount formulaires trouvés.\n";
    else echo "Aucun mouvement.\n";
}

echo "\n--- BILAN DU BACKFILL ---\n";
echo "Appels API SEC : $apiCalls\n";
echo "Formulaires analysés : $totalFormsProcessed\n";
echo "Transactions ajoutées à la BDD : $totalTransactionsInserted\n";