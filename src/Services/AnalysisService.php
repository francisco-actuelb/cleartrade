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

    /**
     * NOUVELLE FONCTION : Analyse le profil complet d'un initié
     */
    public function analyzeInsiderProfile(int $insiderId, ?int $companyId = null): array
    {
        // 1. Bouclier Anti-Spam : Vérifier si on a déjà fait cette analyse aujourd'hui
        $sqlCheck = "SELECT id FROM insider_ai_profiles WHERE insider_id = :iid AND DATE(analysis_date) = CURDATE()";
        $paramsCheck = ['iid' => $insiderId];

        if ($companyId) {
            $sqlCheck .= " AND company_id = :cid";
            $paramsCheck['cid'] = $companyId;
        } else {
            $sqlCheck .= " AND company_id IS NULL";
        }

        $stmtCheck = $this->db->prepare($sqlCheck);
        $stmtCheck->execute($paramsCheck);

        if ($stmtCheck->fetchColumn()) {
            throw new Exception("Une analyse de profil a déjà été générée aujourd'hui pour cette configuration. Revenez demain !");
        }

        // 2. Extraction des Data Points

        // A. Infos de base de l'initié
        $stmtInsider = $this->db->prepare("SELECT name FROM insiders WHERE id = :id");
        $stmtInsider->execute(['id' => $insiderId]);
        $insiderName = $stmtInsider->fetchColumn();

        // B. Statistiques de réussite (Win Rates)
        $stmtStats = $this->db->prepare("SELECT * FROM insider_stats WHERE insider_id = :id");
        $stmtStats->execute(['id' => $insiderId]);
        $stats = $stmtStats->fetch() ?: null;

        // C. Ratio d'activité (Achats vs Ventes)
        $sqlRatio = "SELECT transaction_code, COUNT(*) as count FROM transactions_jour WHERE insider_id = :iid";
        if ($companyId) $sqlRatio .= " AND company_id = " . (int)$companyId;
        $sqlRatio .= " GROUP BY transaction_code";
        $stmtRatio = $this->db->prepare($sqlRatio);
        $stmtRatio->execute(['iid' => $insiderId]);
        $ratios = $stmtRatio->fetchAll(PDO::FETCH_KEY_PAIR);
        $buys = $ratios['P'] ?? 0;
        $sells = $ratios['S'] ?? 0;

        // D. Les 20 dernières transactions pour la dynamique récente
        $sqlActivity = "
            SELECT tj.transaction_date, tj.transaction_code, tj.total_shares, tj.avg_price_per_share, c.ticker
            FROM transactions_jour tj
            JOIN companies c ON tj.company_id = c.id
            WHERE tj.insider_id = :iid
        ";
        $paramsActivity = ['iid' => $insiderId];

        $targetTicker = "Toutes entreprises";
        if ($companyId) {
            $sqlActivity .= " AND tj.company_id = :cid";
            $paramsActivity['cid'] = $companyId;

            $stmtC = $this->db->prepare("SELECT ticker FROM companies WHERE id = :cid");
            $stmtC->execute(['cid' => $companyId]);
            $targetTicker = $stmtC->fetchColumn();
        }

        $sqlActivity .= " ORDER BY tj.transaction_date DESC LIMIT 20";
        $stmtActivity = $this->db->prepare($sqlActivity);
        $stmtActivity->execute($paramsActivity);
        $recentTrades = $stmtActivity->fetchAll();

        // E. NOUVEAU : Extraction de tous les postes occupés (CFO, Director, etc.)
        $sqlTitles = "SELECT DISTINCT officer_title FROM transactions WHERE insider_id = :iid AND officer_title IS NOT NULL";
        if ($companyId) {
            $sqlTitles .= " AND company_id = " . (int)$companyId;
        }
        $stmtTitles = $this->db->prepare($sqlTitles);
        $stmtTitles->execute(['iid' => $insiderId]);
        $titles = $stmtTitles->fetchAll(PDO::FETCH_COLUMN);

        // Nettoyage et formatage (ex: "Director, CFO")
        $cleanTitles = array_filter(array_map('trim', $titles));
        $rolesStr = empty($cleanTitles) ? "Non spécifié" : implode(', ', $cleanTitles);

        // F. NOUVEAU : Effet de meute (Cluster Buying)
        $clusterInfo = "";
        if ($companyId) {
            // On cherche si D'AUTRES dirigeants ont ACHETÉ des actions de cette entreprise dans les 30 derniers jours
            $stmtCluster = $this->db->prepare("
                SELECT COUNT(DISTINCT insider_id) 
                FROM transactions_jour 
                WHERE company_id = :cid 
                  AND insider_id != :iid 
                  AND transaction_code = 'P' 
                  AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ");
            $stmtCluster->execute(['cid' => $companyId, 'iid' => $insiderId]);
            $otherBuyersCount = (int)$stmtCluster->fetchColumn();

            if ($otherBuyersCount > 0) {
                $clusterInfo = "\n--- CONTEXTE D'ENTREPRISE (CLUSTER BUYING) ---\n- ALERTE : Dans les 30 derniers jours, {$otherBuyersCount} AUTRE(S) dirigeant(s) de cette même entreprise ont également acheté des actions ! C'est un signal fort de 'Cluster Buying' (effet de meute).\n";
            }
        }

        // 3. Construction du Super-Prompt
        $contextText = "Initié : {$insiderName}\n";
        $contextText .= "Poste(s) occupé(s) historiquement : {$rolesStr}\n"; // Injection des rôles
        $contextText .= "Cible de l'analyse : {$targetTicker}\n";

        $contextText .= "\n--- STATISTIQUES DE REUSSITE HISTORIQUES (WIN RATES) ---\n";
        if ($stats) {
            $contextText .= "- Taux de réussite de ses trades à 3 mois : " . ($stats['win_rate_3m'] ?? 'N/A') . "%\n";
            $contextText .= "- Taux de réussite de ses trades à 6 mois : " . ($stats['win_rate_6m'] ?? 'N/A') . "%\n";
            $contextText .= "- Rendement moyen généré à 6 mois : " . ($stats['avg_return_6m'] ?? 'N/A') . "%\n";
            $contextText .= "- Volume total de trades sur lequel se base cette statistique : " . max((int)$stats['total_trades_3m'], (int)$stats['total_trades_6m']) . " trades.\n";
        } else {
            $contextText .= "Pas assez d'historique de prix pour calculer une statistique de réussite.\n";
        }

        $contextText .= "\n--- RATIO D'ACTIVITÉ ---\n";
        $contextText .= "- Nombre d'achats sur le marché libre (P) : {$buys}\n";
        $contextText .= "- Nombre de ventes sur le marché libre (S) : {$sells}\n";

        $contextText .= "\n--- 20 DERNIÈRES TRANSACTIONS RÉCENTES ---\n";
        if (empty($recentTrades)) {
            $contextText .= "Aucune transaction récente trouvée.\n";
        } else {
            foreach ($recentTrades as $t) {
                $typeStr = $t['transaction_code'] === 'P' ? 'ACHAT' : 'VENTE';
                $contextText .= "- {$t['transaction_date']} | {$t['ticker']} | {$typeStr} | " . number_format($t['total_shares'], 0, ',', ' ') . " actions à $" . number_format($t['avg_price_per_share'], 2) . "\n";
            }
        }

        // Injection de l'effet de meute à la fin du contexte
        $contextText .= $clusterInfo;

        $systemPrompt = "Tu es un profiler financier expert en 'Insider Trading'. Ta mission est d'évaluer le comportement, la fiabilité et la stratégie d'un dirigeant en fonction de son historique boursier.
        Règles d'or :
        1. Un dirigeant vend souvent pour diversifier son patrimoine, payer des impôts ou acheter une maison. Une vente n'est pas forcément négative.
        2. En revanche, un dirigeant n'achète que s'il croit fermement en la hausse de son entreprise. Les achats sont des signaux forts.
        3. Prends en compte son 'Poste'. Un achat de CEO ou CFO (qui connaissent les finances) a beaucoup plus de poids qu'un 'Director' indépendant.
        4. Si d'autres dirigeants achètent en même temps (Cluster Buying), c'est un signal extrêmement positif qu'il faut souligner.
        5. Utilise son 'Taux de réussite' (Win Rate) pour juger s'il anticipe bien le marché. Un Win Rate > 60% est excellent.
        6. Si l'historique total est trop faible (moins de 3 transactions au total), tu DOIS retourner un signal NEUTRAL avec une confiance faible (<30%) en expliquant que l'échantillon est insuffisant.
        
        Réponds UNIQUEMENT au format JSON strict avec les clés suivantes :
        - 'signal_type' : Doit être exactement 'BULLISH', 'BEARISH' ou 'NEUTRAL'.
        - 'confidence_score' : Un entier entre 0 et 100.
        - 'rationale' : Un texte argumenté, professionnel et incisif en français (environ 4 à 6 phrases) expliquant ton verdict psychologique et stratégique.";

        $userPrompt = "Analyse le profil de ce dirigeant avec les données suivantes :\n\n" . $contextText;

        // 4. Appel à l'API (On force le modèle PRO car c'est du raisonnement global)
        $jsonResponse = $this->callDeepSeekApi($systemPrompt, $userPrompt, self::MODEL_PRO);

        // Sécurisation des clés (au cas où le LLM renvoie 'signal' au lieu de 'signal_type')
        $signalType = $jsonResponse['signal_type'] ?? $jsonResponse['signal'] ?? 'NEUTRAL';
        $confidence = (int)($jsonResponse['confidence_score'] ?? 0);
        $rationale = $jsonResponse['rationale'] ?? "Impossible de parser l'explication de l'IA.";

        if (!in_array($signalType, ['BULLISH', 'BEARISH', 'NEUTRAL'])) {
            $signalType = 'NEUTRAL';
        }

        // 5. Sauvegarde du profil IA en base de données
        $stmtInsert = $this->db->prepare("
            INSERT INTO insider_ai_profiles 
            (insider_id, company_id, signal_type, confidence_score, rationale, model_version) 
            VALUES (:iid, :cid, :stype, :score, :rationale, :model)
        ");

        $stmtInsert->execute([
            'iid' => $insiderId,
            'cid' => $companyId,
            'stype' => $signalType,
            'score' => $confidence,
            'rationale' => $rationale,
            'model' => self::MODEL_PRO
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