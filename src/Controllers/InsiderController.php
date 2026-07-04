<?php
// /var/www/ct.hsrv.fr/src/Controllers/InsiderController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Slim\Views\Twig;
use App\Models\InsiderModel;

class InsiderController
{
    private PDO $db;
    private Twig $view;
    private InsiderModel $model;

    public function __construct(PDO $db, Twig $view)
    {
        $this->db = $db;
        $this->view = $view;
        $this->model = new InsiderModel($db);
    }

    // Endpoint API pour l'autocomplétion AJAX
    public function searchApi(Request $request, Response $response, array $args): Response
    {
        $query = $request->getQueryParams()['q'] ?? '';

        if (strlen($query) < 2) {
            $response->getBody()->write(json_encode([]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $results = $this->model->searchInsiders($query);

        $response->getBody()->write(json_encode($results));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Page de profil
    public function profile(Request $request, Response $response, array $args): Response
    {
        $insiderId = (int)$args['id'];
        $insider = $this->model->getInsider($insiderId);

        if (!$insider) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $stats = $this->model->getGlobalStats($insiderId);
        $activityByTicker = $this->model->getActivityByTicker($insiderId);
        $aiProfiles = $this->model->getAiProfiles($insiderId);

        // On récupère l'erreur potentielle dans l'URL (si l'anti-spam ou DeepSeek bloque)
        $error = $request->getQueryParams()['error'] ?? null;

        return $this->view->render($response, 'insider_profile.html.twig', [
            'insider' => $insider,
            'stats' => $stats,
            'tickers' => $activityByTicker,
            'profiles' => $aiProfiles,
            'latest_profile' => $aiProfiles[0] ?? null,
            'error' => $error // On passe l'erreur au template Twig
        ]);
    }

    // Action pour déclencher l'IA (Avec logs de débogage)
    public function analyzeProfile(Request $request, Response $response, array $args): Response
    {
        $insiderId = (int)$args['id'];
        $parsedBody = $request->getParsedBody();
        $companyId = !empty($parsedBody['company_id']) ? (int)$parsedBody['company_id'] : null;

        // TRACES AJOUTÉES POUR LE DÉBOGAGE (visibles dans les logs Nginx/PHP)
        error_log("=== DÉBUT ANALYSE INITIÉ ===");
        error_log("Insider ID: $insiderId, Company ID: " . ($companyId ?? 'Global'));

        try {
            // 1. Instanciation des services
            $finnhubKey = $_ENV['FINNHUB_API_KEY'] ?? '';
            $deepseekKey = $_ENV['DEEPSEEK_API_KEY'] ?? '';

            if (empty($deepseekKey)) {
                throw new \Exception("Clé API DeepSeek manquante dans le fichier .env !");
            }

            error_log("Clés API ok. Instanciation des services...");
            $priceService = new \App\Services\StockPriceService($finnhubKey);
            $analysisService = new \App\Services\AnalysisService($this->db, $priceService, $deepseekKey);

            // 2. Appel de notre moteur de profilage
            error_log("Appel à analysisService->analyzeInsiderProfile()...");
            $analysisService->analyzeInsiderProfile($insiderId, $companyId);

            // 3. Succès ! On recharge la page pour afficher le bel encart violet
            error_log("Analyse réussie. Redirection vers la page de profil.");
            return $response->withHeader('Location', '/insider/' . $insiderId)->withStatus(302);

        } catch (\Exception $e) {
            // 4. Échec. On trace l'erreur dans les logs et on l'envoie à l'écran
            error_log("ERREUR LORS DE L'ANALYSE : " . $e->getMessage());

            $errorMsg = urlencode($e->getMessage());
            return $response->withHeader('Location', '/insider/' . $insiderId . '?error=' . $errorMsg)->withStatus(302);
        }
    }
}