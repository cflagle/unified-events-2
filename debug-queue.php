<?php
/**
 * Debug Queue Processing
 * This will show us exactly what's happening when a job is processed
 */

require_once __DIR__ . '/vendor/autoload.php';

use UnifiedEvents\Services\EventProcessor;
use UnifiedEvents\Services\RouterService;
use UnifiedEvents\Models\ProcessingQueue;
use UnifiedEvents\Models\Event;
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

echo "=== QUEUE PROCESSING DEBUG ===\n\n";

// Get a failed job
$failedJob = $db->findOne('processing_queue', [
    'status' => 'failed'
]);

if (!$failedJob) {
    echo "No failed jobs found. Let's check pending jobs:\n";
    $failedJob = $db->findOne('processing_queue', [
        'status' => 'pending'
    ]);
}

if (!$failedJob) {
    echo "No jobs found in queue!\n";
    exit;
}

echo "Found job ID: " . $failedJob['id'] . "\n";
echo "Event ID: " . $failedJob['event_id'] . "\n";
echo "Platform ID: " . $failedJob['platform_id'] . "\n\n";

// Get the platform
$platform = $db->findOne('platforms', ['id' => $failedJob['platform_id']]);
echo "Platform: " . $platform['platform_code'] . "\n";
echo "API Config: " . $platform['api_config'] . "\n\n";

// Test RouterService
echo "Testing RouterService...\n";
$router = new RouterService();
$platformFromRouter = $router->getPlatformById($failedJob['platform_id']);

echo "Platform from router:\n";
print_r($platformFromRouter);

// Try to create platform instance
echo "\nTrying to create platform instance...\n";
try {
    $config = json_decode($platform['api_config'], true);
    $config['platform_code'] = $platform['platform_code'];
    $config['display_name'] = $platform['display_name'];
    
    echo "Config being passed:\n";
    print_r($config);
    
    $platformInstance = PlatformFactory::create($platform['platform_code'], $config);
    echo "✓ Platform created successfully\n";
    
    // Test the configuration
    $platformInstance->validateConfig();
    echo "✓ Config validated\n";
    
} catch (Exception $e) {
    echo "✗ Error creating platform: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// Try to process the job manually
echo "\n\nTrying to process job manually...\n";
try {
    // Get the event
    $event = Event::find($failedJob['event_id']);
    if (!$event) {
        echo "✗ Event not found!\n";
        exit;
    }
    
    echo "Event details:\n";
    echo "  Email: " . $event->email . "\n";
    echo "  Status: " . $event->status . "\n";
    echo "  Type: " . $event->event_type . "\n\n";
    
    // Create platform instance with full config from router
    $platformConfig = $router->getPlatformById($failedJob['platform_id']);
    if (!$platformConfig) {
        echo "✗ Platform config not found from router!\n";
        exit;
    }
    
    echo "Creating platform with router config...\n";
    $platformInstance = PlatformFactory::create($platformConfig['platform_code'], $platformConfig);
    echo "✓ Platform created\n";
    
    // Try to send directly
    echo "\nTrying to send event to platform...\n";
    $result = $platformInstance->send($event);
    
    echo "Send result:\n";
    print_r($result);
    
    if (!$result['success']) {
        echo "\n✗ Send failed: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}