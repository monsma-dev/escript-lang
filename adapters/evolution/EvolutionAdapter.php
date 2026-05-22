#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * EScript Evolution Adapter
 *
 * Translates EScript IR into Evolution framework artifacts:
 *   - config/gates.json           (gate definitions for AnalysisPool)
 *   - src/Gates/*.php             (PHP gate classes)
 *   - config/routes/escript.json  (route registrations)
 *   - src/Services/*.php          (service stubs with DI)
 *
 * This adapter is designed for the private Evolution framework.
 * It reads the public IR and emits private framework code.
 * No private code leaks into the public repo.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php EvolutionAdapter.php <ir-file.json> [--output=dir]\n");
    exit(1);
}

$irFile = $argv[1];
$outputDir = 'evolution-output';

for ($i = 2; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--output=')) {
        $outputDir = substr($argv[$i], 9);
    }
}

if (!file_exists($irFile)) {
    fwrite(STDERR, "IR file not found: {$irFile}\n");
    exit(1);
}

$ir = json_decode(file_get_contents($irFile), true);
if ($ir === null) {
    fwrite(STDERR, "Invalid JSON in: {$irFile}\n");
    exit(1);
}

// Validate IR version
$version = $ir['version'] ?? '';
if (!preg_match('/^1\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "Unsupported IR version: {$version}. Expected 1.x.y\n");
    exit(1);
}

echo "EScript Evolution Adapter v0.1.0\n";
echo "IR source: {$ir['source']} (v{$version})\n";
echo str_repeat('─', 50) . "\n";

// ─── Generate config/gates.json ──────────────────────────────────────────────

$guards = $ir['guards'] ?? [];
if (!empty($guards)) {
    $gateConfig = [
        '_generated' => true,
        '_source' => $ir['source'],
        '_ir_version' => $version,
        'gates' => [],
    ];

    foreach ($guards as $guard) {
        $gate = [
            'name' => $guard['name'],
            'tier' => $guard['tier'],
            'fail_mode' => $guard['fail_mode'],
            'enabled' => true,
        ];

        if (isset($guard['input_type']))  $gate['input_type']  = $guard['input_type'];
        if (isset($guard['output_type'])) $gate['output_type'] = $guard['output_type'];
        if (isset($guard['ceiling']))     $gate['ceiling']     = $guard['ceiling'];

        // Reactive guard config
        if (isset($guard['trigger'])) {
            $gate['reactive'] = true;
            $gate['trigger_event'] = $guard['trigger']['on'] ?? null;
        }
        if (isset($guard['action'])) {
            $gate['dispatch_action'] = $guard['action']['dispatch'] ?? null;
        }
        if (isset($guard['conditions'])) {
            $gate['conditions'] = $guard['conditions'];
        }
        if (isset($guard['unsafe_acknowledged'])) {
            $gate['unsafe_acknowledged'] = true;
        }

        $gateConfig['gates'][] = $gate;
    }

    writeFile("{$outputDir}/config/gates.json", json_encode($gateConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

// ─── Generate PHP Gate Classes ───────────────────────────────────────────────

foreach ($guards as $guard) {
    $name = $guard['name'];
    $input = $guard['input_type'] ?? 'mixed';
    $output = $guard['output_type'] ?? 'bool';
    $failMode = $guard['fail_mode'];
    $tier = $guard['tier'];
    $isReactive = isset($guard['trigger']);

    $code = "<?php\n\n";
    $code .= "declare(strict_types=1);\n\n";
    $code .= "namespace App\\Gates;\n\n";
    $code .= "use App\\Core\\Gate\\AbstractGate;\n";
    if ($isReactive) {
        $code .= "use App\\Core\\Gate\\ReactiveGateInterface;\n";
        $code .= "use App\\Core\\Events\\GateEvent;\n";
    }
    $code .= "\n";

    $interfaces = $isReactive ? ' implements ReactiveGateInterface' : '';
    $code .= "/**\n";
    $code .= " * Generated from EScript IR: {$ir['source']}\n";
    $code .= " * Tier: {$tier} | Fail mode: {$failMode}\n";
    if ($isReactive) {
        $triggerOn = $guard['trigger']['on'] ?? 'unknown';
        $code .= " * Reactive: triggers on '{$triggerOn}'\n";
    }
    $code .= " */\n";
    $code .= "final class {$name} extends AbstractGate{$interfaces}\n{\n";

    // Constants
    $code .= "    public const FAIL_MODE = '{$failMode}';\n";
    $code .= "    public const TIER = '{$tier}';\n";
    if (isset($guard['ceiling'])) {
        $code .= "    public const CEILING = {$guard['ceiling']};\n";
    }
    $code .= "\n";

    // evaluate() method
    $code .= "    public function evaluate({$input} \$input): {$output}\n";
    $code .= "    {\n";
    if ($failMode === 'closed') {
        $code .= "        // FAIL-CLOSED: deny by default, allow only on explicit pass\n";
        $code .= "        // TODO: Implement gate logic\n";
        $code .= "        return \$this->deny('Not implemented — fail-closed default');\n";
    } else {
        $code .= "        // WARNING: FAIL-OPEN gate — @unsafe acknowledged\n";
        $code .= "        // TODO: Implement gate logic\n";
        $code .= "        return \$this->allow();\n";
    }
    $code .= "    }\n";

    // Reactive methods
    if ($isReactive) {
        $triggerOn = $guard['trigger']['on'] ?? 'unknown';
        $dispatch = $guard['action']['dispatch'] ?? null;

        $code .= "\n    public function triggerEvent(): string\n";
        $code .= "    {\n";
        $code .= "        return '{$triggerOn}';\n";
        $code .= "    }\n";

        if ($dispatch) {
            $code .= "\n    public function dispatchAction(): string\n";
            $code .= "    {\n";
            $code .= "        return '{$dispatch}';\n";
            $code .= "    }\n";
        }

        if (isset($guard['conditions'])) {
            $code .= "\n    public function shouldActivate(GateEvent \$event): bool\n";
            $code .= "    {\n";
            foreach ($guard['conditions'] as $key => $value) {
                $val = var_export($value, true);
                $code .= "        if (\$event->get('{$key}') !== {$val}) return false;\n";
            }
            $code .= "        return true;\n";
            $code .= "    }\n";
        }
    }

    $code .= "}\n";

    writeFile("{$outputDir}/src/Gates/{$name}.php", $code);
}

// ─── Generate config/routes/escript.json ─────────────────────────────────────

$routes = $ir['routes'] ?? [];
if (!empty($routes)) {
    $routeConfig = [];

    foreach ($routes as $route) {
        if (($route['tier'] ?? '') !== 'php') continue;

        $entry = [
            'method' => $route['method'],
            'path' => $route['path'],
            'controller' => $route['target']['controller'] ?? '',
            'action' => $route['target']['action'] ?? 'index',
        ];

        if (isset($route['middleware'])) {
            $entry['middleware'] = array_map(function (string $m): string {
                return match ($m) {
                    'AuthMiddleware' => 'auth',
                    'AdminAuthMiddleware' => 'admin_auth',
                    'RateLimitMiddleware' => 'rate_limit',
                    default => $m,
                };
            }, $route['middleware']);
        }

        if (isset($route['auth'])) $entry['auth'] = $route['auth'];
        if (isset($route['rate_limit'])) $entry['rate_limit'] = $route['rate_limit'];
        if (isset($route['dto'])) $entry['dto'] = $route['dto'];

        $routeConfig[] = $entry;
    }

    writeFile(
        "{$outputDir}/config/routes/escript.json",
        json_encode($routeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

// ─── Generate Services ───────────────────────────────────────────────────────

foreach ($ir['services'] ?? [] as $service) {
    if (($service['tier'] ?? 'php') !== 'php') continue;

    $name = $service['name'];
    $injects = $service['injects'] ?? [];
    $methods = $service['methods'] ?? [];
    $guards = $service['guards'] ?? [];
    $implements = $service['implements'] ?? [];

    $code = "<?php\n\n";
    $code .= "declare(strict_types=1);\n\n";
    $code .= "namespace App\\Services;\n\n";

    if (!empty($guards)) {
        $code .= "use App\\Core\\Gate\\GateRunner;\n";
    }

    $implementsStr = '';
    if (!empty($implements)) {
        $implementsStr = ' implements ' . implode(', ', $implements);
    }

    $code .= "\nclass {$name}{$implementsStr}\n{\n";

    // Constructor
    $ctorParams = [];
    foreach ($injects as $inject) {
        $ctorParams[] = "        private readonly {$inject['type']} \${$inject['name']}";
    }
    if (!empty($guards)) {
        $ctorParams[] = "        private readonly GateRunner \$gates";
    }

    if (!empty($ctorParams)) {
        $code .= "    public function __construct(\n";
        $code .= implode(",\n", $ctorParams) . ",\n";
        $code .= "    ) {}\n\n";
    }

    // Methods
    foreach ($methods as $method) {
        $vis = $method['visibility'] ?? 'public';
        $params = [];
        foreach ($method['params'] ?? [] as $p) {
            $param = "{$p['type']} \${$p['name']}";
            if (isset($p['default'])) {
                $param .= ' = ' . var_export($p['default'], true);
            }
            $params[] = $param;
        }
        $paramStr = implode(', ', $params);

        $returnType = '';
        if (isset($method['return_type'])) {
            $returnType = ': ' . mapReturnType($method['return_type']);
        }

        $code .= "    {$vis} function {$method['name']}({$paramStr}){$returnType}\n";
        $code .= "    {\n";

        // Insert gate checks if service has guards
        if (!empty($guards)) {
            foreach ($guards as $guardName) {
                $code .= "        \$this->gates->run('{$guardName}', func_get_args());\n";
            }
            $code .= "\n";
        }

        $code .= "        // TODO: Implement {$method['name']}\n";
        $code .= "        throw new \\RuntimeException('Not implemented');\n";
        $code .= "    }\n\n";
    }

    $code .= "}\n";

    writeFile("{$outputDir}/src/Services/{$name}.php", $code);
}

// ─── Generate gate sync manifest ─────────────────────────────────────────────

$manifest = [
    'ir_version' => $version,
    'ir_source' => $ir['source'],
    'generated_at' => date('c'),
    'guard_count' => count($ir['guards'] ?? []),
    'route_count' => count($ir['routes'] ?? []),
    'service_count' => count($ir['services'] ?? []),
    'guard_names' => array_map(fn($g) => $g['name'], $ir['guards'] ?? []),
    'checksums' => [
        'ir_hash' => md5(json_encode($ir)),
    ],
];

writeFile("{$outputDir}/.escript-sync-manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo str_repeat('─', 50) . "\n";
echo "Evolution adapter complete.\n";

// ─── Helpers ─────────────────────────────────────────────────────────────────

function writeFile(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
    echo "  → {$path}\n";
}

function mapReturnType(array $rt): string
{
    return match ($rt['kind'] ?? 'simple') {
        'simple' => $rt['type'] ?? 'mixed',
        'nullable' => '?' . ($rt['type'] ?? 'mixed'),
        'array' => 'array',
        'result' => $rt['ok'] ?? 'mixed',
        default => 'mixed',
    };
}
