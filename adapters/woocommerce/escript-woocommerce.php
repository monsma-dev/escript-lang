<?php
/**
 * Plugin Name: EScript Security for WooCommerce
 * Plugin URI: https://github.com/monsma-dev/escript-lang
 * Description: Fail-closed database security for WooCommerce using EScript JSON-stored queries
 * Version: 1.0.0
 * Author: EScript Team
 * Author URI: https://escript-lang.org
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: escript-woocommerce
 * Requires PHP: 7.4
 * Requires at least: 5.0
 * WC requires at least: 5.0
 * WC requires up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Autoload the PHP bridge
require_once __DIR__ . '/../../php-bridge/RustQueryBridge.php';

use EScript\RustQueryBridge;

class EScriptWooCommerce {
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
        $this->configPath = __DIR__ . '/../../config/woo_queries.json';
        $this->serviceUrl = get_option('escript_woo_service_url', 'http://localhost:8080');
        $this->enabled = get_option('escript_woo_enabled', true);
        
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
            error_log('EScript WooCommerce: Failed to initialize query bridge: ' . $e->getMessage());
            $this->enabled = false;
        }
    }
    
    /**
     * Register WooCommerce hooks
     */
    private function registerHooks() {
        if (!$this->enabled) {
            return;
        }
        
        // Product price queries
        add_filter('woocommerce_product_get_price', [$this, 'interceptProductPrice'], 10, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'interceptProductPrice'], 10, 2);
        
        // Product stock queries
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'interceptStockQuantity'], 10, 2);
        
        // Order queries
        add_filter('woocommerce_orders_list_table_prepare_items_query', [$this, 'interceptOrdersQuery'], 10, 2);
        
        // Customer queries
        add_filter('woocommerce_customer_get_total_spent', [$this, 'interceptCustomerSpent'], 10, 2);
        
        // Coupon queries
        add_filter('woocommerce_coupon_get_discount_amount', [$this, 'interceptCouponDiscount'], 10, 5);
    }
    
    /**
     * Intercept product price queries
     */
    public function interceptProductPrice($price, $product) {
        if (!$this->enabled || !$this->queryBridge) {
            return $price;
        }
        
        try {
            $result = $this->queryBridge->executeQuery('woo.get_product_price', [
                'product_id' => $product->get_id(),
                'user_id' => get_current_user_id()
            ]);
            
            if ($result['success'] && isset($result['data']['price'])) {
                return $result['data']['price'];
            }
        } catch (Exception $e) {
            error_log('EScript WooCommerce: Product price query failed: ' . $e->getMessage());
        }
        
        return $price;
    }
    
    /**
     * Intercept stock quantity queries
     */
    public function interceptStockQuantity($quantity, $product) {
        if (!$this->enabled || !$this->queryBridge) {
            return $quantity;
        }
        
        try {
            $result = $this->queryBridge->executeQuery('woo.get_product_stock', [
                'product_id' => $product->get_id()
            ]);
            
            if ($result['success'] && isset($result['data']['stock_quantity'])) {
                return $result['data']['stock_quantity'];
            }
        } catch (Exception $e) {
            error_log('EScript WooCommerce: Stock query failed: ' . $e->getMessage());
        }
        
        return $quantity;
    }
    
    /**
     * Intercept orders query
     */
    public function interceptOrdersQuery($query_vars, $query) {
        if (!$this->enabled || !$this->queryBridge) {
            return $query_vars;
        }
        
        // Only intercept for shop_order post type
        if (!isset($query_vars['post_type']) || $query_vars['post_type'] !== 'shop_order') {
            return $query_vars;
        }
        
        try {
            $userId = isset($query_vars['author']) ? $query_vars['author'] : get_current_user_id();
            $status = isset($query_vars['post_status']) ? $query_vars['post_status'] : null;
            
            $result = $this->queryBridge->executeQuery('woo.get_orders', [
                'user_id' => $userId,
                'status' => $status
            ]);
            
            if ($result['success'] && !empty($result['data'])) {
                $query_vars['post__in'] = wp_list_pluck($result['data'], 'ID');
            }
        } catch (Exception $e) {
            error_log('EScript WooCommerce: Orders query failed: ' . $e->getMessage());
        }
        
        return $query_vars;
    }
    
    /**
     * Intercept customer total spent query
     */
    public function interceptCustomerSpent($total_spent, $customer_id) {
        if (!$this->enabled || !$this->queryBridge) {
            return $total_spent;
        }
        
        try {
            $result = $this->queryBridge->executeQuery('woo.get_customer_spent', [
                'customer_id' => $customer_id
            ]);
            
            if ($result['success'] && isset($result['data']['total_spent'])) {
                return $result['data']['total_spent'];
            }
        } catch (Exception $e) {
            error_log('EScript WooCommerce: Customer spent query failed: ' . $e->getMessage());
        }
        
        return $total_spent;
    }
    
    /**
     * Intercept coupon discount queries
     */
    public function interceptCouponDiscount($discount, $discounting_amount, $cart_item, $single, $coupon) {
        if (!$this->enabled || !$this->queryBridge) {
            return $discount;
        }
        
        try {
            $result = $this->queryBridge->executeQuery('woo.get_coupon_discount', [
                'coupon_code' => $coupon->get_code(),
                'cart_total' => WC()->cart->get_total('edit'),
                'user_id' => get_current_user_id()
            ]);
            
            if ($result['success'] && isset($result['data']['discount_amount'])) {
                return $result['data']['discount_amount'];
            }
        } catch (Exception $e) {
            error_log('EScript WooCommerce: Coupon discount query failed: ' . $e->getMessage());
        }
        
        return $discount;
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
        add_submenu_page(
            'woocommerce',
            'EScript Security',
            'EScript Security',
            'manage_woocommerce',
            'escript-woocommerce',
            [$this, 'renderAdminPage']
        );
    }
    
    /**
     * Register settings
     */
    public function registerSettings() {
        register_setting('escript_woo_settings', 'escript_woo_enabled');
        register_setting('escript_woo_settings', 'escript_woo_service_url');
        register_setting('escript_woo_settings', 'escript_woo_fail_closed');
    }
    
    /**
     * Render admin page
     */
    public function renderAdminPage() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $enabled = get_option('escript_woo_enabled', true);
        $serviceUrl = get_option('escript_woo_service_url', 'http://localhost:8080');
        $failClosed = get_option('escript_woo_fail_closed', true);
        $healthStatus = $this->checkServiceHealth();
        
        ?>
        <div class="wrap">
            <h1>EScript Security for WooCommerce</h1>
            <form method="post" action="options.php">
                <?php settings_fields('escript_woo_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable EScript Security</th>
                        <td>
                            <input type="checkbox" name="escript_woo_enabled" value="1" <?php checked($enabled, 1); ?>>
                            <label>Enable fail-closed database security for WooCommerce</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rust Service URL</th>
                        <td>
                            <input type="text" name="escript_woo_service_url" value="<?php echo esc_attr($serviceUrl); ?>" class="regular-text">
                            <p class="description">URL of the EScript Rust query service</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Fail-Closed Mode</th>
                        <td>
                            <input type="checkbox" name="escript_woo_fail_closed" value="1" <?php checked($failClosed, 1); ?>>
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
    EScriptWooCommerce::getInstance();
});

// Activation hook
register_activation_hook(__FILE__, function() {
    add_option('escript_woo_enabled', true);
    add_option('escript_woo_service_url', 'http://localhost:8080');
    add_option('escript_woo_fail_closed', true);
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Cleanup if needed
});
