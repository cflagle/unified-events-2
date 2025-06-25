<?php

namespace UnifiedEvents\Utilities;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    private static ?MonologLogger $instance = null;
    private static string $logPath = __DIR__ . '/../../logs/';
    
    /**
     * Get logger instance
     */
    private static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = new MonologLogger('unified-events');
            
            // Ensure log directory exists
            if (!is_dir(self::$logPath)) {
                mkdir(self::$logPath, 0777, true);
            }
            
            // Custom format matching your current format
            $dateFormat = "Y-m-d H:i:s";
            $output = "%datetime%, [%channel%], %level_name%, %message%, %context%\n";
            $formatter = new LineFormatter($output, $dateFormat);
            
            // Daily rotating file handler
            $handler = new RotatingFileHandler(
                self::$logPath . 'events.log',
                30, // Keep 30 days of logs
                MonologLogger::INFO
            );
            $handler->setFormatter($formatter);
            self::$instance->pushHandler($handler);
            
            // Error log handler
            $errorHandler = new RotatingFileHandler(
                self::$logPath . 'errors.log',
                30,
                MonologLogger::ERROR
            );
            $errorHandler->setFormatter($formatter);
            self::$instance->pushHandler($errorHandler);
            
            // Add console handler in development
            if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
                $consoleHandler = new StreamHandler('php://stdout', MonologLogger::DEBUG);
                $consoleHandler->setFormatter($formatter);
                self::$instance->pushHandler($consoleHandler);
            }
        }
        
        return self::$instance;
    }
    
    /**
     * Log event with structured format (matching your logEvent function)
     */
    public function logEvent(string $type, string $email, string $message, $statusCode, string $status = 'success'): void
    {
        $logEntry = sprintf(
            "%s, [%s], Status: %s, Email: %s, Info: %s, %s",
            date('Y-m-d H:i:s'),
            $type,
            $status,
            $email,
            $message,
            $statusCode
        );
        
        // Write to specific event log file
        $eventLogPath = self::$logPath . 'lead-processing-events.log';
        file_put_contents($eventLogPath, $logEntry . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        // Also log to main logger
        $context = [
            'type' => $type,
            'email' => $email,
            'status' => $status,
            'status_code' => $statusCode
        ];
        
        if ($status === 'success') {
            $this->info($message, $context);
        } else {
            $this->error($message, $context);
        }
    }
    
    /**
     * Log info message
     */
    public function info(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }
    
    /**
     * Log error message
     */
    public function error(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }
    
    /**
     * Log debug message
     */
    public function debug(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }
    
    /**
     * Log critical message
     */
    public function critical(string $message, array $context = []): void
    {
        self::getInstance()->critical($message, $context);
    }
    
    /**
     * Log platform-specific events
     */
    public function logPlatformEvent(string $platform, string $email, array $request, array $response, bool $success): void
    {
        $context = [
            'platform' => $platform,
            'email' => $email,
            'request' => $request,
            'response' => $response,
            'success' => $success
        ];
        
        if ($success) {
            $this->info("Platform request successful: $platform", $context);
        } else {
            $this->error("Platform request failed: $platform", $context);
        }
        
        // Also use structured event logging
        $this->logEvent(
            $platform,
            $email,
            json_encode($response),
            $response['status_code'] ?? 'N/A',
            $success ? 'success' : 'failure'
        );
    }
    
    /**
     * Log bot detection
     */
    public function logBotDetection(string $email, string $ip, array $honeypotFields): void
    {
        $message = sprintf(
            "Bot detected - Email: %s, IP: %s, Honeypot fields: %s",
            $email,
            $ip,
            implode(', ', $honeypotFields)
        );
        
        $this->warning($message, [
            'email' => $email,
            'ip' => $ip,
            'honeypot_fields' => $honeypotFields
        ]);
        
        // Write to bots CSV (matching your format)
        $botLogPath = self::$logPath . 'bots.csv';
        $csvLine = sprintf(
            "%s, %s, %s, %s\n",
            date("F j, Y, g:i a"),
            $email,
            $ip,
            implode('|', $honeypotFields)
        );
        file_put_contents($botLogPath, $csvLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Log revenue event
     */
    public function logRevenue(string $platform, string $email, float $amount, string $status = 'pending'): void
    {
        $this->info("Revenue event", [
            'platform' => $platform,
            'email' => $email,
            'amount' => $amount,
            'status' => $status
        ]);
        
        // Write to revenue CSV
        $revenueLogPath = self::$logPath . 'revenue.csv';
        $csvLine = sprintf(
            "%s, %s, %s, %.2f, %s\n",
            date("Y-m-d H:i:s"),
            $platform,
            $email,
            $amount,
            $status
        );
        file_put_contents($revenueLogPath, $csvLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Set custom log path
     */
    public static function setLogPath(string $path): void
    {
        self::$logPath = rtrim($path, '/') . '/';
    }
    
    /**
     * Get current log path
     */
    public static function getLogPath(): string
    {
        return self::$logPath;
    }
}