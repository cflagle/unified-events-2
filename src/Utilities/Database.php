<?php

namespace UnifiedEvents\Utilities;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?PDO $connection = null;
    private static array $config = [];
    private Logger $logger;
    
    public function __construct()
    {
        $this->logger = new Logger();
        $this->connect();
    }
    
    /**
     * Initialize database configuration
     */
    public static function initialize(array $config): void
    {
        self::$config = $config;
    }
    
    /**
     * Get PDO connection
     */
    private function connect(): PDO
    {
        if (self::$connection === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    self::$config['host'] ?? $_ENV['DB_HOST'] ?? 'localhost',
                    self::$config['port'] ?? $_ENV['DB_PORT'] ?? '3306',
                    self::$config['database'] ?? $_ENV['DB_DATABASE'] ?? 'unified_events'
                );
                
                self::$connection = new PDO(
                    $dsn,
                    self::$config['username'] ?? $_ENV['DB_USERNAME'] ?? 'root',
                    self::$config['password'] ?? $_ENV['DB_PASSWORD'] ?? '',
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                    ]
                );
            } catch (PDOException $e) {
                $this->logger->error('Database connection failed', [
                    'error' => $e->getMessage()
                ]);
                throw new Exception('Database connection failed: ' . $e->getMessage());
            }
        }
        
        return self::$connection;
    }
    
    /**
     * Execute a query and return results
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->connect()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logger->error('Query failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Query failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Execute a query and return single row
     */
    public function queryRow(string $sql, array $params = []): ?array
    {
        $results = $this->query($sql, $params);
        return $results[0] ?? null;
    }
    
    /**
     * Execute a query without returning results
     */
    public function execute(string $sql, array $params = []): bool
    {
        try {
            $stmt = $this->connect()->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            $this->logger->error('Execute failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Insert data into table
     */
    public function insert(string $table, array $data): int|false
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":$col", $columns);
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->escapeIdentifier($table),
            implode(', ', array_map([$this, 'escapeIdentifier'], $columns)),
            implode(', ', $placeholders)
        );
        
        try {
            $stmt = $this->connect()->prepare($sql);
            $stmt->execute($data);
            return (int)$this->connect()->lastInsertId();
        } catch (PDOException $e) {
            $this->logger->error('Insert failed', [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Update data in table
     */
    public function update(string $table, array $data, array $where): bool
    {
        $setParts = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = sprintf('%s = :set_%s', $this->escapeIdentifier($column), $column);
            $params["set_$column"] = $value;
        }
        
        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereParts[] = sprintf('%s = :where_%s', $this->escapeIdentifier($column), $column);
            $params["where_$column"] = $value;
        }
        
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $this->escapeIdentifier($table),
            implode(', ', $setParts),
            implode(' AND ', $whereParts)
        );
        
        return $this->execute($sql, $params);
    }
    
    /**
     * Delete from table
     */
    public function delete(string $table, array $where): bool
    {
        $whereParts = [];
        $params = [];
        
        foreach ($where as $column => $value) {
            $whereParts[] = sprintf('%s = :%s', $this->escapeIdentifier($column), $column);
            $params[$column] = $value;
        }
        
        $sql = sprintf(
            "DELETE FROM %s WHERE %s",
            $this->escapeIdentifier($table),
            implode(' AND ', $whereParts)
        );
        
        return $this->execute($sql, $params);
    }
    
    /**
     * Find one record
     */
    public function findOne(string $table, array $where): ?array
    {
        $whereParts = [];
        $params = [];
        
        foreach ($where as $column => $value) {
            $whereParts[] = sprintf('%s = :%s', $this->escapeIdentifier($column), $column);
            $params[$column] = $value;
        }
        
        $sql = sprintf(
            "SELECT * FROM %s WHERE %s LIMIT 1",
            $this->escapeIdentifier($table),
            implode(' AND ', $whereParts)
        );
        
        return $this->queryRow($sql, $params);
    }
    
    /**
     * Find all matching records
     */
    public function findAll(string $table, array $where = [], string $orderBy = '', int $limit = 0): array
    {
        $whereParts = [];
        $params = [];
        
        foreach ($where as $column => $value) {
            if ($value === null) {
                $whereParts[] = sprintf('%s IS NULL', $this->escapeIdentifier($column));
            } else {
                $whereParts[] = sprintf('%s = :%s', $this->escapeIdentifier($column), $column);
                $params[$column] = $value;
            }
        }
        
        $sql = sprintf("SELECT * FROM %s", $this->escapeIdentifier($table));
        
        if (!empty($whereParts)) {
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy;
        }
        
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        return $this->query($sql, $params);
    }
    
    /**
     * Count records
     */
    public function count(string $table, array $where = []): int
    {
        $whereParts = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            // Handle array conditions like ['column', '=', 'value']
            if (is_array($value) && count($value) === 3) {
                $column = $value[0];
                $operator = $value[1];
                $val = $value[2];
                
                $paramKey = $column . '_' . count($params);
                $whereParts[] = sprintf('%s %s :%s', $this->escapeIdentifier($column), $operator, $paramKey);
                $params[$paramKey] = $val;
            }
            // Handle simple key-value pairs
            elseif (!is_array($value)) {
                if ($value === null) {
                    $whereParts[] = sprintf('%s IS NULL', $this->escapeIdentifier($key));
                } else {
                    $whereParts[] = sprintf('%s = :%s', $this->escapeIdentifier($key), $key);
                    $params[$key] = $value;
                }
            }
        }
        
        $sql = sprintf("SELECT COUNT(*) as count FROM %s", $this->escapeIdentifier($table));
        
        if (!empty($whereParts)) {
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }
        
        $result = $this->queryRow($sql, $params);
        return (int)($result['count'] ?? 0);
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->connect()->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->connect()->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        return $this->connect()->rollBack();
    }
    
    /**
     * Execute in transaction
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * Escape identifier (table/column name)
     */
    private function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId(): int
    {
        return (int)$this->connect()->lastInsertId();
    }
    
    /**
     * Check if table exists
     */
    public function tableExists(string $table): bool
    {
        $sql = "SHOW TABLES LIKE ?";
        $result = $this->query($sql, [$table]);
        return count($result) > 0;
    }
    
    /**
     * Run raw SQL (for migrations)
     */
    public function raw(string $sql): bool
    {
        try {
            return $this->connect()->exec($sql) !== false;
        } catch (PDOException $e) {
            $this->logger->error('Raw SQL failed', [
                'sql' => $sql,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}