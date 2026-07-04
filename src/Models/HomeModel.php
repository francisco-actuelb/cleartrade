<?php
// /var/www/ct.hsrv.fr/src/Models/HomeModel.php

namespace App\Models;

use PDO;

class HomeModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function buildWhereClause(array $filters): array
    {
        $whereClauses = [];
        $params = [];

        if (!empty($filters['ticker'])) {
            $whereClauses[] = "c.ticker LIKE :ticker";
            $params['ticker'] = '%' . $filters['ticker'] . '%';
        }

        if (!empty($filters['type'])) {
            $whereClauses[] = "tj.transaction_code = :type";
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['industry'])) {
            $whereClauses[] = "c.industry = :industry";
            $params['industry'] = $filters['industry'];
        }

        // LIAISON DES NOUVELLES DATES À LA REQUÊTE SQL
        if (!empty($filters['start_date'])) {
            $whereClauses[] = "tj.transaction_date >= :start_date";
            $params['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $whereClauses[] = "tj.transaction_date <= :end_date";
            $params['end_date'] = $filters['end_date'];
        }

        $whereSql = '';
        if (count($whereClauses) > 0) {
            $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
        }

        return [$whereSql, $params];
    }

    public function getTotalTransactionsCount(array $filters): int
    {
        [$whereSql, $params] = $this->buildWhereClause($filters);

        // 1. FORCAGE EXTRÊME DE L'ENCODAGE AVANT LA REQUÊTE
        $this->db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $countQuery = "
            SELECT COUNT(*) 
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            JOIN insiders i ON tj.insider_id = i.id
            $whereSql
        ";

        $stmt = $this->db->prepare($countQuery);

        // 2. DÉTECTION D'ERREUR SILENCIEUSE
        if (!$stmt->execute($params)) {
            $error = $stmt->errorInfo();
            die("<div style='background:#fee2e2; color:#991b1b; padding:20px; font-family:sans-serif; border-radius:8px; margin:20px;'>
                    <h2>🚨 ERREUR SQL CACHÉE (Count)</h2>
                    <p>MySQL a refusé la requête mais PHP la cachait.</p>
                    <strong>Message MySQL :</strong> " . htmlspecialchars($error[2]) . "
                 </div>");
        }

        return (int)$stmt->fetchColumn();
    }

    public function getTransactions(int $limit, int $offset, array $filters): array
    {
        [$whereSql, $params] = $this->buildWhereClause($filters);

        // 1. FORCAGE EXTRÊME DE L'ENCODAGE AVANT LA REQUÊTE
        $this->db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Note : J'ai retiré le mot "COLLATE" qui traînait dans le LEFT JOIN et qui pouvait
        // causer une erreur de syntaxe si la base de données ne l'aimait pas.
        $query = "
            SELECT 
                tj.transaction_date, 
                tj.transaction_code, 
                tj.total_shares, 
                tj.avg_price_per_share, 
                tj.transaction_count,
                tj.company_id,
                tj.insider_id,
                c.name as company_name, 
                c.ticker,
                i.name as insider_name,
                (SELECT officer_title FROM transactions t WHERE t.company_id = tj.company_id AND t.insider_id = tj.insider_id ORDER BY t.transaction_date DESC LIMIT 1) as officer_title,
                sp.close_price as market_price
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            JOIN insiders i ON tj.insider_id = i.id
            LEFT JOIN stock_prices sp ON c.ticker = sp.ticker AND tj.transaction_date = sp.price_date
            $whereSql
            ORDER BY tj.transaction_date DESC, tj.id DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $this->db->prepare($query);

        // 2. DÉTECTION D'ERREUR SILENCIEUSE
        if (!$stmt->execute($params)) {
            $error = $stmt->errorInfo();
            die("<div style='background:#fee2e2; color:#991b1b; padding:20px; font-family:sans-serif; border-radius:8px; margin:20px;'>
                    <h2>🚨 ERREUR SQL CACHÉE (Select)</h2>
                    <p>MySQL a refusé la requête principale.</p>
                    <strong>Message MySQL :</strong> " . htmlspecialchars($error[2]) . "
                 </div>");
        }

        return $stmt->fetchAll();
    }

    public function getSectorsAndIndustries(): array
    {
        $stmtIndustries = $this->db->query("
            SELECT DISTINCT c.sector, c.industry 
            FROM companies c
            JOIN transactions_jour tj ON c.id = tj.company_id
            WHERE c.sector IS NOT NULL AND c.industry IS NOT NULL
              AND c.sector != 'NON-COTE' AND c.sector != 'INCONNU'
            ORDER BY c.sector ASC, c.industry ASC
        ");

        $rawIndustries = $stmtIndustries->fetchAll(PDO::FETCH_ASSOC);

        $sectorsTree = [];
        foreach ($rawIndustries as $row) {
            $sectorsTree[$row['sector']][] = $row['industry'];
        }

        return $sectorsTree;
    }

    public function getLastIngestionId(): ?string
    {
        $stmtStatus = $this->db->query("SELECT value FROM system_settings WHERE key_name = 'last_accession_number'");
        $value = $stmtStatus->fetchColumn();
        return $value ?: null;
    }
}