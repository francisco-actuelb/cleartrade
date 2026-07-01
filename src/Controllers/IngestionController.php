<?php
// /var/www/ct.hsrv.fr/src/Controllers/IngestionController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\IngestionModel;
use App\Services\SecFetcherService;
use PDO;
use Exception;

class IngestionController
{
    private IngestionModel $model;
    private SecFetcherService $fetcher;

    public function __construct(PDO $db)
    {
        $this->model = new IngestionModel($db);
        $this->fetcher = new SecFetcherService();
    }

    public function ingest(Request $request, Response $response, array $args): Response
    {
        try {
            $lastProcessedId = $this->model->getLastProcessedId();
            $newEntries = $this->fetcher->fetchNewFilings($lastProcessedId);

            if (empty($newEntries)) {
                $response->getBody()->write(json_encode([
                    'status' => 'success',
                    'message' => 'Aucun nouveau formulaire SEC à traiter.',
                    'details' => 'Dernier identifiant connu : ' . ($lastProcessedId ?: 'Aucun')
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $processedCount = 0;
            $totalTransactionsInserted = 0;

            foreach ($newEntries as $entry) {
                $rawId = (string)$entry->id;

                // CORRECTION : Extraction propre de l'Accession Number ici aussi
                if (preg_match('/([0-9]{10}-[0-9]{2}-[0-9]{6})/', $rawId, $matches)) {
                    $accessionNumber = $matches[1];
                } else {
                    $accessionNumber = preg_replace('/^urn:.*:/', '', $rawId);
                }

                // Récupération de l'URL du formulaire
                $link = '';
                foreach ($entry->link as $l) {
                    if (isset($l['href'])) {
                        $link = (string)$l['href'];
                        break;
                    }
                }

                if (empty($link)) {
                    continue;
                }

                $xml = $this->fetcher->fetchForm4Xml($link);

                usleep(200000); // Pause de 200ms pour préserver le serveur de la SEC

                if (!$xml) {
                    $this->model->setLastProcessedId($accessionNumber);
                    continue;
                }

                $issuerData = [
                    'cik' => (string)$xml->issuer->issuerCik,
                    'name' => (string)$xml->issuer->issuerName,
                    'ticker' => (string)$xml->issuer->issuerTradingSymbol
                ];

                $ownerData = [
                    'cik' => (string)$xml->reportingOwner->reportingOwnerId->rptOwnerCik,
                    'name' => (string)$xml->reportingOwner->reportingOwnerId->rptOwnerName
                ];

                $reportingOwner = isset($xml->reportingOwner[0]) ? $xml->reportingOwner[0] : $xml->reportingOwner;

                // --- NOUVEAU : Extraction des Remarks et Footnotes ---
                $remarks = isset($xml->remarks) ? trim((string)$xml->remarks) : null;

                // Si le CIK est introuvable, c'est un formulaire invalide, on passe au suivant.
                if (empty($issuerData['cik']) || empty($ownerData['cik'])) {
                    $this->model->setLastProcessedId($accessionNumber);
                    $processedCount++;
                    continue;
                }

                $footnotesMap = [];
                if (isset($xml->footnotes->footnote)) {
                    foreach ($xml->footnotes->footnote as $fn) {
                        $id = (string)$fn['id'];
                        $footnotesMap[$id] = trim((string)$fn);
                    }
                }
                // -----------------------------------------------------

                if (!isset($xml->nonDerivativeTable->nonDerivativeTransaction)) {
                    $this->model->setLastProcessedId($accessionNumber);
                    $processedCount++;
                    continue;
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

                        // --- NOUVEAU : Trouver les notes de bas de page liées à cette transaction spécifique ---
                        $lineFootnotes = [];
                        // On cherche toutes les balises <footnoteId> à l'intérieur de cette <nonDerivativeTransaction>
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
                        // --------------------------------------------------------------------------------------

                        $transactionsData[] = [
                            'line_idx' => $index,
                            'tx_date'  => (string)$tx->transactionDate->value,
                            'tx_code'  => (string)$tx->transactionCoding->transactionCode,
                            'shares'   => (int)$tx->transactionAmounts->transactionShares->value,
                            'price'    => (float)($tx->transactionAmounts->transactionPricePerShare->value ?? 0),
                            'title'    => $title,
                            'remarks'  => $remarks, // On ajoute la remarque globale
                            'footnotes'=> $footnotesText // On ajoute les notes spécifiques
                        ];
                    }
                    $index++;
                }

                if (!empty($transactionsData)) {
                    $insertedCount = $this->model->processForm4($accessionNumber, $issuerData, $ownerData, $transactionsData);
                    $totalTransactionsInserted += $insertedCount;
                }

                $this->model->setLastProcessedId($accessionNumber);
                $processedCount++;
            }

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'message' => "Ingestion réelle du flux SEC terminée.",
                'details' => "$processedCount formulaire(s) lu(s), $totalTransactionsInserted transaction(s) insérée(s).",
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Erreur critique lors de l\'ingestion : ' . $e->getMessage()
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}