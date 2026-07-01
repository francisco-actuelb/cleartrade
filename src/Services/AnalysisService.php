<?php
// /var/www/ct.hsrv.fr/src/Services/AnalysisService.php

namespace App\Services;

use PDO;
use Exception;

class AnalysisService
{
    private PDO $db;
    private StockPriceService $priceService;
    private string $apiKey;

    // Noms des modèles sur l'API DeepSeek
    private const MODEL_FLASH = 'deepseek-chat';
    private const MODEL_PRO = 'deepseek-reasoner';

    public function __construct(PDO $db, StockPriceService $priceService, string $apiKey)
    {
        $this->db = $db;
        $this->priceService = $priceService;
        $this->apiKey = $apiKey;
    }

    public function analyze(int $transactionId): array
    {
        // 1. Récupérer les détails de la transaction
        $stmt = $this->db->prepare("
            SELECT t.*, c.ticker, c.name as company_name, i.name as insider_name 
            FROM transactions t
            JOIN companies c ON t.company_id = c.id
            JOIN insiders i ON t.insider_id = i.id
            WHERE t.id = :id
        ");
        $stmt->execute(['id' => $transactionId]);
        $tx = $stmt->fetch();

        if (!$tx) {
            throw new Exception("Transaction introuvable.");
        }

        $totalValue = $tx['shares'] * $tx['price_per_share'];
        $transactionType = $tx['transaction_code'] === 'P' ? 'ACHAT' : 'VENTE';

        // Formatage du contexte textuel (Remarks / Footnotes)
        $contextuel = "";
        if (!empty($tx['remarks'])) {
            $contextuel .= "\n- Remarques de l'initié : " . $tx['remarks'];
        }
        if (!empty($tx['footnotes'])) {
            $contextuel .= "\n- Notes de bas de page : " . $tx['footnotes'];
        }

        // 2. Vérifier si un signal existe déjà pour ce jour / cet initié pour éviter les doublons d'API
        $stmtCheck = $this->db->prepare("
            SELECT id FROM ai_signals 
            WHERE company_id = :cid AND insider_id = :iid AND signal_date = :sdate
        ");
        $stmtCheck->execute([
            'cid' => $tx['company_id'],
            'iid' => $tx['insider_id'],
            'sdate' => $tx['transaction_date']
        ]);

        if ($stmtCheck->fetchColumn()) {
            throw new Exception("Une analyse IA a déjà été générée pour cet initié à cette date.");
        }

        // 3. Récupérer le contexte de marché (Cours actuel)
        // Note: S'il n'y a pas de donnée API dispo, on utilise le prix de la transaction comme référence
        $marketData = $this->priceService->getQuote($tx['ticker']);
        $currentPrice = $marketData['c'] ?? $tx['price_per_share'];
        $priceDiffPercent = (($currentPrice - $tx['price_per_share']) / $tx['price_per_share']) * 100;

        // 4. Routage Intelligent (Flash vs Pro)
        // Pour l'instant, on bascule sur "Pro" si la transaction dépasse 1 000 000 $
        $selectedModel = ($totalValue >= 1000000) ? self::MODEL_PRO : self::MODEL_FLASH;

        // 5. Préparation du Prompt
        $systemPrompt = "Tu es un analyste quantitatif expert. Ta mission est d'évaluer une transaction d'initié (insider trading) déclarée à la SEC. 
        Tu dois émettre un signal ('BULLISH', 'BEARISH' ou 'NEUTRAL'), un score de confiance (0 à 100) et une justification courte (rationale) en français.
        Réponds UNIQUEMENT au format JSON strict avec les clés: 'signal', 'confidence_score', 'rationale'.";

        $userPrompt = "
        Analyse la transaction suivante :
        - Entreprise : {$tx['company_name']} ({$tx['ticker']})
        - Initié : {$tx['insider_name']} (Titre : {$tx['officer_title']})
        - Action : {$transactionType} de " . number_format($tx['shares'], 0) . " actions
        - Valeur Totale : $" . number_format($totalValue, 2) . "
        - Prix unitaire de transaction : $" . number_format($tx['price_per_share'], 2) . "
        - Prix actuel sur le marché : $" . number_format($currentPrice, 2) . " (" . round($priceDiffPercent, 2) . "% de diff){$contextuel}
        
        Génère ton analyse JSON en tenant compte des remarques si elles sont présentes.";

        // 6. Appel à l'API DeepSeek
        $jsonResponse = $this->callDeepSeekApi($systemPrompt, $userPrompt, $selectedModel);

        if (!isset($jsonResponse['signal']) || !isset($jsonResponse['confidence_score'])) {
            throw new Exception("L'IA a retourné un format invalide.");
        }

        // 7. Sauvegarde du signal en Base de Données
        $metadata = json_encode([
            'total_value_usd' => $totalValue,
            'current_market_price' => $currentPrice,
            'price_diff_percent' => round($priceDiffPercent, 2)
        ]);

        $stmtInsert = $this->db->prepare("
            INSERT INTO ai_signals 
            (company_id, insider_id, signal_date, signal_type, confidence_score, rationale, analysis_metadata, model_version) 
            VALUES (:cid, :iid, :sdate, :stype, :score, :rationale, :meta, :model)
        ");

        $stmtInsert->execute([
            'cid' => $tx['company_id'],
            'iid' => $tx['insider_id'],
            'sdate' => $tx['transaction_date'],
            'stype' => $jsonResponse['signal'],
            'score' => (int)$jsonResponse['confidence_score'],
            'rationale' => $jsonResponse['rationale'],
            'meta' => $metadata,
            'model' => $selectedModel
        ]);

        return $jsonResponse;
    }

    public function analyzeAggregated(int $companyId, int $insiderId, string $date, string $type): array
    {
        // 1. Vérifier si un signal existe déjà pour éviter de payer l'API en double
        $stmtCheck = $this->db->prepare("
            SELECT id FROM ai_signals 
            WHERE company_id = :cid AND insider_id = :iid AND signal_date = :sdate AND signal_type = :stype
        ");
        $stmtCheck->execute([
            'cid' => $companyId,
            'iid' => $insiderId,
            'sdate' => $date,
            'stype' => $type === 'P' ? 'BULLISH' : 'BEARISH' // Approximation pour la vérification
        ]);

        if ($stmtCheck->fetchColumn()) {
            throw new Exception("Une analyse IA a déjà été générée pour cet événement.");
        }

        // 2. Récupérer les données agrégées (Le Total)
        $stmtAgg = $this->db->prepare("
            SELECT tj.*, c.ticker, c.name as company_name, i.name as insider_name 
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            JOIN insiders i ON tj.insider_id = i.id
            WHERE tj.company_id = :cid AND tj.insider_id = :iid AND tj.transaction_date = :sdate AND tj.transaction_code = :type
        ");
        $stmtAgg->execute(['cid' => $companyId, 'iid' => $insiderId, 'sdate' => $date, 'type' => $type]);
        $aggData = $stmtAgg->fetch();

        if (!$aggData) {
            throw new Exception("Données agrégées introuvables.");
        }

        $totalValue = $aggData['total_shares'] * $aggData['avg_price_per_share'];
        $transactionTypeStr = $type === 'P' ? 'ACHAT' : 'VENTE';

        // 3. Récupérer et concaténer TOUTES les remarques et notes de bas de page des lignes individuelles
        $stmtLines = $this->db->prepare("
            SELECT officer_title, remarks, footnotes 
            FROM transactions 
            WHERE company_id = :cid AND insider_id = :iid AND transaction_date = :sdate AND transaction_code = :type
        ");
        $stmtLines->execute(['cid' => $companyId, 'iid' => $insiderId, 'sdate' => $date, 'type' => $type]);
        $lines = $stmtLines->fetchAll();

        $allRemarks = [];
        $allFootnotes = [];
        $officerTitle = 'Dirigeant';

        foreach ($lines as $line) {
            if (!empty($line['officer_title'])) $officerTitle = $line['officer_title'];
            if (!empty($line['remarks'])) $allRemarks[] = trim($line['remarks']);
            if (!empty($line['footnotes'])) $allFootnotes[] = trim($line['footnotes']);
        }

        // Déduplication (pour éviter d'envoyer 9 fois la même note de bas de page à l'IA)
        $uniqueRemarks = array_unique($allRemarks);
        $uniqueFootnotes = array_unique($allFootnotes);

        $contextuel = "";
        if (!empty($uniqueRemarks)) {
            $contextuel .= "\n- Remarques de l'initié : " . implode(" | ", $uniqueRemarks);
        }
        if (!empty($uniqueFootnotes)) {
            $contextuel .= "\n- Notes de bas de page : " . implode(" | ", $uniqueFootnotes);
        }

        // 4. Récupérer le contexte de marché (Cours actuel via l'API)
        $marketData = $this->priceService->getQuote($aggData['ticker']);
        $currentPrice = $marketData['current_price'] ?? $aggData['avg_price_per_share'];

        // Sécurité : éviter division par zéro
        $priceDiffPercent = 0;
        if ($aggData['avg_price_per_share'] > 0) {
            $priceDiffPercent = (($currentPrice - $aggData['avg_price_per_share']) / $aggData['avg_price_per_share']) * 100;
        }

        // 5. Routage Intelligent (Flash vs Pro)
        // Bascule sur "Pro" si l'opération > 1 000 000 $ OU s'il y a du texte juridique complexe à lire
        $selectedModel = ($totalValue >= 1000000 || !empty($contextuel)) ? self::MODEL_PRO : self::MODEL_FLASH;

        // 6. Préparation du Prompt
        $systemPrompt = "Tu es un analyste quantitatif expert. Ta mission est d'évaluer un bloc de transactions d'initié (insider trading) déclaré à la SEC. 
        Tu dois émettre un signal ('BULLISH', 'BEARISH' ou 'NEUTRAL'), un score de confiance (0 à 100) et une justification courte (rationale) en français.
        Réponds UNIQUEMENT au format JSON strict avec les clés: 'signal', 'confidence_score', 'rationale'.";

        $userPrompt = "
        Analyse l'opération globale suivante (agrégée sur la journée) :
        - Entreprise : {$aggData['company_name']} ({$aggData['ticker']})
        - Initié : {$aggData['insider_name']} (Titre : {$officerTitle})
        - Action : {$transactionTypeStr} d'un volume total de " . number_format($aggData['total_shares'], 0) . " actions
        - Valeur Totale de l'opération : $" . number_format($totalValue, 2) . "
        - Prix unitaire moyen d'exécution : $" . number_format($aggData['avg_price_per_share'], 2) . "
        - Prix actuel sur le marché : $" . number_format($currentPrice, 2) . " (" . round($priceDiffPercent, 2) . "% d'écart par rapport au prix d'exécution){$contextuel}
        
        Génère ton analyse JSON en tenant impérativement compte des remarques et notes (Footnotes) si elles sont présentes.";

        // 7. Appel à l'API DeepSeek
        $jsonResponse = $this->callDeepSeekApi($systemPrompt, $userPrompt, $selectedModel);

        if (!isset($jsonResponse['signal']) || !isset($jsonResponse['confidence_score'])) {
            throw new Exception("L'IA a retourné un format invalide.");
        }

        // 8. Sauvegarde du signal en Base de Données
        $metadata = json_encode([
            'total_value_usd' => $totalValue,
            'current_market_price' => $currentPrice,
            'price_diff_percent' => round($priceDiffPercent, 2),
            'transactions_count' => $aggData['transaction_count'] // On note que ce signal vient d'un bloc de X lignes
        ]);

        $stmtInsert = $this->db->prepare("
            INSERT INTO ai_signals 
            (company_id, insider_id, signal_date, signal_type, confidence_score, rationale, analysis_metadata, model_version) 
            VALUES (:cid, :iid, :sdate, :stype, :score, :rationale, :meta, :model)
        ");

        $stmtInsert->execute([
            'cid' => $companyId,
            'iid' => $insiderId,
            'sdate' => $date,
            'stype' => $jsonResponse['signal'],
            'score' => (int)$jsonResponse['confidence_score'],
            'rationale' => $jsonResponse['rationale'],
            'meta' => $metadata,
            'model' => $selectedModel
        ]);

        return $jsonResponse;
    }

    private function callDeepSeekApi(string $systemPrompt, string $userPrompt, string $model): array
    {
        $url = 'https://api.deepseek.com/chat/completions';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            // On force le format JSON pour s'assurer que le PHP puisse le lire
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2 // Température basse pour une analyse financière stricte
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Erreur de l'API DeepSeek (HTTP $httpCode) : " . $response);
        }

        $responseData = json_decode($response, true);
        $aiContent = $responseData['choices'][0]['message']['content'] ?? '{}';

        return json_decode($aiContent, true) ?? [];
    }
}