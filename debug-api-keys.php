<?php
/**
 * Debug API Keys Configuration
 * Run this to see what's actually in the database
 */

require_once __DIR__ . '/vendor/autoload.php';

use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Platforms\PlatformFactory;
use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Initialize database
Database::initialize([
    'host' => $_ENV['DB_HOST'],
    'port' => $_ENV['DB_PORT'],
    'database' => $_ENV['DB_DATABASE'],
    'username' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD']
]);

$db = new Database();

echo "=== API KEY CONFIGURATION DEBUG ===\n\n";

// Get all platforms
$platforms = $db->findAll('platforms');

foreach ($platforms as $platform) {
    echo "Platform: " . $platform['platform_code'] . "\n";
    echo "Display Name: " . $platform['display_name'] . "\n";
    echo "Is Active: " . ($platform['is_active'] ? 'Yes' : 'No') . "\n";
    echo "API Config Raw: " . $platform['api_config'] . "\n";
    
    // Try to decode the JSON
    $config = json_decode($platform['api_config'], true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "API Config Decoded:\n";
        print_r($config);
        
        // Check for api_key
        if (isset($config['api_key'])) {
            echo "✓ API Key found: " . substr($config['api_key'], 0, 10) . "...\n";
        } else {
            echo "✗ API Key NOT FOUND in config!\n";
        }
    } else {
        echo "✗ JSON decode error: " . json_last_error_msg() . "\n";
    }
    
    // Try to instantiate the platform
    try {
        echo "Testing platform instantiation...\n";
        $platformConfig = $config ?: [];
        $platformConfig['platform_code'] = $platform['platform_code'];
        $platformConfig['display_name'] = $platform['display_name'];
        
        $instance = PlatformFactory::create($platform['platform_code'], $platformConfig);
        echo "✓ Platform instantiated successfully\n";
        
        // Try to validate config
        $instance->validateConfig();
        echo "✓ Configuration validated\n";
        
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
    
    echo str_repeat("-", 50) . "\n\n";
}

// Show a sample update command
echo "=== SAMPLE UPDATE COMMANDS ===\n\n";
echo "If keys are missing, run these SQL commands:\n\n";

echo "-- ActiveCampaign\n";
echo "UPDATE platforms\n";
echo "SET api_config = '{\"api_url\":\"https://rif868.api-us1.com\",\"api_key\":\"YOUR_AC_KEY\",\"list_id\":2}'\n";
echo "WHERE platform_code = 'activecampaign';\n\n";

echo "-- ZeroBounce\n";
echo "UPDATE platforms\n";
echo "SET api_config = '{\"api_key\":\"YOUR_ZB_KEY\",\"check_activity\":true}'\n";
echo "WHERE platform_code = 'zerobounce';\n\n";