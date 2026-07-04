<?php
// /var/www/ct.hsrv.fr/src/Controllers/CompanyController.php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Slim\Views\Twig;

class CompanyController
{
    private PDO $db;
    private Twig $view;

    public function __construct(PDO $db, Twig $view)
    {
        $this->db = $db;
        $this->view = $view;
    }

    // 1. Affiche la page HTML
    public function profile(Request $request, Response $response, array $args): Response
    {
        $ticker = strtoupper(trim($args['ticker']));

        // Récupération des infos de l'entreprise
        $stmt = $this->db->prepare("SELECT * FROM companies WHERE ticker = :ticker LIMIT 1");
        $stmt->execute(['ticker' => $ticker]);
        $company = $stmt->fetch();

        if (!$company) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        // Récupération des initiés récents pour cette entreprise
        // CORRECTION : Utilisation de :cid1 et :cid2 pour éviter le PDOException HY093
        $stmtInsiders = $this->db->prepare("
            SELECT DISTINCT i.id, i.name, 
                   (SELECT officer_title FROM transactions t WHERE t.insider_id = i.id AND t.company_id = :cid1 ORDER BY t.transaction_date DESC LIMIT 1) as title
            FROM transactions_jour tj
            JOIN insiders i ON tj.insider_id = i.id
            WHERE tj.company_id = :cid2
            ORDER BY tj.transaction_date DESC
            LIMIT 10
        ");
        $stmtInsiders->execute([
            'cid1' => $company['id'],
            'cid2' => $company['id']
        ]);
        $insiders = $stmtInsiders->fetchAll();

        return $this->view->render($response, 'company_profile.html.twig', [
            'company' => $company,
            'insiders' => $insiders
        ]);
    }

    // 2. Endpoint API pour fournir les données au graphique (OHLC + Transactions)
    public function chartData(Request $request, Response $response, array $args): Response
    {
        $ticker = strtoupper(trim($args['ticker']));

        $stmtC = $this->db->prepare("SELECT id FROM companies WHERE ticker = :ticker LIMIT 1");
        $stmtC->execute(['ticker' => $ticker]);
        $companyId = $stmtC->fetchColumn();

        if (!$companyId) {
            $response->getBody()->write(json_encode(['error' => 'Company not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // A. Historique des prix (OHLCV) trié par ordre chronologique
        $stmtPrices = $this->db->prepare("
            SELECT price_date as time, open_price as open, high_price as high, low_price as low, close_price as close, volume
            FROM stock_prices 
            WHERE ticker = :ticker
            ORDER BY price_date ASC
        ");
        $stmtPrices->execute(['ticker' => $ticker]);
        $prices = $stmtPrices->fetchAll(PDO::FETCH_ASSOC);

        // B. Les transactions des initiés (pour les marqueurs sur le graphique)
        $stmtTx = $this->db->prepare("
            SELECT transaction_date as time, transaction_code as type, avg_price_per_share as price, i.name as insider_name
            FROM transactions_jour tj
            JOIN insiders i ON tj.insider_id = i.id
            WHERE tj.company_id = :cid
            ORDER BY transaction_date ASC
        ");
        $stmtTx->execute(['cid' => $companyId]);
        $transactions = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode([
            'prices' => $prices,
            'transactions' => $transactions
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}