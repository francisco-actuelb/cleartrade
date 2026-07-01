<?php
// /var/www/ct.hsrv.fr/src/Controllers/DetailsController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Slim\Views\Twig;
use App\Models\DetailsModel; // Ajout de l'import du modèle

class DetailsController
{
    private PDO $db;
    private Twig $view;
    private DetailsModel $model; // Ajout de la propriété

    public function __construct(PDO $db, Twig $view)
    {
        $this->db = $db;
        $this->view = $view;
        $this->model = new DetailsModel($db); // Instanciation du modèle
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $queryParams = $request->getQueryParams();

        $date = $queryParams['date'] ?? null;
        $companyId = $queryParams['company'] ?? null;
        $insiderId = $queryParams['insider'] ?? null;
        $type = $queryParams['type'] ?? null;

        // Si un paramètre manque, on redirige vers l'accueil
        if (!$date || !$companyId || !$insiderId || !$type) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        // Utilisation du modèle au lieu de la requête SQL directe
        $rawTransactions = $this->model->getTransactionsDetails($date, $companyId, $insiderId, $type);

        // CORRECTION : Construction de l'URL exacte attendue par les serveurs de la SEC
        foreach ($rawTransactions as &$tx) {
            // La SEC exige le CIK sans les zéros initiaux pour le nom du dossier
            $cikSansZeros = ltrim($tx['company_cik'], '0');
            $accSansTirets = str_replace('-', '', $tx['accession_number']);

            $tx['sec_url'] = "https://www.sec.gov/Archives/edgar/data/{$cikSansZeros}/{$accSansTirets}/{$tx['accession_number']}.txt";
        }

        // Récupération du contexte à partir de la première ligne
        $context = !empty($rawTransactions) ? $rawTransactions[0] : null;

        // Si aucune transaction n'est trouvée, retour à l'accueil
        if (!$context) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        // NOUVEAU : On va chercher le signal IA et les erreurs éventuelles
        $aiSignal = $this->model->getAiSignal($companyId, $insiderId, $date);
        $error = $queryParams['error'] ?? null;

        return $this->view->render($response, 'details.html.twig', [
            'transactions' => $rawTransactions,
            'context' => $context,
            'type' => $type,
            'date' => $date,
            'ai_signal' => $aiSignal, // Ajout du signal
            'error' => $error         // Ajout de l'erreur pour affichage
        ]);
    }
}