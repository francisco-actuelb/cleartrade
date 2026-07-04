<?php
// /var/www/ct.hsrv.fr/src/Models/InsiderModel.php

namespace App\Models;

use PDO;

class InsiderModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function searchInsiders(string $query, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name 
            FROM insiders 
            WHERE name LIKE :query 
            ORDER BY name ASC 
            LIMIT :limit
        ");
        // On bind de manière sécurisée (attention LIMIT doit être un entier strict pour PDO)
        $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getInsider(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM insiders WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function getGlobalStats(int $insiderId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM insider_stats WHERE insider_id = :id");
        $stmt->execute(['id' => $insiderId]);
        return $stmt->fetch() ?: null;
    }

    public function getActivityByTicker(int $insiderId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                c.id as company_id,
                c.ticker, 
                c.name as company_name,
                COUNT(tj.id) as total_days_traded,
                SUM(tj.total_shares) as total_volume
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            WHERE tj.insider_id = :id
            GROUP BY c.id, c.ticker, c.name
            ORDER BY total_days_traded DESC
        ");
        $stmt->execute(['id' => $insiderId]);
        return $stmt->fetchAll();
    }

    public function getAiProfiles(int $insiderId, ?int $companyId = null): array
    {
        $sql = "SELECT p.*, c.ticker 
                FROM insider_ai_profiles p
                LEFT JOIN companies c ON p.company_id = c.id
                WHERE p.insider_id = :id";

        $params = ['id' => $insiderId];

        if ($companyId !== null) {
            $sql .= " AND p.company_id = :cid";
            $params['cid'] = $companyId;
        }

        $sql .= " ORDER BY p.analysis_date DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}