<?php
// /var/www/ct.hsrv.fr/src/Controllers/HomeController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Slim\Views\Twig;
use App\Models\HomeModel; // Import de notre nouveau modèle

class HomeController
{
    private Twig $view;
    private HomeModel $model;

    public function __construct(PDO $db, Twig $view)
    {
        $this->view = $view;
        // Instanciation du modèle avec la connexion BDD
        $this->model = new HomeModel($db);
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        // 1. Gestion des paramètres HTTP (Le rôle exact d'un contrôleur)
        $queryParams = $request->getQueryParams();

        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
        if ($page < 1) $page = 1;

        $limit = 50;
        $offset = ($page - 1) * $limit;

        // AJOUT DES DATES PAR DÉFAUT (-30 jours à aujourd'hui) DANS LE TABLEAU
        $filters = [
            'ticker' => $queryParams['ticker'] ?? '',
            'type' => $queryParams['type'] ?? '',
            'industry' => $queryParams['industry'] ?? '',
            'start_date' => $queryParams['start_date'] ?? date('Y-m-d', strtotime('-30 days')),
            'end_date' => $queryParams['end_date'] ?? date('Y-m-d')
        ];

        // 2. Récupération des données via le Modèle (La logique métier)
        $totalRecords = $this->model->getTotalTransactionsCount($filters);
        $totalPages = ceil($totalRecords / $limit);

        $transactions = $this->model->getTransactions($limit, $offset, $filters);
        $sectorsTree = $this->model->getSectorsAndIndustries();
        $lastIngestionId = $this->model->getLastIngestionId();

        // 3. Transmission des données à la Vue
        return $this->view->render($response, 'home.html.twig', [
            'transactions' => $transactions,
            'lastIngestionId' => $lastIngestionId,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'sectorsTree' => $sectorsTree,
            'filters' => $filters
        ]);
    }
}