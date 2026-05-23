<?php
/**
 * Plugin Name: EScript Security for WordPress
 * Plugin URI: https://github.com/monsma-dev/escript-lang
 * Description: Fail-closed database security for WordPress using EScript JSON-stored queries
 * Version: 1.0.0
 * Author: EScript Team
 * Author URI: https://escript-lang.org
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: escript-security
 * Requires PHP: 7.4
 * Requires at least: 5.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Autoload the PHP bridge
require_once __DIR__ . '/../../php-bridge/RustQueryBridge.php';

use EScript\RustQueryBridge;

class EScriptWordPress {
    private static $instance = null;
    private $queryBridge;
    private $configPath;
    private $serviceUrl;
    private $enabled;
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->configPath = __DIR__ . '/../../config/wp_queries.json';
        $this->serviceUrl = get_option('escript_service_url', 'http://localhost:8080');
        $this->enabled = get_option('escript_enabled', true);
        
        $this->initQueryBridge();
        $this->registerHooks();
        $this->initAdmin();
    }
    
    /**
     * Initialize query bridge
     */
    private function initQueryBridge() {
        try {
            $this->queryBridge = new RustQueryBridge(
                $this->serviceUrl,
                $this->configPath,
                5, // timeout
                true // fail-closed
            );
        } catch (Exception $e) {
            error_log('EScript WordPress: Failed to initialize query bridge: ' . $e->getMessage());
            $this->enabled = false;
        }
    }
    
    /**
     * Register WordPress hooks
     */
    private function registerHooks() {
        if (!$this->enabled) {
            return;
        }
        
        // Hook into $wpdb queries
        add_filter('query', [$this, 'interceptQuery'], 10, 1);
        
        // Hook into WP_Query
        add_action('pre_get_posts', [$this, 'interceptPostsQuery'], 10, 1);
        
        // Hook into get_option
        add_filter('pre_option', [$this, 'interceptOption'], 10, 2);
        
        // Hook into get_post_meta
        add_filter('get_post_metadata', [$this, 'interceptPostMeta'], 10, 4);
    }
    
    /**
     * Intercept database queries
     */
    public function interceptQuery($query) {
        if (!$this->enabled || !$this->queryBridge) {
            return $query;
        }
        
        // Only intercept SELECT queries for now
        if (!preg_match('/^\s*SELECT/i', $query)) {
            return $query;
        }
        
        // Map to whitelisted query
        $queryId = $this->mapToQueryId($query);
        if (!$queryId) {
            return $query; // Fallback to original
        }
        
        try {
            // Execute through EScript
            $result = $this->queryBridge->executeQuery($queryId, []);
            
            if ($result['success']) {
                return $this->formatAsWordPressResult($result['data']);
            }
        } catch (Exception $e) {
            error_log('EScript WordPress: Query execution failed: ' . $e->getMessage());
        }
        
        return $query; // Fallback on error
    }
    
    /**
     * Intercept WP_Query
     */
    public function interceptPostsQuery($query) {
        if (!$this->enabled || !$this->queryBridge || is_admin()) {
            return;
        }
        
        if (!$query->is_main_query()) {
            return;
        }
        
        $postType = $query->get('post_type', 'post');
        $postStatus = $query->get('post_status', 'publish');
        $postsPerPage = $query->get('posts_per_page', get_option('posts_per_page'));
        
        try {
            $result = $this->queryBridge->executeQuery('wp.get_posts', [
                'post_type' => $postType,
                'post_status' => $postStatus,
                'posts_per_page' => $postsPerPage
            ]);
            
            if ($result['success']) {
                // Modify query to use EScript results
                $query->set('post__in', wp_list_pluck($result['data'], 'ID'));
            }
        } catch (Exception $e) {
            error_log('EScript WordPress: Posts query failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Intercept get_option
     */
    public function interceptOption($value, $option) {
        if (!$this->enabled || !$this->queryBridge) {
            return $value;
        }
        
        try {
            $result = $this->queryBridge->executeQuery('wp.get_option', [
                'option_name' => $option
            ]);
            
            if ($result['success'] && !empty($result['data'])) {
                return maybe_unserialize($result['data'][0]['option_value']);
            }
        } catch (Exception $e) {
            error_log('EScript WordPress: Option query failed: ' . $e->getMessage());
        }
        
        return $value;
    }
    
    /**
     * Intercept get_post_meta
     */
    public function interceptPostMeta($value, $object_id, $meta_key, $single) {
        if (!$this->enabled || !$this->queryBridge) {
            return $value;
        }
        
        try {
            $result = $this->queryBridge->executeQuery('wp.get_post_meta', [
                'post_id' => $object_id,
                'meta_key' => $meta_key
            ]);
            
            if ($result['success'] && !empty($result['data'])) {
                $metaValues = wp_list_pluck($result['data'], 'meta_value');
                return $single ? $metaValues[0] : $metaValues;
            }
        } catch (Exception $e) {
            error_log('EScript WordPress: Post meta query failed: ' . $e->getMessage());
        }
        
        return $value;
    }
    
    /**
     * Map SQL query to query-id
     */
    private function mapToQueryId($sql): ?string {
        $patterns = [
            'SELECT.*FROM wp_posts WHERE post_type.*post_status' => 'wp.get_posts',
            'SELECT.*FROM wp_options WHERE option_name' => 'wp.get_option',
            'SELECT.*FROM wp_postmeta WHERE post_id' => 'wp.get_post_meta',
            'SELECT.*FROM wp_users WHERE ID' => 'wp.get_user',
            'SELECT.*FROM wp_terms WHERE term_id' => 'wp.get_term',
        ];
        
        foreach ($patterns as $pattern => $queryId) {
            if (preg_match('/' . $pattern . '/i', $sql)) {
                return $queryId;
            }
        }
        
        return null;
    }
    
    /**
     * Format EScript result as WordPress result
     */
    private function formatAsWordPressResult($data) {
        if (empty($data)) {
            return '';
        }
        
        // For now, return the data as-is
        // In production, this would format according to WordPress expectations
        return $data;
    }
    
    /**
     * Initialize admin interface
     */
    private function initAdmin() {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }
    
    /**
     * Add admin menu
     */
    public function addAdminMenu() {
        add_options_page(
            'EScript Security',
            'EScript Security',
            'manage_options',
            'escript-security',
            [$this, 'renderAdminPage']
        );
    }
    
    /**
     * Register settings
     */
    public function registerSettings() {
        register_setting('escript_settings', 'escript_enabled');
        register_setting('escript_settings', 'escript_service_url');
        register_setting('escript_settings', 'escript_fail_closed');
    }
    
    /**
     * Render admin page
     */
    public function renderAdminPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $enabled = get_option('escript_enabled', true);
        $serviceUrl = get_option('escript_service_url', 'http://localhost:8080');
        $failClosed = get_option('escript_fail_closed', true);
        $healthStatus = $this->checkServiceHealth();
        
        ?>
        <div class="wrap">
            <h1>EScript Security for WordPress</h1>
            <form method="post" action="options.php">
                <?php settings_fields('escript_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable EScript Security</th>
                        <td>
                            <input type="checkbox" name="escript_enabled" value="1" <?php checked($enabled, 1); ?>>
                            <label>Enable fail-closed database security</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rust Service URL</th>
                        <td>
                            <input type="text" name="escript_service_url" value="<?php echo esc_attr($serviceUrl); ?>" class="regular-text">
                            <p class="description">URL of the EScript Rust query service</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Fail-Closed Mode</th>
                        <td>
                            <input type="checkbox" name="escript_fail_closed" value="1" <?php checked($failClosed, 1); ?>>
                            <label>Block queries on service failure</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Service Health</th>
                        <td>
                            <span class="<?php echo $healthStatus ? 'dashicons dashicons-yes' : 'dashicons dashicons-no'; ?>"></span>
                            <?php echo $healthStatus ? 'Service is healthy' : 'Service is unavailable'; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Check Rust service health
     */
    private function checkServiceHealth(): bool {
        if (!$this->queryBridge) {
            return false;
        }
        
        return $this->queryBridge->healthCheck();
    }
}

// Initialize plugin
add_action('plugins_loaded', function() {
    EScriptWordPress::getInstance();
});

// Activation hook
register_activation_hook(__FILE__, function() {
    add_option('escript_enabled', true);
    add_option('escript_service_url', 'http://localhost:8080');
    add_option('escript_fail_closed', true);
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Cleanup if needed
});
