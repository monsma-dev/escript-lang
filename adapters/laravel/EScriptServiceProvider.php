<?php

namespace EScript\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\QueryExecuted;

class EScriptServiceProvider extends ServiceProvider {
    private $queryBridge;
    private $enabled;
    
    /**
     * Register services
     */
    public function register() {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/laravel_queries.json',
            'escript'
        );
        
        $this->app->singleton('escript.query_bridge', function ($app) {
            return new \EScript\RustQueryBridge(
                config('escript.service_url', 'http://localhost:8080'),
                __DIR__ . '/../../config/laravel_queries.json',
                config('escript.timeout', 5),
                config('escript.fail_closed', true)
            );
        });
    }
    
    /**
     * Bootstrap services
     */
    public function boot() {
        $this->publishes([
            __DIR__ . '/../../config/laravel_queries.json' => config_path('escript_queries.json'),
        ], 'escript-config');
        
        $this->enabled = config('escript.enabled', true);
        
        if ($this->enabled) {
            $this->registerQueryListener();
        }
    }
    
    /**
     * Register database query listener
     */
    private function registerQueryListener() {
        DB::listen(function (QueryExecuted $query) {
            $this->interceptQuery($query);
        });
    }
    
    /**
     * Intercept Laravel queries
     */
    private function interceptQuery($query) {
        $sql = $query->sql;
        $bindings = $query->bindings;
        
        // Only intercept SELECT queries for now
        if (!preg_match('/^\s*SELECT/i', $sql)) {
            return;
        }
        
        // Map Laravel query to EScript query-id
        $queryId = $this->mapLaravelQuery($sql, $bindings);
        
        if (!$queryId) {
            return;
        }
        
        try {
            $bridge = $this->app->make('escript.query_bridge');
            $result = $bridge->executeQuery($queryId, $this->formatBindings($bindings));
            
            if ($result['success']) {
                // In production, this would modify the query result
                // For now, just log that the query was intercepted
                \Log::info('EScript: Intercepted query ' . $queryId);
            }
        } catch (\Exception $e) {
            \Log::error('EScript: Query interception failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Map Laravel query to EScript query-id
     */
    private function mapLaravelQuery($sql, $bindings): ?string {
        $patterns = [
            'SELECT.*FROM users WHERE' => 'laravel.get_user',
            'SELECT.*FROM posts WHERE' => 'laravel.get_post',
            'SELECT.*FROM products WHERE' => 'laravel.get_product',
            'SELECT.*FROM orders WHERE' => 'laravel.get_order',
        ];
        
        foreach ($patterns as $pattern => $queryId) {
            if (preg_match('/' . $pattern . '/i', $sql)) {
                return $queryId;
            }
        }
        
        return null;
    }
    
    /**
     * Format Laravel bindings for EScript
     */
    private function formatBindings($bindings): array {
        $formatted = [];
        
        foreach ($bindings as $key => $value) {
            if (is_numeric($key)) {
                // Positional binding, use index
                $formatted['param_' . $key] = $value;
            } else {
                // Named binding
                $formatted[$key] = $value;
            }
        }
        
        return $formatted;
    }
}
