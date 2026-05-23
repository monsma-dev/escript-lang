<?php

/**
 * RustQueryBridge - PHP Bridge to EScript Rust Query Service
 * 
 * This class provides a PHP interface to the Rust sidecar service that executes
 * whitelisted JSON-stored database queries. It enforces fail-closed security by
 * validating all requests against the EScript DatabaseQueryGuard.
 * 
 * @package EScript
 * @version 1.0.0
 */

namespace EScript;

class RustQueryBridge {
    
    private string $serviceUrl;
    private string $configPath;
    private array $queryConfig;
    private int $timeout;
    private bool $failClosed;
    
    /**
     * Constructor
     * 
     * @param string $serviceUrl URL of the Rust query service (default: http://localhost:8080)
     * @param string $configPath Path to db_queries.json config file
     * @param int $timeout Request timeout in seconds (default: 5)
     * @param bool $failClosed Enable fail-closed behavior (default: true)
     */
    public function __construct(
        string $serviceUrl = 'http://localhost:8080',
        string $configPath = __DIR__ . '/../config/db_queries.json',
        int $timeout = 5,
        bool $failClosed = true
    ) {
        $this->serviceUrl = rtrim($serviceUrl, '/');
        $this->configPath = $configPath;
        $this->timeout = $timeout;
        $this->failClosed = $failClosed;
        $this->loadQueryConfig();
    }
    
    /**
     * Load the query configuration from db_queries.json
     */
    private function loadQueryConfig(): void {
        if (!file_exists($this->configPath)) {
            throw new \RuntimeException("Query config file not found: {$this->configPath}");
        }
        
        $configContent = file_get_contents($this->configPath);
        $this->queryConfig = json_decode($configContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse query config: " . json_last_error_msg());
        }
    }
    
    /**
     * Check if a query-id is whitelisted
     * 
     * @param string $queryId The query identifier
     * @return bool True if whitelisted, false otherwise
     */
    public function isQueryWhitelisted(string $queryId): bool {
        return isset($this->queryConfig['queries'][$queryId]);
    }
    
    /**
     * Validate query parameters against the schema
     * 
     * @param string $queryId The query identifier
     * @param array $params The parameters to validate
     * @return bool True if valid, false otherwise
     */
    public function validateParameters(string $queryId, array $params): bool {
        if (!isset($this->queryConfig['queries'][$queryId])) {
            return false;
        }
        
        $queryDef = $this->queryConfig['queries'][$queryId];
        $paramDefs = $queryDef['parameters'] ?? [];
        
        // Check required parameters
        foreach ($paramDefs as $paramName => $paramDef) {
            if ($paramDef['required'] ?? false && !isset($params[$paramName])) {
                return false;
            }
        }
        
        // Validate parameter types and constraints
        foreach ($params as $paramName => $paramValue) {
            if (!isset($paramDefs[$paramName])) {
                return false; // Unknown parameter
            }
            
            $paramDef = $paramDefs[$paramName];
            $expectedType = $paramDef['type'] ?? 'string';
            
            // Type validation
            if (!$this->validateType($paramValue, $expectedType)) {
                return false;
            }
            
            // Pattern validation for strings
            if ($expectedType === 'string' && isset($paramDef['pattern'])) {
                if (!preg_match('/' . $paramDef['pattern'] . '/', $paramValue)) {
                    return false;
                }
            }
            
            // Min/max validation
            if (isset($paramDef['min']) && $paramValue < $paramDef['min']) {
                return false;
            }
            if (isset($paramDef['max']) && $paramValue > $paramDef['max']) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate a parameter type
     * 
     * @param mixed $value The value to validate
     * @param string $expectedType The expected type
     * @return bool True if type matches, false otherwise
     */
    private function validateType($value, string $expectedType): bool {
        switch ($expectedType) {
            case 'integer':
                return is_int($value);
            case 'string':
                return is_string($value);
            case 'number':
                return is_numeric($value);
            case 'boolean':
                return is_bool($value);
            default:
                return true;
        }
    }
    
    /**
     * Check if a query requires fail-closed behavior
     * 
     * @param string $queryId The query identifier
     * @return bool True if fail-closed required, false otherwise
     */
    public function requiresFailClosed(string $queryId): bool {
        if (!isset($this->queryConfig['queries'][$queryId])) {
            return $this->queryConfig['security']['default_fail_closed'] ?? true;
        }
        
        return $this->queryConfig['queries'][$queryId]['fail_closed'] ?? true;
    }
    
    /**
     * Execute a whitelisted query through the Rust service
     * 
     * @param string $queryId The query identifier
     * @param array $params Query parameters
     * @return array Query result or error
     * @throws \RuntimeException On fail-closed errors
     */
    public function executeQuery(string $queryId, array $params = []): array {
        // Validate query is whitelisted
        if (!$this->isQueryWhitelisted($queryId)) {
            if ($this->requiresFailClosed($queryId)) {
                throw new \RuntimeException("Fail-closed: Query not whitelisted: {$queryId}");
            }
            return [
                'success' => false,
                'error' => "Query not whitelisted: {$queryId}",
                'data' => null
            ];
        }
        
        // Validate parameters
        if (!$this->validateParameters($queryId, $params)) {
            if ($this->requiresFailClosed($queryId)) {
                throw new \RuntimeException("Fail-closed: Parameter validation failed for query: {$queryId}");
            }
            return [
                'success' => false,
                'error' => "Parameter validation failed for query: {$queryId}",
                'data' => null
            ];
        }
        
        // Get query definition
        $queryDef = $this->queryConfig['queries'][$queryId];
        
        // Prepare request
        $request = [
            'query_id' => $queryId,
            'params' => $params,
            'security_level' => $queryDef['security_level'] ?? 'read',
            'timeout_ms' => $queryDef['timeout_ms'] ?? 5000,
            'requires_transaction' => $queryDef['requires_transaction'] ?? false
        ];
        
        // Send to Rust service
        try {
            $response = $this->sendRequest('/query', $request);
            
            if ($response === null) {
                // Fail-closed fallback: Rust service unavailable
                return $this->handleFailClosedFallback($queryId, $params, "Rust service unavailable");
            }
            
            return $response;
            
        } catch (\Exception $e) {
            // Fail-closed fallback: Rust service error
            return $this->handleFailClosedFallback($queryId, $params, "Rust service error: " . $e->getMessage());
        }
    }
    
    /**
     * Handle fail-closed fallback when Rust service is unavailable
     * 
     * @param string $queryId The query identifier
     * @param array $params Query parameters
     * @param string $reason The reason for the fallback
     * @return array Fallback response
     * @throws \RuntimeException On fail-closed errors
     */
    private function handleFailClosedFallback(string $queryId, array $params, string $reason): array {
        if ($this->requiresFailClosed($queryId)) {
            throw new \RuntimeException("Fail-closed: {$reason} for query: {$queryId}");
        }
        
        // Log the fallback for monitoring
        error_log("EScript Query Bridge: Fail-closed fallback triggered for query {$queryId}. Reason: {$reason}");
        
        // Return safe fallback response
        return [
            'success' => false,
            'error' => "Service unavailable - fail-closed fallback activated",
            'data' => null,
            'fallback' => true,
            'query_id' => $queryId,
            'reason' => $reason
        ];
    }
    
    /**
     * Send a request to the Rust service
     * 
     * @param string $endpoint The API endpoint
     * @param array $data The request data
     * @return array|null Response data or null on failure
     */
    private function sendRequest(string $endpoint, array $data): ?array {
        $url = $this->serviceUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return null;
        }
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        return $decoded;
    }
    
    /**
     * Check if the Rust service is healthy
     * 
     * @return bool True if service is healthy, false otherwise
     */
    public function healthCheck(): bool {
        try {
            $response = $this->sendRequest('/health', []);
            return $response !== null && ($response['status'] ?? '') === 'healthy';
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get the security level for a query
     * 
     * @param string $queryId The query identifier
     * @return string|null Security level or null if not found
     */
    public function getSecurityLevel(string $queryId): ?string {
        if (!isset($this->queryConfig['queries'][$queryId])) {
            return null;
        }
        
        return $this->queryConfig['queries'][$queryId]['security_level'] ?? null;
    }
}
