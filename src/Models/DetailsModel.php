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
                t.id,
                t.company_id,
                t.insider_id,
                t.transaction_date,
                t.shares,
                t.price_per_share,
                t.accession_number,
                t.line_index,
                c.name as company_name, 
                c.ticker,
                c.cik as company_cik,
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
}