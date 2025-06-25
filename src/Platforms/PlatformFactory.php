<?php

namespace UnifiedEvents\Platforms;

use Exception;

class PlatformFactory
{
    /**
     * Platform class mapping
     */
    private static array $platformMap = [
        'activecampaign' => ActiveCampaignPlatform::class,
        'woopra' => WoopraPlatform::class,
        'zerobounce' => ZeroBouncePlatform::class,
        'limecellular' => LimeCellularPlatform::class,
        'marketbeat' => MarketbeatPlatform::class,
        'mailercloud' => MailercloudPlatform::class,
    ];
    
    /**
     * Create a platform instance
     */
    public static function create(string $platformCode, array $config): AbstractPlatform
    {
        $platformCode = strtolower($platformCode);
        
        if (!isset(self::$platformMap[$platformCode])) {
            throw new Exception("Unknown platform: $platformCode");
        }
        
        $className = self::$platformMap[$platformCode];
        
        if (!class_exists($className)) {
            throw new Exception("Platform class not found: $className");
        }
        
        // If api_config is nested, merge it into the main config
        if (isset($config['api_config']) && is_array($config['api_config'])) {
            // Merge api_config into the main config array
            $config = array_merge($config, $config['api_config']);
        }
        
        $instance = new $className($config);
        
        if (!($instance instanceof AbstractPlatform)) {
            throw new Exception("Platform must extend AbstractPlatform");
        }
        
        // Validate configuration
        $instance->validateConfig();
        
        return $instance;
    }
    
    /**
     * Register a custom platform
     */
    public static function register(string $platformCode, string $className): void
    {
        if (!class_exists($className)) {
            throw new Exception("Platform class not found: $className");
        }
        
        if (!is_subclass_of($className, AbstractPlatform::class)) {
            throw new Exception("Platform must extend AbstractPlatform");
        }
        
        self::$platformMap[strtolower($platformCode)] = $className;
    }
    
    /**
     * Get all registered platforms
     */
    public static function getRegisteredPlatforms(): array
    {
        return array_keys(self::$platformMap);
    }
    
    /**
     * Check if a platform is registered
     */
    public static function isRegistered(string $platformCode): bool
    {
        return isset(self::$platformMap[strtolower($platformCode)]);
    }
    
    /**
     * Create multiple platform instances from database config
     */
    public static function createFromDatabase(array $platformConfigs): array
    {
        $instances = [];
        
        foreach ($platformConfigs as $config) {
            try {
                $instances[$config['platform_code']] = self::create(
                    $config['platform_code'],
                    $config
                );
            } catch (Exception $e) {
                // Log error but continue with other platforms
                error_log("Failed to create platform {$config['platform_code']}: " . $e->getMessage());
            }
        }
        
        return $instances;
    }
}