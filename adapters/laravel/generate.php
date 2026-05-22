#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * EScript Laravel Adapter
 *
 * Translates EScript IR into Laravel-native artifacts:
 *   - routes/api.php
 *   - app/Http/Controllers/*.php
 *   - app/Http/Requests/*.php (from DTOs)
 *   - app/Services/*.php
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php generate.php <ir-file.json> [--output=dir]\n");
    exit(1);
}

$irFile = $argv[1];
$outputDir = 'laravel-output';

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

echo "EScript Laravel Adapter v0.1.0\n";
echo "IR source: {$ir['source']}\n";
echo str_repeat('─', 50) . "\n";

// ─── Generate routes/api.php ─────────────────────────────────────────────────

$routes = $ir['routes'] ?? [];
if (!empty($routes)) {
    $routeCode = "<?php\n\n";
    $routeCode .= "use Illuminate\\Support\\Facades\\Route;\n\n";

    $controllers = [];

    foreach ($routes as $route) {
        $method = strtolower($route['method']);
        $path = $route['path'];
        $controller = $route['target']['controller'] ?? '';
        $action = $route['target']['action'] ?? 'index';

        $controllers[$controller] = true;
        $fullController = "App\\Http\\Controllers\\{$controller}";

        $line = "Route::{$method}('{$path}', [{$fullController}::class, '{$action}'])";

        // Middleware
        $middleware = $route['middleware'] ?? [];
        $laravelMiddleware = array_map(fn(string $m) => mapMiddleware($m), $middleware);
        if (!empty($laravelMiddleware)) {
            $mwStr = implode("', '", $laravelMiddleware);
            $line .= "\n    ->middleware(['{$mwStr}'])";
        }

        // Name
        $routeName = slugToName($path, $action);
        $line .= "\n    ->name('{$routeName}')";

        $line .= ";\n\n";
        $routeCode .= $line;
    }

    writeFile("{$outputDir}/routes/api.php", $routeCode);
}

// ─── Generate Controllers ────────────────────────────────────────────────────

$controllerNames = [];
foreach ($routes as $route) {
    $name = $route['target']['controller'] ?? '';
    if ($name && !isset($controllerNames[$name])) {
        $controllerNames[$name] = [];
    }
    if ($name) {
        $controllerNames[$name][] = $route;
    }
}

foreach ($controllerNames as $controllerName => $controllerRoutes) {
    $dtoMap = buildDtoMap($ir['dtos'] ?? []);

    $code = "<?php\n\n";
    $code .= "declare(strict_types=1);\n\n";
    $code .= "namespace App\\Http\\Controllers;\n\n";
    $code .= "use Illuminate\\Http\\JsonResponse;\n";
    $code .= "use Illuminate\\Http\\Request;\n";

    // Import FormRequests for POST/PUT/PATCH routes
    $formRequestImports = [];
    foreach ($controllerRoutes as $r) {
        if (in_array($r['method'], ['POST', 'PUT', 'PATCH']) && isset($r['dto'])) {
            $fr = $r['dto'] . 'Request';
            $formRequestImports[$fr] = "use App\\Http\\Requests\\{$fr};";
        }
    }
    foreach ($formRequestImports as $import) {
        $code .= $import . "\n";
    }

    $code .= "\n";
    $code .= "class {$controllerName} extends Controller\n{\n";

    foreach ($controllerRoutes as $r) {
        $action = $r['target']['action'] ?? 'index';
        $method = $r['method'];
        $dtoName = $r['dto'] ?? null;

        // Determine parameter type
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && $dtoName) {
            $paramType = $dtoName . 'Request';
            $paramName = 'request';
        } else {
            $paramType = 'Request';
            $paramName = 'request';
        }

        // Check if route has path params
        $pathParams = [];
        preg_match_all('/\{(\w+)\}/', $r['path'], $matches);
        if (!empty($matches[1])) {
            $pathParams = $matches[1];
        }

        $params = ["{$paramType} \${$paramName}"];
        foreach ($pathParams as $pp) {
            $params[] = "int \${$pp}";
        }
        $paramStr = implode(', ', $params);

        $code .= "    public function {$action}({$paramStr}): JsonResponse\n";
        $code .= "    {\n";
        $code .= "        // TODO: Implement {$action}\n";
        $code .= "        return response()->json([]);\n";
        $code .= "    }\n\n";
    }

    $code .= "}\n";

    writeFile("{$outputDir}/app/Http/Controllers/{$controllerName}.php", $code);
}

// ─── Generate FormRequests from DTOs ─────────────────────────────────────────

foreach ($ir['dtos'] ?? [] as $dto) {
    $name = $dto['name'];
    $fields = $dto['fields'] ?? [];

    $code = "<?php\n\n";
    $code .= "declare(strict_types=1);\n\n";
    $code .= "namespace App\\Http\\Requests;\n\n";
    $code .= "use Illuminate\\Foundation\\Http\\FormRequest;\n\n";
    $code .= "class {$name}Request extends FormRequest\n{\n";
    $code .= "    public function authorize(): bool\n";
    $code .= "    {\n";
    $code .= "        return true;\n";
    $code .= "    }\n\n";
    $code .= "    /** @return array<string, mixed> */\n";
    $code .= "    public function rules(): array\n";
    $code .= "    {\n";
    $code .= "        return [\n";

    foreach ($fields as $field) {
        $rules = [];
        if (!($field['nullable'] ?? false) && !isset($field['default'])) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }
        $rules[] = mapTypeToLaravelRule($field['type']);

        $ruleStr = implode('|', $rules);
        $code .= "            '{$field['name']}' => '{$ruleStr}',\n";
    }

    $code .= "        ];\n";
    $code .= "    }\n";
    $code .= "}\n";

    writeFile("{$outputDir}/app/Http/Requests/{$name}Request.php", $code);
}

// ─── Generate Services ───────────────────────────────────────────────────────

foreach ($ir['services'] ?? [] as $service) {
    if (($service['tier'] ?? 'php') !== 'php') continue;

    $name = $service['name'];
    $injects = $service['injects'] ?? [];
    $methods = $service['methods'] ?? [];
    $implements = $service['implements'] ?? [];

    $code = "<?php\n\n";
    $code .= "declare(strict_types=1);\n\n";
    $code .= "namespace App\\Services;\n\n";

    $implementsStr = '';
    if (!empty($implements)) {
        $implementsStr = ' implements ' . implode(', ', $implements);
    }

    $code .= "class {$name}{$implementsStr}\n{\n";

    // Constructor with DI
    if (!empty($injects)) {
        $code .= "    public function __construct(\n";
        $parts = [];
        foreach ($injects as $inject) {
            $parts[] = "        private readonly {$inject['type']} \${$inject['name']}";
        }
        $code .= implode(",\n", $parts) . "\n";
        $code .= "    ) {}\n\n";
    }

    // Methods
    foreach ($methods as $method) {
        $vis = $method['visibility'] ?? 'public';
        $params = [];
        foreach ($method['params'] ?? [] as $p) {
            $typeHint = mapIrTypeToPhp($p['type']);
            $param = "{$typeHint} \${$p['name']}";
            if (isset($p['default'])) {
                $param .= ' = ' . var_export($p['default'], true);
            }
            $params[] = $param;
        }
        $paramStr = implode(', ', $params);

        $returnType = '';
        if (isset($method['return_type'])) {
            $returnType = ': ' . mapReturnTypeToPhp($method['return_type']);
        }

        $code .= "    {$vis} function {$method['name']}({$paramStr}){$returnType}\n";
        $code .= "    {\n";
        $code .= "        // TODO: Implement {$method['name']}\n";
        $code .= "        throw new \\RuntimeException('Not implemented');\n";
        $code .= "    }\n\n";
    }

    $code .= "}\n";

    writeFile("{$outputDir}/app/Services/{$name}.php", $code);
}

echo str_repeat('─', 50) . "\n";
echo "Laravel adapter complete.\n";

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

function mapMiddleware(string $m): string
{
    return match ($m) {
        'AuthMiddleware' => 'auth:sanctum',
        'AdminAuthMiddleware' => 'auth:sanctum',
        'RateLimitMiddleware' => 'throttle:api',
        default => strtolower($m),
    };
}

function slugToName(string $path, string $action): string
{
    $parts = array_filter(explode('/', trim($path, '/')));
    $parts = array_filter($parts, fn($p) => !str_starts_with($p, '{'));
    return implode('.', $parts) . '.' . $action;
}

function mapTypeToLaravelRule(string $type): string
{
    return match ($type) {
        'string' => 'string',
        'int' => 'integer',
        'float' => 'numeric',
        'bool' => 'boolean',
        default => 'string',
    };
}

function mapIrTypeToPhp(string $type): string
{
    return match ($type) {
        'string' => 'string',
        'int' => 'int',
        'float' => 'float',
        'bool' => 'bool',
        'void' => 'void',
        default => $type,
    };
}

function mapReturnTypeToPhp(array $rt): string
{
    return match ($rt['kind'] ?? 'simple') {
        'simple' => mapIrTypeToPhp($rt['type'] ?? 'mixed'),
        'nullable' => '?' . mapIrTypeToPhp($rt['type'] ?? 'mixed'),
        'array' => 'array',
        'result' => mapIrTypeToPhp($rt['ok'] ?? 'mixed'),
        default => 'mixed',
    };
}

function buildDtoMap(array $dtos): array
{
    $map = [];
    foreach ($dtos as $dto) {
        $map[$dto['name']] = $dto;
    }
    return $map;
}
