<?php

/**
 * Database Migration Runner
 * 
 * Run this script to set up or update your database
 * 
 * Usage:
 *   php migrate.php                    # Run all pending migrations
 *   php migrate.php --rollback        # Rollback last migration
 *   php migrate.php --fresh           # Drop all tables and re-run migrations
 *   php migrate.php --status          # Show migration status
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Set timezone
date_default_timezone_set('America/New_York');

// Parse command line arguments
$options = getopt('', ['rollback', 'fresh', 'status', 'force']);
$isRollback = isset($options['rollback']);
$isFresh = isset($options['fresh']);
$isStatus = isset($options['status']);
$force = isset($options['force']);

// Database connection
$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_DATABASE'] ?? 'unified_events'
    ),
    $_ENV['DB_USERNAME'] ?? 'root',
    $_ENV['DB_PASSWORD'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true  // Enable buffered queries
    ]
);

// Colors for output
class Colors {
    const GREEN = "\033[0;32m";
    const RED = "\033[0;31m";
    const YELLOW = "\033[0;33m";
    const BLUE = "\033[0;34m";
    const RESET = "\033[0m";
}

function success($message) {
    echo Colors::GREEN . "✓ " . $message . Colors::RESET . "\n";
}

function error($message) {
    echo Colors::RED . "✗ " . $message . Colors::RESET . "\n";
}

function info($message) {
    echo Colors::BLUE . "→ " . $message . Colors::RESET . "\n";
}

function warning($message) {
    echo Colors::YELLOW . "⚠ " . $message . Colors::RESET . "\n";
}

// Create migrations table if it doesn't exist
function createMigrationsTable($db) {
    $sql = "CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL,
        batch INT NOT NULL,
        migrated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
}

// Get list of migration files
function getMigrationFiles() {
    $files = glob(__DIR__ . '/*.sql');
    sort($files);
    return $files;
}

// Get completed migrations
function getCompletedMigrations($db) {
    $stmt = $db->query("SELECT migration FROM migrations ORDER BY batch, migration");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get next batch number
function getNextBatch($db) {
    $stmt = $db->query("SELECT MAX(batch) as max_batch FROM migrations");
    $result = $stmt->fetch();
    return ($result['max_batch'] ?? 0) + 1;
}

// Run a migration
function runMigration($db, $file, $batch) {
    $migration = basename($file);
    info("Running migration: $migration");
    
    try {
        // Read the SQL file
        $sql = file_get_contents($file);
        
        // Remove comments
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // Split by semicolon
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        $success = true;
        foreach ($statements as $statement) {
            if (empty($statement)) continue;
            
            try {
                // Check if this is a SELECT statement
                if (stripos($statement, 'SELECT') === 0) {
                    $stmt = $db->query($statement);
                    $results = $stmt->fetchAll();
                    
                    // Display SELECT results
                    foreach ($results as $row) {
                        foreach ($row as $value) {
                            echo $value . "\n";
                        }
                    }
                    $stmt->closeCursor();
                } else {
                    $db->exec($statement);
                }
            } catch (PDOException $e) {
                error("Failed on statement: " . substr($statement, 0, 50) . "...");
                error("Error: " . $e->getMessage());
                $success = false;
                break;
            }
        }
        
        if ($success) {
            // Record the migration
            $stmt = $db->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
            $stmt->execute([$migration, $batch]);
            
            success("Migrated: $migration");
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error("Failed to migrate $migration: " . $e->getMessage());
        return false;
    }
}

// Show migration status
function showStatus($db) {
    $completed = getCompletedMigrations($db);
    $files = getMigrationFiles();
    
    echo "\n" . Colors::BLUE . "Migration Status:" . Colors::RESET . "\n";
    echo str_repeat("-", 60) . "\n";
    
    foreach ($files as $file) {
        $migration = basename($file);
        if (in_array($migration, $completed)) {
            echo Colors::GREEN . "✓ " . Colors::RESET . $migration . "\n";
        } else {
            echo Colors::YELLOW . "○ " . Colors::RESET . $migration . " (pending)\n";
        }
    }
    
    echo str_repeat("-", 60) . "\n";
    $pending = count($files) - count($completed);
    echo "Total: " . count($files) . " migrations ";
    echo "(" . count($completed) . " completed, $pending pending)\n\n";
}

// Main execution
echo "\n" . Colors::BLUE . "Unified Events Database Migration" . Colors::RESET . "\n";
echo str_repeat("=", 60) . "\n\n";

try {
    // Create migrations table
    createMigrationsTable($db);
    
    if ($isStatus) {
        showStatus($db);
        exit(0);
    }
    
    if ($isFresh) {
        if (!$force) {
            echo Colors::YELLOW . "WARNING: This will drop all tables and data!" . Colors::RESET . "\n";
            echo "Are you sure? Type 'yes' to continue: ";
            $confirm = trim(fgets(STDIN));
            if ($confirm !== 'yes') {
                error("Migration cancelled");
                exit(1);
            }
        }
        
        info("Dropping all tables...");
        
        // Get all tables
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Disable foreign key checks
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Drop each table
        foreach ($tables as $table) {
            $db->exec("DROP TABLE IF EXISTS `$table`");
            info("Dropped table: $table");
        }
        
        // Re-enable foreign key checks
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        // Recreate migrations table
        createMigrationsTable($db);
        
        success("Database cleared");
    }
    
    if ($isRollback) {
        // Get last batch
        $stmt = $db->query("SELECT MAX(batch) as last_batch FROM migrations");
        $lastBatch = $stmt->fetch()['last_batch'];
        
        if (!$lastBatch) {
            warning("No migrations to rollback");
            exit(0);
        }
        
        // Get migrations in last batch
        $stmt = $db->prepare("SELECT migration FROM migrations WHERE batch = ? ORDER BY migration DESC");
        $stmt->execute([$lastBatch]);
        $toRollback = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($toRollback as $migration) {
            info("Rolling back: $migration");
            
            // Look for down migration
            $downFile = __DIR__ . '/rollback/' . $migration;
            if (file_exists($downFile)) {
                $sql = file_get_contents($downFile);
                $db->exec($sql);
            }
            
            // Remove from migrations table
            $stmt = $db->prepare("DELETE FROM migrations WHERE migration = ?");
            $stmt->execute([$migration]);
            
            success("Rolled back: $migration");
        }
        
        exit(0);
    }
    
    // Run pending migrations
    $completed = getCompletedMigrations($db);
    $files = getMigrationFiles();
    $batch = getNextBatch($db);
    $migrated = 0;
    
    foreach ($files as $file) {
        $migration = basename($file);
        
        if (!in_array($migration, $completed)) {
            if (runMigration($db, $file, $batch)) {
                $migrated++;
            } else {
                error("Migration failed. Stopping.");
                exit(1);
            }
        }
    }
    
    if ($migrated === 0) {
        info("No pending migrations");
    } else {
        success("Migrated $migrated file(s)");
    }
    
    // Show final status
    echo "\n";
    showStatus($db);
    
} catch (Exception $e) {
    error("Migration error: " . $e->getMessage());
    exit(1);
}

success("Migration complete!\n");