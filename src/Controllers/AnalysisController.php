<?php
// /var/www/ct.hsrv.fr/src/Controllers/AnalysisController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\AnalysisService;
use PDO;

class AnalysisController
{
    private AnalysisService $analysisService;

    public function __construct(PDO $db)
    {
        // 1. Récupération des clés API depuis le fichier .env
        $finnhubKey = $_ENV['FINNHUB_API_KEY'] ?? '';
        $deepseekKey = $_ENV['DEEPSEEK_API_KEY'] ?? '';

        // 2. Instanciation du service de prix avec sa clé
        $priceService = new \App\Services\StockPriceService($finnhubKey);

        // 3. Instanciation du service d'analyse avec la BDD, le service de prix, et la clé DeepSeek
        $this->analysisService = new AnalysisService($db, $priceService, $deepseekKey);
    }

    public function analyze(Request $request, Response $response, array $args): Response
    {
        $parsedBody = $request->getParsedBody();

        $companyId = (int)($parsedBody['company_id'] ?? 0);
        $insiderId = (int)($parsedBody['insider_id'] ?? 0);
        $date = $parsedBody['date'] ?? '';
        $type = $parsedBody['type'] ?? '';

        if (!$companyId || !$insiderId || !$date || !$type) {
            return $response->withStatus(400); // Mauvaise requête
        }

        try {
            $this->analysisService->analyzeAggregated($companyId, $insiderId, $date, $type);

            // Redirection vers la page des détails pour voir le résultat (avec les mêmes paramètres)
            $url = "/details?date={$date}&company={$companyId}&insider={$insiderId}&type={$type}";
            return $response->withHeader('Location', $url)->withStatus(302);
        } catch (\Exception $e) {
            // En production, il faudrait flasher l'erreur en session.
            // Pour l'instant, on redirige avec un paramètre d'erreur.
            $url = "/details?date={$date}&company={$companyId}&insider={$insiderId}&type={$type}&error=" . urlencode($e->getMessage());
            return $response->withHeader('Location', $url)->withStatus(302);
        }
    }

    public function analyze_detail(Request $request, Response $response, array $args): Response
    {
        $transactionId = (int)$args['id'];

        try {
            $result = $this->analysisService->analyze($transactionId);

            // Redirection vers la page précédente avec succès
            return $response->withHeader('Location', '/')->withStatus(302);
        } catch (\Exception $e) {
            // Loguer l'erreur ici si nécessaire
            return $response->withStatus(500);
        }
    }
}