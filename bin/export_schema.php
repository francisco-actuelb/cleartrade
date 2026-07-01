<?php
// Fichier : scripts/export_schema.php
// Rôle : Générer un fichier .sql contenant la structure (sans les données) de la base.

require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

// Chargement des variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

try {
    $dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Fichier de destination à la racine du projet
    $outputFile = __DIR__ . '/../database_schema.sql';

    $sqlDump = "-- Export de la structure de la base de données : {$_ENV['DB_NAME']}\n";
    $sqlDump .= "-- Généré le : " . date('Y-m-d H:i:s') . "\n\n";

    // On désactive la vérification des clés étrangères pour faciliter l'import futur
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // Étape 1 : Récupérer le nom de toutes les tables de la base
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "⚠️ Aucune table trouvée dans la base de données.\n";
        exit;
    }

    // Étape 2 : Boucler sur chaque table pour récupérer son "CREATE TABLE"
    foreach ($tables as $table) {
        $sqlDump .= "-- --------------------------------------------------------\n";
        $sqlDump .= "-- Structure de la table `$table`\n";
        $sqlDump .= "-- --------------------------------------------------------\n";
        $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";

        $stmtCreate = $pdo->query("SHOW CREATE TABLE `$table`");
        $createRow = $stmtCreate->fetch(PDO::FETCH_ASSOC);

        // On ajoute le code de création avec un point-virgule à la fin
        $sqlDump .= $createRow['Create Table'] . ";\n\n";
    }

    // On réactive la vérification des clés étrangères
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // Étape 3 : Écriture du fichier
    file_put_contents($outputFile, $sqlDump);

    echo "✅ Succès ! Le schéma a été exporté dans : " . realpath($outputFile) . "\n";
    echo "💡 Pense à faire un 'git add database_schema.sql' pour ton prochain commit !\n";

} catch (PDOException $e) {
    echo "❌ Erreur de base de données : " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Erreur système : " . $e->getMessage() . "\n";
}