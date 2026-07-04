<?php
// /var/www/ct.hsrv.fr/src/Models/IngestionModel.php

namespace App\Models;

use PDO;
use Exception;

class IngestionModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * NOUVEAU : Nettoyage et normalisation du ticker "à la volée" (Le Vaccin)
     */
    private function cleanTickerData(string $originalTicker, string $companyName): array
    {
        $t = trim($originalTicker);

        // 1. Nettoyage des préfixes (NYSE:, etc.)
        $t = preg_replace('/^(NYSE|NASDAQ|ASX|AMEX|OTC|CBOE):\s*/i', '', $t);

        // 2. Multi-tickers (on garde le premier)
        if (preg_match('/^([A-Z0-9\-\.]+)(?:\s+|,|\/|AND)/i', $t, $matches)) {
            $t = $matches[1];
        }

        // 3. Classes d'actions
        if (strtoupper($t) === 'NA' && stripos($companyName, 'Nano Labs') !== false) {
            // Vrai Nano Labs, on ne touche à rien
        } else {
            // Remplacement du point par un tiret pour Yahoo (ex: BRK.B -> BRK-B)
            $t = preg_replace('/\.([A-Z0-9])$/i', '-$1', $t);
        }

        $t = strtoupper(trim($t));

        // 4. Détection Poubelles et Fonds
        $isGarbage = false;
        $reason = null;

        if (empty($t) || in_array($t, ['NONE', 'NULL', 'N/A', 'NON-ASSIGN', 'AB-LEND'])) {
            $isGarbage = true; $reason = 'Ticker invalide textuel';
        } elseif (is_numeric($t) || strlen($t) > 8) {
            $isGarbage = true; $reason = 'Numéro CIK ou code erroné';
        } elseif ($t === 'NA') {
            $isGarbage = true; $reason = 'Faux ticker NA';
        } elseif (preg_match('/-(PR[A-Z]?|P[A-Z]?|W|R|U)$/i', $t)) {
            $isGarbage = true; $reason = 'Actions préférentielles / Warrants';
        } elseif (strlen($t) === 5 && str_ends_with($t, 'X')) {
            $isGarbage = true; $reason = 'Fonds Commun (Mutual Fund)';
        } elseif (stripos($companyName, 'Fund') !== false || stripos($companyName, 'Trust') !== false) {
            $isGarbage = true; $reason = 'Fonds d\'investissement / Trust non standard';
        }

        if ($isGarbage) {
            return ['ticker' => 'NON-COTE', 'sector' => 'NON-COTE', 'industry' => $reason];
        }

        return ['ticker' => $t, 'sector' => null, 'industry' => null];
    }

    public function getLastProcessedId(): ?string
    {
        $stmt = $this->db->query("SELECT value FROM system_settings WHERE key_name = 'last_accession_number'");
        return $stmt->fetchColumn() ?: null;
    }

    public function setLastProcessedId(string $id): void
    {
        $stmt = $this->db->prepare("REPLACE INTO system_settings (key_name, value) VALUES ('last_accession_number', :id)");
        $stmt->execute(['id' => $id]);
    }

    /**
     * Traite et insère les données extraites d'un SEC Form 4.
     */
    public function processForm4(string $accessionNumber, array $issuerData, array $ownerData, array $transactionsData): int
    {
        try {
            $this->db->beginTransaction();

            // 1. Gérer l'entreprise
            $stmt = $this->db->prepare("SELECT id FROM companies WHERE cik = :cik");
            $stmt->execute(['cik' => $issuerData['cik']]);
            $companyId = $stmt->fetchColumn();

            if (!$companyId) {
                // APPLICATION DU VACCIN : On nettoie le ticker AVANT l'insertion
                $cleanData = $this->cleanTickerData($issuerData['ticker'], $issuerData['name']);

                $stmt = $this->db->prepare("INSERT INTO companies (cik, name, ticker, sector, industry) VALUES (:cik, :name, :ticker, :sector, :industry)");
                $stmt->execute([
                    'cik' => $issuerData['cik'],
                    'name' => $issuerData['name'],
                    'ticker' => $cleanData['ticker'],
                    'sector' => $cleanData['sector'],
                    'industry' => $cleanData['industry']
                ]);
                $companyId = $this->db->lastInsertId();
            }

            // 2. Gérer le dirigeant
            $stmt = $this->db->prepare("SELECT id FROM insiders WHERE cik = :cik");
            $stmt->execute(['cik' => $ownerData['cik']]);
            $insiderId = $stmt->fetchColumn();

            if (!$insiderId) {
                $stmt = $this->db->prepare("INSERT INTO insiders (cik, name) VALUES (:cik, :name)");
                $stmt->execute($ownerData);
                $insiderId = $this->db->lastInsertId();
            }

            // 3. Insérer les transactions brutes
            $insertedCount = 0;
            $stmtTx = $this->db->prepare("
                INSERT IGNORE INTO transactions 
                (accession_number, line_index, company_id, insider_id, transaction_date, transaction_code, shares, price_per_share, shares_owned_following, direct_or_indirect, is_10b51, officer_title, remarks, footnotes) 
                VALUES 
                (:acc_num, :line_idx, :comp_id, :ins_id, :tx_date, :tx_code, :shares, :price, :shares_following, :direct_or_indirect, :is_10b51, :title, :remarks, :footnotes)
            ");

            // Tableau pour garder en mémoire les jours modifiés
            $aggregateKeys = [];

            foreach ($transactionsData as $tx) {
                // Sécurité vitale : On ignore les transactions avec 0 action (évite de planter la BDD)
                if ($tx['shares'] <= 0) {
                    continue;
                }

                $tx['acc_num'] = $accessionNumber;
                $tx['comp_id'] = $companyId;
                $tx['ins_id']  = $insiderId;

                $stmtTx->execute($tx);
                if ($stmtTx->rowCount() > 0) {
                    $insertedCount++;
                    // Mémoriser la date et le type pour mettre à jour la table d'agrégation
                    $key = $tx['tx_date'] . '|' . $tx['tx_code'];
                    $aggregateKeys[$key] = true;
                }
            }

            // 4. Mettre à jour l'agrégation journalière (transactions_jour)
            if (!empty($aggregateKeys)) {
                $stmtAgg = $this->db->prepare("
                    INSERT INTO transactions_jour 
                    (transaction_date, company_id, insider_id, transaction_code, total_shares, avg_price_per_share, transaction_count, is_10b51, officer_title)
                    SELECT 
                        transaction_date, company_id, insider_id, transaction_code,
                        SUM(shares) as total_shares,
                        SUM(shares * price_per_share) / SUM(shares) as avg_price_per_share,
                        COUNT(*) as transaction_count,
                        MAX(is_10b51) as is_10b51, officer_title
                    FROM transactions
                    WHERE company_id = :comp_id 
                      AND insider_id = :ins_id 
                      AND transaction_date = :tx_date 
                      AND transaction_code = :tx_code
                    GROUP BY transaction_date, company_id, insider_id, transaction_code
                    ON DUPLICATE KEY UPDATE 
                        total_shares = VALUES(total_shares),
                        avg_price_per_share = VALUES(avg_price_per_share),
                        transaction_count = VALUES(transaction_count),
                        is_10b51 = VALUES(is_10b51),
                        officer_title = VALUES(officer_title)               
                ");

                foreach (array_keys($aggregateKeys) as $key) {
                    list($txDate, $txCode) = explode('|', $key);
                    $stmtAgg->execute([
                        'comp_id' => $companyId,
                        'ins_id'  => $insiderId,
                        'tx_date' => $txDate,
                        'tx_code' => $txCode
                    ]);
                }
            }

            $this->db->commit();
            return $insertedCount;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}