# Unified Events System

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
