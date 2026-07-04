<?php
// /var/www/ct.hsrv.fr/src/Models/ScreenerModel.php

namespace App\Models;

use PDO;

class ScreenerModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Recherche les meilleures opportunités selon des critères stricts
     */
    public function searchOpportunities(array $filters): array
    {
        $params = [];
        $whereClauses = [];

        // 1. Filtre temporel (Défaut: 30 derniers jours)
        $days = (int)($filters['days'] ?? 30);
        $whereClauses[] = "tj.transaction_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)";
        $params['days'] = $days;

        // 2. Type de transaction (Défaut: P - Achats)
        $type = $filters['type'] ?? 'P';
        if ($type !== 'ALL') {
            $whereClauses[] = "tj.transaction_code = :type";
            $params['type'] = $type;
        }

        // 3. Secteur
        if (!empty($filters['sector'])) {
            $whereClauses[] = "c.sector = :sector";
            $params['sector'] = $filters['sector'];
        }

        $whereSql = implode(' AND ', $whereClauses);

        // REQUÊTE PRINCIPALE
        $query = "
            SELECT 
                tj.transaction_date, 
                tj.transaction_code, 
                tj.total_shares, 
                tj.avg_price_per_share, 
                (tj.total_shares * tj.avg_price_per_share) as total_value,
                tj.company_id,
                tj.insider_id,
                c.name as company_name, 
                c.ticker,
                c.sector,
                i.name as insider_name,
                (SELECT officer_title FROM transactions t WHERE t.company_id = tj.company_id AND t.insider_id = tj.insider_id ORDER BY t.transaction_date DESC LIMIT 1) as officer_title
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            JOIN insiders i ON tj.insider_id = i.id
            WHERE $whereSql
        ";

        // 4. Filtrage dynamique par Rôle (CFO, CEO, etc.) via HAVING car on utilise une sous-requête
        $havingClauses = [];

        $role = $filters['role'] ?? '';
        if ($role === 'CEO') {
            $havingClauses[] = "officer_title LIKE '%CEO%' OR officer_title LIKE '%Chief Executive%'";
        } elseif ($role === 'CFO') {
            $havingClauses[] = "officer_title LIKE '%CFO%' OR officer_title LIKE '%Chief Financial%'";
        } elseif ($role === 'DIRECTOR') {
            $havingClauses[] = "officer_title LIKE '%Director%'";
        }

        // 5. Filtre Valeur Minimum ($)
        $minValue = (int)($filters['min_value'] ?? 0);
        if ($minValue > 0) {
            $havingClauses[] = "total_value >= $minValue"; // Sécurisé car casté en int
        }

        if (!empty($havingClauses)) {
            $query .= " HAVING " . implode(' AND ', $havingClauses);
        }

        // 6. Tri : On veut voir les plus grosses transactions en premier
        $query .= " ORDER BY total_value DESC LIMIT 50";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Liste des secteurs uniques pour le menu déroulant
     */
    public function getSectors(): array
    {
        $stmt = $this->db->query("
            SELECT DISTINCT sector 
            FROM companies 
            WHERE sector IS NOT NULL AND sector != 'NON-COTE' AND sector != 'INCONNU' 
            ORDER BY sector ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}