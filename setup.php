<?php
/**
 * Unified Events System - Setup Script
 * 
 * This script creates all necessary directories and files
 * Upload this single file to your server and run it
 */

echo "Unified Events System - Setup Script\n";
echo "====================================\n\n";

// Base directory (current directory)
$baseDir = __DIR__;

// Create directory structure
$directories = [
    'api',
    'api/endpoints',
    'api/middleware',
    'dashboard',
    'dashboard/events',
    'dashboard/platforms',
    'dashboard/analytics',
    'dashboard/admin',
    'migrations',
    'migrations/rollback',
    'src',
    'src/Models',
    'src/Platforms',
    'src/Services',
    'src/Utilities',
    'src/Events',
    'src/Validators',
    'src/Transformers',
    'workers',
    'logs',
    'scripts',
    'tests',
    'tests/Unit',
    'tests/Integration',
    'vendor'
];

echo "Creating directories...\n";
foreach ($directories as $dir) {
    $path = $baseDir . '/' . $dir;
    if (!is_dir($path)) {
        if (mkdir($path, 0755, true)) {
            echo "✓ Created: $dir\n";
        } else {
            echo "✗ Failed to create: $dir\n";
        }
    } else {
        echo "• Exists: $dir\n";
    }
}

// Create .gitkeep files
file_put_contents($baseDir . '/logs/.gitkeep', '');

// Create composer.json
$composerJson = '{
    "name": "yourcompany/unified-events",
    "description": "Unified Event Processing System",
    "type": "project",
    "require": {
        "php": "^8.0",
        "guzzlehttp/guzzle": "^7.5",
        "vlucas/phpdotenv": "^5.5",
        "monolog/monolog": "^3.0",
        "predis/predis": "^2.0",
        "ramsey/uuid": "^4.7",
        "defuse/php-encryption": "^2.3",
        "nesbot/carbon": "^2.66"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "friendsofphp/php-cs-fixer": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "UnifiedEvents\\\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "UnifiedEvents\\\\Tests\\\\": "tests/"
        }
    }
}';

file_put_contents($baseDir . '/composer.json', $composerJson);
echo "\n✓ Created composer.json\n";

// Create .env.example
$envExample = '# Application
APP_NAME="Unified Event Processor"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wallstwatchdogs.com/leads/33

# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=unified_events
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Redis (optional)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# API Keys (encrypted in database, but master key here)
ENCRYPTION_KEY=your-32-character-encryption-key

# Queue Settings
QUEUE_BATCH_SIZE=100
QUEUE_RETRY_ATTEMPTS=3
QUEUE_RETRY_DELAY=60

# Validation Cache
VALIDATION_CACHE_DAYS=30
BOT_CHECK_ENABLED=true

# Platform-specific settings
ZEROBOUNCE_ENABLED=true
ZEROBOUNCE_DAILY_LIMIT=10000

# Your API Keys
ZEROBOUNCE_API_KEY=your_key_here
ACTIVECAMPAIGN_API_KEY=your_key_here
MAILERCLOUD_API_KEY=your_key_here
';

file_put_contents($baseDir . '/.env.example', $envExample);
echo "✓ Created .env.example\n";

// Create .htaccess
$htaccess = '# Unified Events System
RewriteEngine On

# Force HTTPS
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Protect sensitive files
<FilesMatch "^\.env|composer\.(json|lock)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Protect directories
<FilesMatch "^(src|workers|migrations|logs|tests)/">
    Order allow,deny
    Deny from all
</FilesMatch>

# Route API requests
RewriteCond %{REQUEST_URI} ^/api/
RewriteRule ^api/(.*)$ api/index.php [L,QSA]

# Prevent directory listing
Options -Indexes
';

file_put_contents($baseDir . '/.htaccess', $htaccess);
echo "✓ Created .htaccess\n";

// Create README.md
$readme = '# Unified Events System

## Installation

1. Run composer install:
   ```
   composer install --no-dev --optimize-autoloader
   ```

2. Copy .env.example to .env and configure

3. Run migrations:
   ```
   php migrations/migrate.php
   ```

4. Set up cron jobs for workers

5. Configure your web server to point to this directory

## API Endpoints

- POST /api/events/lead - Process lead submissions
- POST /api/events/purchase - Process purchases
- GET /api/health - System health check
- GET /api/stats - System statistics

## Dashboard

Access the dashboard at: /dashboard/

## Support

For issues, check the logs/ directory.
';

file_put_contents($baseDir . '/README.md', $readme);
echo "✓ Created README.md\n";

echo "\n\n====================================\n";
echo "Setup Complete!\n\n";
echo "Next Steps:\n";
echo "1. Download all the individual PHP files from our conversation\n";
echo "2. Place them in their respective directories as shown above\n";
echo "3. Run: composer install --no-dev --optimize-autoloader\n";
echo "4. Copy .env.example to .env and configure it\n";
echo "5. Run: php migrations/migrate.php\n";
echo "\n";
echo "Directory structure created at: $baseDir\n";
echo "====================================\n";

// Show which files need to be added
echo "\nFiles you need to add from our conversation:\n\n";

$filesToAdd = [
    'api/index.php' => 'API Router',
    'api/endpoints/lead.php' => 'Lead API Endpoint',
    'api/endpoints/purchase.php' => 'Purchase API Endpoint',
    'api/endpoints/health.php' => 'Health Check Endpoint',
    'api/endpoints/stats.php' => 'Statistics Endpoint',
    'api/middleware/auth.php' => 'Authentication Middleware',
    'api/middleware/rate-limit.php' => 'Rate Limit Middleware',
    'dashboard/index.php' => 'Dashboard Index',
    'migrations/migrate.php' => 'Database Migration Runner',
    'migrations/*.sql' => 'All SQL migration files',
    'src/Models/*.php' => 'All Model classes',
    'src/Platforms/*.php' => 'All Platform classes',
    'src/Services/*.php' => 'All Service classes',
    'src/Utilities/*.php' => 'All Utility classes',
    'workers/*.php' => 'All Worker scripts'
];

foreach ($filesToAdd as $file => $description) {
    echo "- $file ($description)\n";
}

echo "\nYou can find all these files in our conversation above!\n";