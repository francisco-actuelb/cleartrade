<?php
// /var/www/ct.hsrv.fr/public/index.php

use Slim\Factory\AppFactory;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use App\Controllers\HomeController;
use App\Controllers\IngestionController;
use App\Controllers\DetailsController;
use App\Controllers\AnalysisController;
use App\Controllers\InsiderController;
use App\Controllers\CompanyController;
use App\Controllers\ScreenerController;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
#use PDO;

// 1. Inclusion de l'autoloader généré par Composer
require __DIR__ . '/../vendor/autoload.php';

// Chargement des variables d'environnement (.env)
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Configuration du conteneur d'injection de dépendances (PHP-DI)
// CORRECTION : Utilisation de ContainerBuilder au lieu de Container
$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    // Définition de la connexion PDO
    PDO::class => function () {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $db   = $_ENV['DB_NAME'] ?? 'cleartrade';
        $user = $_ENV['DB_USER'] ?? 'ct_user';
        $pass = $_ENV['DB_PASS'] ?? '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        return new PDO($dsn, $user, $pass, $options);
    },

    // Définition de Twig
    Twig::class => function() {
        // On pointe vers notre dossier de templates, cache désactivé pour le développement
        return Twig::create(__DIR__ . '/../templates', ['cache' => false]);
    }
]);

// Construction finale du conteneur
$container = $containerBuilder->build();

// Assigner le conteneur à l'application Slim
AppFactory::setContainer($container);
$app = AppFactory::create();

// Ajouter le middleware Twig à l'application Slim pour activer le rendu
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

// Ajout du middleware de gestion des erreurs
$app->addErrorMiddleware(true, true, true);

// 3. Définition des routes MVC
$app->get('/', [HomeController::class, 'index']);
$app->get('/ingest', [IngestionController::class, 'ingest']);
$app->get('/details', [DetailsController::class, 'index']);
$app->post('/analyze', [AnalysisController::class, 'analyze']);
#A voir pour la suite si besoin de analyse-detail
$app->post('/analyze-detail/{id}', [AnalysisController::class, 'analyze_detail']);

// NOUVELLES ROUTES POUR LE PROFIL INITIÉ
$app->get('/api/insiders/search', [InsiderController::class, 'searchApi']); // L'API pour l'AJAX
$app->get('/insider/{id}', [InsiderController::class, 'profile']); // La page Profil
$app->post('/insider/{id}/analyze', [InsiderController::class, 'analyzeProfile']); // L'action de lancer l'IA

// NOUVELLES ROUTES ENTREPRISE
$app->get('/company/{ticker}', [CompanyController::class, 'profile']);
$app->get('/api/chart/data/{ticker}', [CompanyController::class, 'chartData']);

// NOUVELLE ROUTE POUR LE SCREENER
$app->get('/screener', [ScreenerController::class, 'index']);

// 4. Lancement de l'application
$app->run();