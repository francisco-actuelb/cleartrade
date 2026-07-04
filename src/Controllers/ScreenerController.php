<?php
// /var/www/ct.hsrv.fr/src/Controllers/ScreenerController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Slim\Views\Twig;
use App\Models\ScreenerModel;
use App\Models\DetailsModel; // Pour réutiliser le calcul RSI/MA200

class ScreenerController
{
    private Twig $view;
    private ScreenerModel $model;
    private DetailsModel $detailsModel;

    public function __construct(PDO $db, Twig $view)
    {
        $this->view = $view;
        $this->model = new ScreenerModel($db);
        $this->detailsModel = new DetailsModel($db);
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $queryParams = $request->getQueryParams();

        // Définition des filtres par défaut
        $filters = [
            'days' => $queryParams['days'] ?? 30,
            'type' => $queryParams['type'] ?? 'P', // Achat par défaut
            'role' => $queryParams['role'] ?? '',
            'min_value' => $queryParams['min_value'] ?? 50000, // 50k$ minimum par défaut
            'sector' => $queryParams['sector'] ?? ''
        ];

        // Récupération des opportunités
        $opportunities = $this->model->searchOpportunities($filters);

        // Enrichissement Hybride : Calcul de la MA200 et du RSI pour chaque résultat !
        foreach ($opportunities as &$opp) {
            $opp['rsi'] = $this->detailsModel->getRSI14($opp['ticker'], $opp['transaction_date']);
            $opp['ma200'] = $this->detailsModel->getMA200($opp['ticker'], $opp['transaction_date']);
        }

        $sectors = $this->model->getSectors();

        return $this->view->render($response, 'screener.html.twig', [
            'opportunities' => $opportunities,
            'sectors' => $sectors,
            'filters' => $filters
        ]);
    }
}