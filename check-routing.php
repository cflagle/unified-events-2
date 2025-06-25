<?php
/**
 * Check routing rules and what platforms should receive events
 */

require_once __DIR__ . '/vendor/autoload.php';

use UnifiedEvents\Models\Event;
use UnifiedEvents\Services\RouterService;
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

$db = new Database();

echo "=== ROUTING RULES CHECK ===\n\n";

// Get the latest event
$latestEvent = $db->queryRow("SELECT * FROM events ORDER BY id DESC LIMIT 1");
if (!$latestEvent) {
    echo "No events found!\n";
    exit;
}

$event = Event::fromArray($latestEvent);
echo "Latest Event:\n";
echo "  ID: " . $event->id . "\n";
echo "  Email: " . $event->email . "\n";
echo "  Type: " . $event->event_type . "\n";
echo "  Status: " . $event->status . "\n";
echo "  Validation Status: " . $event->email_validation_status . "\n\n";

// Check routing
$router = new RouterService();
$routes = $router->getRoutesForEvent($event);

echo "Routes for this event:\n";
if (empty($routes)) {
    echo "  NO ROUTES FOUND!\n";
} else {
    foreach ($routes as $route) {
        echo "  - " . $route['platform_code'] . " (" . $route['display_name'] . ")\n";
    }
}

// Check what's in the queue for this event
echo "\nQueue entries for this event:\n";
$queueEntries = $db->findAll('processing_queue', ['event_id' => $event->id]);
foreach ($queueEntries as $entry) {
    $platform = $db->findOne('platforms', ['id' => $entry['platform_id']]);
    echo "  - Platform: " . $platform['platform_code'] . 
         ", Status: " . $entry['status'] . 
         ", Attempts: " . $entry['attempts'] . "\n";
}

// Check routing rules
echo "\nActive Routing Rules:\n";
$sql = "SELECT r.*, p.platform_code, et.type_code 
        FROM routing_rules r 
        JOIN platforms p ON r.platform_id = p.id 
        JOIN event_types et ON r.event_type_id = et.id 
        WHERE r.is_active = 1 
        ORDER BY et.type_code, r.priority";

$rules = $db->query($sql);
foreach ($rules as $rule) {
    echo "\n" . $rule['type_code'] . " -> " . $rule['platform_code'] . 
         " (priority: " . $rule['priority'] . ")\n";
    echo "  Name: " . $rule['rule_name'] . "\n";
    if ($rule['conditions']) {
        echo "  Conditions: " . $rule['conditions'] . "\n";
        
        // Check if this rule would match our event
        if ($rule['type_code'] === $event->event_type) {
            $conditions = json_decode($rule['conditions'], true);
            if ($conditions) {
                echo "  Would match this event? ";
                
                // Simple condition check
                $matches = true;
                foreach ($conditions as $field => $value) {
                    if ($field === 'email_validation_status') {
                        if (isset($value['not_equals']) && $event->email_validation_status == $value['not_equals']) {
                            $matches = false;
                        }
                    }
                }
                echo $matches ? "YES" : "NO";
                echo "\n";
            }
        }
    }
}