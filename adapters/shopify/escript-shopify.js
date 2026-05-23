/**
 * EScript Security for Shopify
 * Fail-closed API security for Shopify apps using EScript JSON-stored queries
 * Version: 1.0.0
 */

const http = require('https');
const http2 = require('http2');

class EScriptShopifyAdapter {
    constructor(config = {}) {
        this.serviceUrl = config.serviceUrl || process.env.RUST_QUERY_SERVICE_URL || 'http://localhost:8080';
        this.configPath = config.configPath || __dirname + '/../../config/shopify_queries.json';
        this.enabled = config.enabled !== false;
        this.failClosed = config.failClosed !== false;
        
        this.queryConfig = null;
        this.loadQueryConfig();
        
        if (this.enabled) {
            this.interceptAdminAPI();
            this.interceptStorefrontAPI();
        }
    }
    
    /**
     * Load query configuration
     */
    loadQueryConfig() {
        try {
            const fs = require('fs');
            const configContent = fs.readFileSync(this.configPath, 'utf8');
            this.queryConfig = JSON.parse(configContent);
        } catch (error) {
            console.error('EScript Shopify: Failed to load query config:', error.message);
            this.enabled = false;
        }
    }
    
    /**
     * Intercept Shopify Admin API calls
     */
    interceptAdminAPI() {
        const originalHttpsRequest = https.request;
        
        https.request = (options, callback) => {
            if (typeof options === 'string' && options.includes('/admin/api/')) {
                const queryId = this.mapShopifyAdminQuery(options);
                
                if (queryId) {
                    return this.executeEScriptQuery(queryId, {}, callback);
                }
            }
            
            return originalHttpsRequest(options, callback);
        };
    }
    
    /**
     * Intercept Shopify Storefront API calls
     */
    interceptStorefrontAPI() {
        // Storefront API typically uses fetch in modern Shopify apps
        if (typeof fetch !== 'undefined') {
            const originalFetch = global.fetch;
            
            global.fetch = async (url, options) => {
                if (url.includes('/api/')) {
                    const queryId = this.mapShopifyStorefrontQuery(url, options);
                    
                    if (queryId) {
                        const result = await this.executeEScriptQueryAsync(queryId, {});
                        
                        if (result.success) {
                            return {
                                ok: true,
                                json: async () => result.data
                            };
                        }
                    }
                }
                
                return originalFetch(url, options);
            };
        }
    }
    
    /**
     * Map Shopify Admin API call to query-id
     */
    mapShopifyAdminQuery(url) {
        const patterns = {
            '/admin/api/.*products.json': 'shopify.get_products',
            '/admin/api/.*orders.json': 'shopify.get_orders',
            '/admin/api/.*customers.json': 'shopify.get_customers',
            '/admin/api/.*products/.*variants.json': 'shopify.get_product_variants',
            '/admin/api/.*inventory_levels.json': 'shopify.get_inventory_levels'
        };
        
        for (const pattern in patterns) {
            if (new RegExp(pattern).test(url)) {
                return patterns[pattern];
            }
        }
        
        return null;
    }
    
    /**
     * Map Shopify Storefront API call to query-id
     */
    mapShopifyStorefrontQuery(url, options) {
        const patterns = {
            'productByHandle': 'shopify.get_product_by_handle',
            'collectionByHandle': 'shopify.get_collection_by_handle',
            'customer': 'shopify.get_customer',
            'checkout': 'shopify.get_checkout'
        };
        
        // Parse GraphQL query body
        if (options && options.body) {
            for (const pattern in patterns) {
                if (options.body.includes(pattern)) {
                    return patterns[pattern];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Execute EScript query (callback style)
     */
    executeEScriptQuery(queryId, params, callback) {
        const request = {
            query_id: queryId,
            params: params,
            security_level: 'read',
            timeout_ms: 5000,
            requires_transaction: false
        };
        
        const url = new URL(this.serviceUrl + '/query');
        
        const req = http.request(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        }, (res) => {
            let data = '';
            
            res.on('data', (chunk) => {
                data += chunk;
            });
            
            res.on('end', () => {
                try {
                    const response = JSON.parse(data);
                    callback(null, response);
                } catch (error) {
                    callback(error, null);
                }
            });
        });
        
        req.on('error', (error) => {
            if (this.failClosed) {
                console.error('EScript Shopify: Fail-closed - Service unavailable');
                callback(new Error('Service unavailable'), null);
            } else {
                callback(error, null);
            }
        });
        
        req.write(JSON.stringify(request));
        req.end();
    }
    
    /**
     * Execute EScript query (async style)
     */
    async executeEScriptQueryAsync(queryId, params) {
        const request = {
            query_id: queryId,
            params: params,
            security_level: 'read',
            timeout_ms: 5000,
            requires_transaction: false
        };
        
        try {
            const response = await this.httpPost(this.serviceUrl + '/query', request);
            return response;
        } catch (error) {
            if (this.failClosed) {
                console.error('EScript Shopify: Fail-closed - Service unavailable');
                throw new Error('Service unavailable');
            }
            throw error;
        }
    }
    
    /**
     * HTTP POST helper
     */
    httpPost(url, data) {
        return new Promise((resolve, reject) => {
            const urlObj = new URL(url);
            const protocol = urlObj.protocol === 'https:' ? https : http2;
            
            const req = protocol.request(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            }, (res) => {
                let responseData = '';
                
                res.on('data', (chunk) => {
                    responseData += chunk;
                });
                
                res.on('end', () => {
                    try {
                        const response = JSON.parse(responseData);
                        resolve(response);
                    } catch (error) {
                        reject(error);
                    }
                });
            });
            
            req.on('error', reject);
            req.write(JSON.stringify(data));
            req.end();
        });
    }
    
    /**
     * Check service health
     */
    async healthCheck() {
        try {
            const response = await this.httpGet(this.serviceUrl + '/health');
            return response.status === 'healthy';
        } catch (error) {
            return false;
        }
    }
    
    /**
     * HTTP GET helper
     */
    httpGet(url) {
        return new Promise((resolve, reject) => {
            const urlObj = new URL(url);
            const protocol = urlObj.protocol === 'https:' ? https : http2;
            
            const req = protocol.request(url, (res) => {
                let responseData = '';
                
                res.on('data', (chunk) => {
                    responseData += chunk;
                });
                
                res.on('end', () => {
                    try {
                        const response = JSON.parse(responseData);
                        resolve(response);
                    } catch (error) {
                        reject(error);
                    }
                });
            });
            
            req.on('error', reject);
            req.end();
        });
    }
}

// Export for use in Shopify apps
module.exports = EScriptShopifyAdapter;

// Auto-initialize if running as standalone
if (require.main === module) {
    const adapter = new EScriptShopifyAdapter();
    console.log('EScript Shopify Adapter initialized');
}
