<?php
// /var/www/ct.hsrv.fr/src/Controllers/HomeController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Slim\Views\Twig;

class HomeController
{
    private PDO $db;
    private Twig $view;

    public function __construct(PDO $db, Twig $view)
    {
        $this->db = $db;
        $this->view = $view;
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        // 1. Récupération des paramètres de l'URL (Filtres et Pagination)
        $queryParams = $request->getQueryParams();

        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
        if ($page < 1) $page = 1;

        $limit = 50; // Nombre de lignes groupées par page
        $offset = ($page - 1) * $limit;

        $tickerFilter = $queryParams['ticker'] ?? '';
        $typeFilter = $queryParams['type'] ?? '';

        // 2. Construction dynamique de la requête SQL
        $whereClauses = [];
        $params = [];

        if ($tickerFilter !== '') {
            $whereClauses[] = "c.ticker LIKE :ticker";
            $params['ticker'] = '%' . $tickerFilter . '%';
        }

        if ($typeFilter !== '') {
            $whereClauses[] = "tj.transaction_code = :type"; // Utilisation de 'tj' pour transactions_jour
            $params['type'] = $typeFilter;
        }

        $whereSql = '';
        if (count($whereClauses) > 0) {
            $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
        }

        // 3. Calcul du nombre total de pages (pour la pagination)
        $countQuery = "
            SELECT COUNT(*) 
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            JOIN insiders i ON tj.insider_id = i.id
            $whereSql
        ";
        $stmtCount = $this->db->prepare($countQuery);
        $stmtCount->execute($params);
        $totalRecords = $stmtCount->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // 4. Récupération des transactions agrégées, filtrées et paginées
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
                i.name as insider_name
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            JOIN insiders i ON tj.insider_id = i.id
            $whereSql
            ORDER BY tj.transaction_date DESC, tj.id DESC
            LIMIT $limit OFFSET $offset
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll();

        // 5. Récupération du statut de la dernière ingestion
        $stmtStatus = $this->db->query("SELECT value FROM system_settings WHERE key_name = 'last_accession_number'");
        $lastIngestionId = $stmtStatus->fetchColumn();

        // 6. Envoi de toutes les données à la vue Twig
        return $this->view->render($response, 'home.html.twig', [
            'transactions' => $transactions,
            'lastIngestionId' => $lastIngestionId,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'filters' => [
                'ticker' => $tickerFilter,
                'type' => $typeFilter
            ]
        ]);
    }
}