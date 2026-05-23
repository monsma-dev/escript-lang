// EScript IR Validator
// Validates EScript Intermediate Representation (IR) for security compliance
// Version: 1.0.0

service EScriptIrValidator {
    
    // Validate that an IR node references only whitelisted query-ids
    // Returns true if all query-id references are valid, false otherwise
    function validate_query_references(ir_node: map<string, any>) -> bool {
        // Check if the IR node contains database query operations
        if (ir_node.type == "database_query") {
            let query_id = ir_node.query_id;
            
            // Validate the query-id against whitelist
            if (!is_query_id_whitelisted(query_id)) {
                log_error("Invalid query-id in IR: " + query_id);
                return false;
            }
            
            // Validate parameters if present
            if (ir_node.params != null) {
                if (!validate_query_parameters(query_id, ir_node.params)) {
                    log_error("Invalid parameters for query-id: " + query_id);
                    return false;
                }
            }
        }
        
        // Recursively validate child nodes
        if (ir_node.children != null) {
            for (child in ir_node.children) {
                if (!validate_query_references(child)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    // Check if a query-id exists in the whitelist
    function is_query_id_whitelisted(query_id: string) -> bool {
        let config = load_config("config/db_queries.json");
        
        if (config.queries == null) {
            return false;
        }
        
        return config.queries.has_key(query_id);
    }
    
    // Validate query parameters against the schema
    function validate_query_parameters(query_id: string, params: map<string, any>) -> bool {
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
    
    // Validate the entire IR for security compliance
    // This is the main entry point for IR validation
    function validate_ir(ir: map<string, any>) -> bool {
        // Check for database query operations
        if (!validate_query_references(ir)) {
            return false;
        }
        
        // Additional security checks can be added here
        // e.g., check for unsafe operations, validate resource access, etc.
        
        return true;
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
}

// Export the EScriptIrValidator service
export EScriptIrValidator;
