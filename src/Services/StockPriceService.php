<?php
// /var/www/ct.hsrv.fr/src/Services/StockPriceService.php

namespace App\Services;

use Exception;

/**
 * Service pour récupérer les données de marché (prix actuel, historique).
 */
class StockPriceService
{
    private string $apiKey;
    private string $baseUrl = 'https://finnhub.io/api/v1'; // Exemple avec Finnhub (gratuit/limité)

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function getQuote(string $ticker): array
    {
        // 1. Appel API pour le prix temps réel
        $url = "{$this->baseUrl}/quote?symbol={$ticker}&token={$this->apiKey}";
        $data = $this->makeRequest($url);

        if (!isset($data['c'])) {
            throw new Exception("Impossible de récupérer le cours pour {$ticker}");
        }

        return [
            'current_price' => (float)$data['c'],
            'previous_close' => (float)$data['pc'],
            'high_day' => (float)$data['h'],
            'low_day' => (float)$data['l'],
        ];
    }

    public function getMovingAverage(string $ticker, int $days = 200): float
    {
        // 2. Appel API pour récupérer l'historique et calculer une moyenne simple
        // Note: Dans une vraie appli, utilisez un endpoint /indicator ou /stock/candle
        $to = time();
        $from = $to - ($days * 86400 * 1.5); // On prend un peu de marge
        $url = "{$this->baseUrl}/stock/candle?symbol={$ticker}&resolution=D&from={$from}&to={$to}&token={$this->apiKey}";

        $data = $this->makeRequest($url);

        if (!isset($data['c']) || count($data['c']) < $days) {
            return 0.0;
        }

        // Calcul simple de la moyenne des N derniers jours
        $closes = array_slice($data['c'], -$days);
        return array_sum($closes) / count($closes);
    }

    /**
     * Récupère le prix de clôture à une date donnée dans le passé.
     */
    public function getHistoricalClose(string $ticker, string $dateString): ?float
    {
        $targetTime = strtotime($dateString);

        // On demande une fenêtre de 5 jours à partir de la date cible
        // pour être sûr de tomber sur un jour ouvré (ignorer les week-ends/jours fériés)
        $from = $targetTime;
        $to = $targetTime + (5 * 86400);

        $url = "{$this->baseUrl}/stock/candle?symbol={$ticker}&resolution=D&from={$from}&to={$to}&token={$this->apiKey}";

        try {
            $data = $this->makeRequest($url);

            // Si on a des données, on prend le cours de clôture du premier jour de la fenêtre
            if (isset($data['c']) && count($data['c']) > 0) {
                return (float)$data['c'][0];
            }
        } catch (Exception $e) {
            // Ignorer silencieusement pour ne pas crasher la boucle globale
            return null;
        }

        return null;
    }

    private function makeRequest(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erreur de connexion API : " . $error);
        }

        $result = json_decode($response, true);
        if (!$result) {
            throw new Exception("Réponse API invalide");
        }

        return $result;
    }
}