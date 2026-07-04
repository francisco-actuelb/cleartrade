<?php
// /var/www/ct.hsrv.fr/src/Models/DetailsModel.php

namespace App\Models;

use PDO;

class DetailsModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Récupère toutes les lignes de transaction pour une opération globale.
     */
    public function getTransactionsDetails(string $date, int $companyId, int $insiderId, string $type): array
    {
        $query = "
            SELECT 
                t.id, t.company_id, t.insider_id, t.transaction_date,
                t.shares, t.price_per_share, t.accession_number, t.line_index,
                c.name as company_name, c.ticker, c.cik as company_cik,
                i.name as insider_name
            FROM transactions t
            JOIN companies c ON t.company_id = c.id
            JOIN insiders i ON t.insider_id = i.id
            WHERE t.transaction_date = :date
              AND t.company_id = :company
              AND t.insider_id = :insider
              AND t.transaction_code = :type
            ORDER BY t.price_per_share DESC, t.line_index ASC
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            'date' => $date,
            'company' => $companyId,
            'insider' => $insiderId,
            'type' => $type
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Récupère le signal IA s'il existe pour cette opération
     */
    public function getAiSignal(int $companyId, int $insiderId, string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM ai_signals 
            WHERE company_id = :company AND insider_id = :insider AND signal_date = :date
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([
            'company' => $companyId,
            'insider' => $insiderId,
            'date' => $date
        ]);

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Calcule la Moyenne Mobile 200 jours (MA200)
     */
    public function getMA200(string $ticker, string $targetDate): ?float
    {
        $stmt = $this->db->prepare("
            SELECT AVG(close_price) as ma200
            FROM (
                SELECT close_price 
                FROM stock_prices 
                WHERE ticker = :ticker AND price_date <= :target_date 
                ORDER BY price_date DESC 
                LIMIT 200
            ) as recent_prices
        ");
        $stmt->execute(['ticker' => $ticker, 'target_date' => $targetDate]);

        $val = $stmt->fetchColumn();
        return $val ? (float)$val : null;
    }

    /**
     * Calcule le RSI 14 jours (Manquant pour le Screener !)
     */
    public function getRSI14(string $ticker, string $targetDate): ?float
    {
        $stmt = $this->db->prepare("
            SELECT close_price 
            FROM stock_prices 
            WHERE ticker = :ticker AND price_date <= :target_date 
            ORDER BY price_date DESC 
            LIMIT 15
        ");
        $stmt->execute(['ticker' => $ticker, 'target_date' => $targetDate]);
        $closes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($closes) < 15) return null;

        $closes = array_reverse($closes);
        $gains = 0; $losses = 0;

        for ($i = 1; $i < 15; $i++) {
            $change = $closes[$i] - $closes[$i-1];
            if ($change > 0) $gains += $change;
            else $losses += abs($change);
        }

        $avgLoss = $losses / 14;
        if ($avgLoss == 0) return 100;

        $rs = ($gains / 14) / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }
}