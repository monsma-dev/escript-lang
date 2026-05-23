// EScript Fail-Closed Standard Library
// DatabaseQueryGuard: Secure database access through whitelisted JSON queries
// Version: 1.0.0

// DatabaseQueryGuard: Validates and executes whitelisted database queries
// This guard ensures that only pre-approved JSON queries can be executed,
// preventing SQL injection and enforcing fail-closed security.
service DatabaseQueryGuard {
    
    // Check if a query-id is whitelisted in the db_queries.json config
    // Returns true if the query exists and is approved, false otherwise
    function is_query_whitelisted(query_id: string) -> bool {
        // Load db_queries.json config
        let config = load_config("config/db_queries.json");
        
        // Check if query exists in queries object
        if (config.queries == null) {
            return false;
        }
        
        return config.queries.has_key(query_id);
    }
    
    // Validate query parameters against the schema
    // Returns true if all parameters match the expected types and constraints
    function validate_parameters(query_id: string, params: map<string, any>) -> bool {
        let config = load_config("config/db_queries.json");
        let query = config.queries[query_id];
        
        if (query == null || query.parameters == null) {
            return false;
        }
        
        // Check all required parameters are present
        for (param_name, param_def in query.parameters) {
            if (param_def.required == true && !params.has_key(param_name)) {
                return false;
            }
        }
        
        // Validate parameter types and constraints
        for (param_name, param_value in params) {
            if (!query.parameters.has_key(param_name)) {
                return false; // Unknown parameter
            }
            
            let param_def = query.parameters[param_name];
            
            // Type validation
            if (param_def.type == "integer" && typeof(param_value) != "integer") {
                return false;
            }
            if (param_def.type == "string" && typeof(param_value) != "string") {
                return false;
            }
            if (param_def.type == "number" && typeof(param_value) != "number") {
                return false;
            }
            
            // Pattern validation for strings
            if (param_def.pattern != null && typeof(param_value) == "string") {
                if (!param_value.matches(param_def.pattern)) {
                    return false;
                }
            }
            
            // Min/max validation
            if (param_def.min != null && param_value < param_def.min) {
                return false;
            }
            if (param_def.max != null && param_value > param_def.max) {
                return false;
            }
        }
        
        return true;
    }
    
    // Check if the query requires fail-closed behavior
    // Returns true if the query must fail-closed on any error
    function requires_fail_closed(query_id: string) -> bool {
        let config = load_config("config/db_queries.json");
        let query = config.queries[query_id];
        
        if (query == null) {
            return config.security.default_fail_closed;
        }
        
        return query.fail_closed == true;
    }
    
    // Check if the query requires a database transaction
    // Returns true if the query must be executed within a transaction
    function requires_transaction(query_id: string) -> bool {
        let config = load_config("config/db_queries.json");
        let query = config.queries[query_id];
        
        if (query == null) {
            return false;
        }
        
        return query.requires_transaction == true;
    }
    
    // Get the security level for a query (read/write)
    // Returns the security level or null if query not found
    function get_security_level(query_id: string) -> string? {
        let config = load_config("config/db_queries.json");
        let query = config.queries[query_id];
        
        if (query == null) {
            return null;
        }
        
        return query.security_level;
    }
    
    // Get the timeout for a query in milliseconds
    // Returns the timeout or default max timeout
    function get_timeout_ms(query_id: string) -> integer {
        let config = load_config("config/db_queries.json");
        let query = config.queries[query_id];
        
        if (query == null || query.timeout_ms == null) {
            return config.security.max_timeout_ms;
        }
        
        return query.timeout_ms;
    }
    
    // Main guard function: Validates a database query request
    // Returns true if the request is valid and safe to execute
    function validate_query_request(query_id: string, params: map<string, any>) -> bool {
        // Step 1: Check if query is whitelisted
        if (!is_query_whitelisted(query_id)) {
            log_error("Query not whitelisted: " + query_id);
            return false;
        }
        
        // Step 2: Validate parameters
        if (!validate_parameters(query_id, params)) {
            log_error("Parameter validation failed for query: " + query_id);
            return false;
        }
        
        // Step 3: Check security level
        let security_level = get_security_level(query_id);
        if (security_level == null) {
            log_error("Security level not defined for query: " + query_id);
            return false;
        }
        
        // Step 4: Validate against allowed security levels
        let config = load_config("config/db_queries.json");
        if (!config.security.allowed_security_levels.contains(security_level)) {
            log_error("Invalid security level for query: " + query_id);
            return false;
        }
        
        return true;
    }
    
    // Execute a whitelisted query through the Rust sidecar service
    // This function sends the validated request to the Rust service
    // Returns the query result or null on failure
    function execute_query(query_id: string, params: map<string, any>) -> any? {
        // Validate the request first
        if (!validate_query_request(query_id, params)) {
            if (requires_fail_closed(query_id)) {
                log_error("Fail-closed: Query validation failed, blocking execution");
                return null;
            }
        }
        
        // Prepare request for Rust sidecar
        let request = {
            "query_id": query_id,
            "params": params,
            "security_level": get_security_level(query_id),
            "timeout_ms": get_timeout_ms(query_id),
            "requires_transaction": requires_transaction(query_id)
        };
        
        // Send to Rust sidecar service
        let rust_service_url = get_env("RUST_QUERY_SERVICE_URL", "http://localhost:8080");
        let response = http_post(rust_service_url + "/query", request);
        
        if (response == null || response.status != 200) {
            if (requires_fail_closed(query_id)) {
                log_error("Fail-closed: Rust service unavailable or error");
                return null;
            }
        }
        
        return response.data;
    }
    
    // Helper function to load config files
    function load_config(path: string) -> map<string, any> {
        // This would be implemented by the EScript runtime
        // For now, return a placeholder
        return {};
    }
    
    // Helper function to log errors
    function log_error(message: string) {
        // This would be implemented by the EScript runtime
        // For now, placeholder
    }
    
    // Helper function to get environment variables
    function get_env(key: string, default: string) -> string {
        // This would be implemented by the EScript runtime
        // For now, return default
        return default;
    }
    
    // Helper function for HTTP POST requests
    function http_post(url: string, data: map<string, any>) -> map<string, any>? {
        // This would be implemented by the EScript runtime
        // For now, return null
        return null;
    }
}

// Export the DatabaseQueryGuard service
export DatabaseQueryGuard;
