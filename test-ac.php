<?php
/**
 * Test ActiveCampaign Integration
 */

require_once __DIR__ . '/vendor/autoload.php';

use UnifiedEvents\Models\Event;
use UnifiedEvents\Services\RouterService;
use UnifiedEvents\Platforms\PlatformFactory;
use UnifiedEvents\Utilities\Database;
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

echo "=== TESTING ACTIVECAMPAIGN ===\n\n";

// Get an event with valid email
$db = new Database();
$validEvent = $db->findOne('events', [
    ['email_validation_status', '!=', 'invalid']
]);

if (!$validEvent) {
    // Create a test event
    $event = Event::createFromRequest([
        'email' => 'live-test-' . time() . '@gmail.com',
        'first_name' => 'Test',
        'last_name' => 'User',
        'campaign' => 'test_campaign',
        'source' => 'test_source'
    ], 'lead');
    
    $event->email_validation_status = 'valid';
    $event->save();
    
    echo "Created test event with ID: " . $event->id . "\n";
} else {
    $event = Event::fromArray($validEvent);
    echo "Using existing event ID: " . $event->id . "\n";
}

echo "Email: " . $event->email . "\n\n";

// Get ActiveCampaign platform
$router = new RouterService();
$acPlatform = $router->getPlatformByCode('activecampaign');

if (!$acPlatform) {
    echo "ActiveCampaign platform not found!\n";
    exit;
}

echo "ActiveCampaign config:\n";
print_r($acPlatform);

// Create platform instance
try {
    $ac = PlatformFactory::create('activecampaign', $acPlatform);
    echo "\n✓ ActiveCampaign platform created\n";
    
    // Test sending
    echo "\nSending to ActiveCampaign...\n";
    $result = $ac->send($event);
    
    echo "\nResult:\n";
    print_r($result);
    
    if ($result['success']) {
        echo "\n✓ SUCCESS! Contact synced to ActiveCampaign\n";
        if (isset($result['contact_id'])) {
            echo "Contact ID: " . $result['contact_id'] . "\n";
        }
        if (isset($result['was_update']) && $result['was_update']) {
            echo "This was an UPDATE (contact already existed)\n";
        }
    } else {
        echo "\n✗ FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
}