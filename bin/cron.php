<?php
// /var/www/ct.hsrv.fr/bin/cron.php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\IngestionModel;
use App\Services\SecFetcherService;

// Initialisation
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
    ]);
} catch (PDOException $e) {
    die("[" . date('Y-m-d H:i:s') . "] Erreur de connexion BDD : " . $e->getMessage() . "\n");
}

$model = new IngestionModel($pdo);
$fetcher = new SecFetcherService();

echo "[" . date('Y-m-d H:i:s') . "] Démarrage du Cron SEC...\n";

try {
    $lastProcessedId = $model->getLastProcessedId();
    $newEntries = $fetcher->fetchNewFilings($lastProcessedId);

    if (empty($newEntries)) {
        echo "Aucun nouveau formulaire à traiter. (Dernier ID: " . ($lastProcessedId ?: 'Aucun') . ")\n";
        exit(0);
    }

    $processedCount = 0;
    $totalTransactionsInserted = 0;

    foreach ($newEntries as $entry) {
        $rawId = (string)$entry->id;

        // CORRECTION : Extraction propre de l'Accession Number
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

        $xml = $fetcher->fetchForm4Xml($link);
        usleep(200000); // 200ms de pause pour la SEC

        if (!$xml) {
            $model->setLastProcessedId($accessionNumber);
            continue;
        }

        $issuerData = [
            'cik' => isset($xml->issuer->issuerCik) ? (string)$xml->issuer->issuerCik : '',
            'name' => isset($xml->issuer->issuerName) ? (string)$xml->issuer->issuerName : 'UNKNOWN',
            'ticker' => isset($xml->issuer->issuerTradingSymbol) ? (string)$xml->issuer->issuerTradingSymbol : 'UNKNOWN'
        ];

        if (!isset($xml->reportingOwner[0]->reportingOwnerId)) {
            $model->setLastProcessedId($accessionNumber);
            $processedCount++;
            continue;
        }

        $ownerData = [
            'cik' => isset($xml->reportingOwner[0]->reportingOwnerId->rptOwnerCik) ? (string)$xml->reportingOwner[0]->reportingOwnerId->rptOwnerCik : '0000000000',
            'name' => isset($xml->reportingOwner[0]->reportingOwnerId->rptOwnerName) ? (string)$xml->reportingOwner[0]->reportingOwnerId->rptOwnerName : 'UNKNOWN'
        ];

        $remarks = isset($xml->remarks) ? trim((string)$xml->remarks) : null;
        $footnotesMap = [];
        if (isset($xml->footnotes->footnote)) {
            foreach ($xml->footnotes->footnote as $fn) {
                $id = (string)$fn['id'];
                $footnotesMap[$id] = trim((string)$fn);
            }
        }

        if (!isset($xml->nonDerivativeTable->nonDerivativeTransaction)) {
            $model->setLastProcessedId($accessionNumber);
            $processedCount++;
            continue;
        }

        $transactionsData = [];

        $title = 'Director';
        if (isset($xml->reportingOwner[0]->reportingOwnerRelationship->officerTitle)) {
            $title = (string)$xml->reportingOwner[0]->reportingOwnerRelationship->officerTitle;
        }

        $index = 0;
        foreach ($xml->nonDerivativeTable->nonDerivativeTransaction as $tx) {
            $tx_code = (string)$tx->transactionCoding->transactionCode;
            $price = (float)($tx->transactionAmounts->transactionPricePerShare->value ?? 0);

            if (!in_array($tx_code, ['P', 'S']) || $price <= 0) {
                $index++;
                continue;
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
                    if (preg_match('/10b5[- ]1/i', $combinedText)) {
                        $is10b51 = 1;
                    }
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
        }

        $model->setLastProcessedId($accessionNumber);
        $processedCount++;
    }

    echo "Succès : $processedCount formulaire(s) lu(s), $totalTransactionsInserted transaction(s) insérée(s).\n";

} catch (Exception $e) {
    echo "Erreur critique : " . $e->getMessage() . "\n";
    exit(1);
}