<?php

/**
 * Unified Events Dashboard
 * Main dashboard page showing system overview
 */

require_once __DIR__ . '/../vendor/autoload.php';

use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Services\EventProcessor;
use UnifiedEvents\Services\QueueService;
use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize database
Database::initialize([
    'host' => $_ENV['DB_HOST'],
    'port' => $_ENV['DB_PORT'],
    'database' => $_ENV['DB_DATABASE'],
    'username' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD']
]);

// Set timezone
date_default_timezone_set('America/New_York');

// Get time period
$period = $_GET['period'] ?? '24h';

// Initialize services
$db = new Database();
$eventProcessor = new EventProcessor();
$queueService = new QueueService();

// Get stats
$stats = $eventProcessor->getStats($period);
$queueStats = $queueService->getStats();

// Get recent events
$recentEvents = $db->query(
    "SELECT e.*, p.platform_code, p.display_name as platform_name
     FROM events e
     LEFT JOIN processing_queue pq ON e.id = pq.event_id
     LEFT JOIN platforms p ON pq.platform_id = p.id
     ORDER BY e.created_at DESC
     LIMIT 10"
);

// Get revenue for today
$todayRevenue = $db->queryRow(
    "SELECT SUM(gross_revenue) as total 
     FROM revenue_tracking 
     WHERE DATE(created_at) = CURDATE()"
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Events Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .navbar {
            background-color: var(--primary-color) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,.05);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }
        
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-processing { background-color: #cce5ff; color: #004085; }
        .status-completed { background-color: #d4edda; color: #155724; }
        .status-failed { background-color: #f8d7da; color: #721c24; }
        .status-blocked { background-color: #e2e3e5; color: #383d41; }
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,.05);
        }
        
        .platform-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            background-color: #e9ecef;
            color: #495057;
        }
        
        .revenue-positive {
            color: var(--success-color);
            font-weight: 600;
        }
        
        .queue-progress {
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-graph-up-arrow"></i> Unified Events
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="events/all.php">Events</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="platforms/performance.php">Platforms</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="analytics/revenue.php">Revenue</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin/bots.php">Admin</a>
                    </li>
                </ul>
                <div class="ms-auto">
                    <select class="form-select form-select-sm" onchange="changePeriod(this.value)">
                        <option value="1h" <?= $period === '1h' ? 'selected' : '' ?>>Last Hour</option>
                        <option value="24h" <?= $period === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                        <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                        <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                    </select>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid mt-4">
        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="stat-label">Total Events</p>
                                <h3 class="stat-number"><?= number_format($stats['events_processed']) ?></h3>
                            </div>
                            <div class="text-primary">
                                <i class="bi bi-calendar-event" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="stat-label">Queue Pending</p>
                                <h3 class="stat-number"><?= number_format($stats['queue_pending']) ?></h3>
                            </div>
                            <div class="text-warning">
                                <i class="bi bi-hourglass-split" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                        <?php if ($stats['queue_pending'] > 0): ?>
                        <div class="queue-progress bg-light mt-2">
                            <div class="bg-warning" style="width: <?= min(100, ($stats['queue_pending'] / 1000) * 100) ?>%; height: 100%;"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="stat-label">Today's Revenue</p>
                                <h3 class="stat-number revenue-positive">$<?= number_format($todayRevenue['total'] ?? 0, 2) ?></h3>
                            </div>
                            <div class="text-success">
                                <i class="bi bi-currency-dollar" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="stat-label">Blocked Events</p>
                                <h3 class="stat-number text-danger"><?= number_format($stats['events_blocked']) ?></h3>
                            </div>
                            <div class="text-danger">
                                <i class="bi bi-shield-x" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Queue Status -->
        <div class="row g-3 mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Queue Status by Platform</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Platform</th>
                                        <th>Pending</th>
                                        <th>Processing</th>
                                        <th>Completed</th>
                                        <th>Failed</th>
                                        <th>Success Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queueStats['by_platform'] as $platform => $platformStats): ?>
                                    <tr>
                                        <td><span class="platform-badge"><?= htmlspecialchars($platform) ?></span></td>
                                        <td><?= $platformStats['pending'] ?? 0 ?></td>
                                        <td><?= $platformStats['processing'] ?? 0 ?></td>
                                        <td class="text-success"><?= $platformStats['completed'] ?? 0 ?></td>
                                        <td class="text-danger"><?= $platformStats['failed'] ?? 0 ?></td>
                                        <td>
                                            <?php 
                                            $total = ($platformStats['completed'] ?? 0) + ($platformStats['failed'] ?? 0);
                                            $rate = $total > 0 ? round(($platformStats['completed'] / $total) * 100, 1) : 0;
                                            ?>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-success" style="width: <?= $rate ?>%">
                                                    <?= $rate ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Validation Stats</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Bot Detections</span>
                                <strong><?= number_format($stats['validation_stats']['bot_stats']['total_bots'] ?? 0) ?></strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Emails Validated</span>
                                <strong><?= number_format($stats['validation_stats']['email_validation_stats']['total_validated'] ?? 0) ?></strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Valid Emails</span>
                                <strong class="text-success"><?= number_format($stats['validation_stats']['email_validation_stats']['valid'] ?? 0) ?></strong>
                            </div>
                        </div>
                        <div>
                            <div class="d-flex justify-content-between">
                                <span>Invalid Emails</span>
                                <strong class="text-danger"><?= number_format($stats['validation_stats']['email_validation_stats']['invalid'] ?? 0) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Events -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Events</h5>
                <a href="events/all.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Email</th>
                                <th>Campaign</th>
                                <th>Platform</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentEvents as $event): ?>
                            <tr>
                                <td class="text-muted"><?= date('g:i A', strtotime($event['created_at'])) ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= ucfirst($event['event_type']) ?></span>
                                </td>
                                <td><?= htmlspecialchars(substr($event['email'] ?? '', 0, 20)) ?>...</td>
                                <td><?= htmlspecialchars($event['campaign'] ?? '-') ?></td>
                                <td>
                                    <?php if ($event['platform_code']): ?>
                                    <span class="platform-badge"><?= htmlspecialchars($event['platform_name']) ?></span>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $event['status'] ?>">
                                        <?= ucfirst($event['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function changePeriod(period) {
            window.location.href = '?period=' + period;
        }
        
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>