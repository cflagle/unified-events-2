<?php
/**
 * Debug script to test the environment
 * Access at: /leads/33/api/debug.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";
echo "=== ENVIRONMENT DEBUG ===\n\n";

// Check PHP version
echo "PHP Version: " . phpversion() . "\n\n";

// Check if vendor/autoload exists
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
echo "Autoload exists: " . (file_exists($autoloadPath) ? 'YES' : 'NO') . "\n";
echo "Autoload path: " . $autoloadPath . "\n\n";

// Check if .env exists
$envPath = __DIR__ . '/../.env';
echo ".env exists: " . (file_exists($envPath) ? 'YES' : 'NO') . "\n";
echo ".env path: " . $envPath . "\n\n";

// Try to load composer autoload
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    echo "✓ Composer autoload loaded successfully\n\n";
    
    // Try to load dotenv
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        echo "✓ Dotenv loaded successfully\n\n";
        
        // Show database config (without password)
        echo "Database Config:\n";
        echo "  Host: " . ($_ENV['DB_HOST'] ?? 'NOT SET') . "\n";
        echo "  Database: " . ($_ENV['DB_DATABASE'] ?? 'NOT SET') . "\n";
        echo "  Username: " . ($_ENV['DB_USERNAME'] ?? 'NOT SET') . "\n";
        echo "  Password: " . (isset($_ENV['DB_PASSWORD']) ? '***SET***' : 'NOT SET') . "\n\n";
        
    } catch (Exception $e) {
        echo "✗ Dotenv error: " . $e->getMessage() . "\n\n";
    }
    
    // Test database connection
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? 'localhost',
            $_ENV['DB_PORT'] ?? '3306',
            $_ENV['DB_DATABASE'] ?? 'unified_events'
        );
        
        $db = new PDO($dsn, $_ENV['DB_USERNAME'] ?? '', $_ENV['DB_PASSWORD'] ?? '');
        echo "✓ Database connection successful\n\n";
        
        // Check if tables exist
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables found: " . count($tables) . "\n";
        if (count($tables) > 0) {
            echo "Tables: " . implode(', ', array_slice($tables, 0, 5)) . "...\n";
        }
        
    } catch (Exception $e) {
        echo "✗ Database error: " . $e->getMessage() . "\n\n";
    }
    
} else {
    echo "✗ Composer autoload not found!\n";
    echo "Run: composer install\n\n";
}

// Check required directories
$dirs = ['logs', 'workers', 'src', 'api/endpoints'];
echo "\nDirectory Check:\n";
foreach ($dirs as $dir) {
    $path = __DIR__ . '/../' . $dir;
    echo "  $dir: " . (is_dir($path) ? '✓ EXISTS' : '✗ MISSING') . "\n";
}

// Check error log
$errorLog = error_get_last();
if ($errorLog) {
    echo "\nLast Error:\n";
    print_r($errorLog);
}

echo "</pre>";