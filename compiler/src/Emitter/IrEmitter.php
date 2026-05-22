<?php

declare(strict_types=1);

namespace EScript\Compiler\Emitter;

use EScript\Compiler\Ast\Node;

final class IrEmitter
{
    /** @param Node[] $nodes */
    public function emit(array $nodes, string $sourceFile): array
    {
        $ir = [
            'version'     => '1.0.0',
            'source'      => $sourceFile,
            'compiled_at' => date('c'),
            'modules'     => [],
            'routes'      => [],
            'services'    => [],
            'dtos'        => [],
            'guards'      => [],
            'islands'     => [],
        ];

        foreach ($nodes as $node) {
            match ($node->kind) {
                'module'  => $ir['modules'][]  = $this->emitModule($node),
                'route'   => $ir['routes'][]   = $this->emitRoute($node),
                'service' => $ir['services'][] = $this->emitService($node),
                'dto'     => $ir['dtos'][]     = $this->emitDto($node),
                'guard'   => $ir['guards'][]   = $this->emitGuard($node),
                'island'  => $ir['islands'][]  = $this->emitIsland($node),
                default   => null,
            };
        }

        // Remove empty arrays
        foreach (['modules', 'routes', 'services', 'dtos', 'guards', 'islands'] as $key) {
            if (empty($ir[$key])) {
                unset($ir[$key]);
            }
        }

        return $ir;
    }

    private function emitModule(Node $node): array
    {
        $d = $node->data;
        $result = [
            'name' => $d['name'],
            'type' => $d['type'] ?? 'package',
        ];

        if (isset($d['version']))      $result['version']      = $d['version'];
        if (isset($d['bootstrapper'])) $result['bootstrapper'] = $d['bootstrapper'];
        if (isset($d['priority']))     $result['priority']     = $d['priority'];
        if (isset($d['requires']))     $result['requires']     = $d['requires'];
        if (isset($d['surface']))      $result['surface']      = $d['surface'];

        return $result;
    }

    private function emitRoute(Node $node): array
    {
        $d = $node->data;
        $target = $d['target'] ?? [];
        $options = $d['options'] ?? [];
        $annotations = $d['annotations'] ?? [];

        $result = [
            'method' => $d['method'],
            'path'   => $d['path'],
            'tier'   => $d['tier'],
            'target' => [],
        ];

        // Build target
        $controller = $target['controller'] ?? '';
        $action = $target['action'] ?? null;

        if ($d['tier'] === 'rust') {
            $result['target']['action'] = $controller;
        } else {
            $result['target']['controller'] = $controller;
            if ($action) {
                $result['target']['action'] = $action;
            }
        }

        // Options
        if (isset($options['middleware'])) {
            $result['middleware'] = $options['middleware'];
        }
        if (isset($options['rust_middlewares'])) {
            $result['rust_middleware'] = $options['rust_middlewares'];
        }
        if (isset($options['dto'])) {
            $result['dto'] = $options['dto'];
        }

        // Annotations → auth, rate_limit, fail_closed
        $annotationMap = [];
        foreach ($annotations as $ann) {
            $annotationMap[$ann['name']] = $ann['args'];
        }

        if (isset($annotationMap['auth'])) {
            $result['auth'] = $this->firstArg($annotationMap['auth']);
        } elseif (isset($options['auth'])) {
            $result['auth'] = $options['auth'];
        }

        if (isset($annotationMap['rate_limit'])) {
            $result['rate_limit'] = $this->firstArg($annotationMap['rate_limit']);
        } elseif (isset($options['rate_limit'])) {
            $result['rate_limit'] = $options['rate_limit'];
        }

        $result['annotations'] = ['fail_closed' => true];

        return $result;
    }

    private function emitService(Node $node): array
    {
        $d = $node->data;
        $annotations = $d['annotations'] ?? [];

        $tier = 'php';
        $failMode = 'closed';

        foreach ($annotations as $ann) {
            if ($ann['name'] === 'tier') {
                $tier = $this->firstArg($ann['args']);
            }
            if ($ann['name'] === 'fail_closed') {
                $failMode = 'closed';
            }
            if ($ann['name'] === 'fail_open') {
                $failMode = 'open';
            }
        }

        $result = [
            'name'       => $d['name'],
            'tier'       => $tier,
            'fail_mode'  => $failMode,
        ];

        if (!empty($d['implements'])) {
            $result['implements'] = $d['implements'];
        }

        if (!empty($d['injects'])) {
            $result['injects'] = $d['injects'];
        }

        if (!empty($d['guards'])) {
            $result['guards'] = $d['guards'];
        }

        if (!empty($d['methods'])) {
            $result['methods'] = array_map([$this, 'emitMethod'], $d['methods']);
        }

        return $result;
    }

    private function emitMethod(array $method): array
    {
        $result = [
            'name'       => $method['name'],
            'visibility' => $method['visibility'] ?? 'public',
        ];

        if (!empty($method['params'])) {
            $result['params'] = array_map(function (array $p): array {
                $param = ['name' => $p['name'], 'type' => $p['type']];
                if ($p['default'] !== null) {
                    $param['default'] = $p['default'];
                }
                return $param;
            }, $method['params']);
        }

        if ($method['return_type'] !== null) {
            $result['return_type'] = $this->emitTypeRef($method['return_type']);
        }

        if (!empty($method['throws'])) {
            $result['throws'] = $method['throws'];
        }

        return $result;
    }

    private function emitDto(Node $node): array
    {
        $d = $node->data;
        $annotations = $d['annotations'] ?? [];

        $result = [
            'name'   => $d['name'],
            'fields' => [],
        ];

        foreach ($d['fields'] as $field) {
            $type = $field['type'];
            $nullable = str_ends_with($type, '?');
            if ($nullable) {
                $type = rtrim($type, '?');
            }

            $f = [
                'name'     => $field['name'],
                'type'     => $type,
                'nullable' => $nullable,
            ];

            if ($field['default'] !== null) {
                $f['default'] = $field['default'];
            }

            $result['fields'][] = $f;
        }

        // @fluid annotation → fluid section
        foreach ($annotations as $ann) {
            if ($ann['name'] === 'fluid' && !empty($ann['args'])) {
                $result['fluid'] = [];
                foreach ($ann['args'] as $k => $v) {
                    $result['fluid'][$k] = $v;
                }
            }
        }

        return $result;
    }

    private function emitGuard(Node $node): array
    {
        $d = $node->data;
        $annotations = $d['annotations'] ?? [];

        $result = [
            'name'      => $d['name'],
            'tier'      => $d['tier'] ?? 'rust',
            'fail_mode' => $d['fail_mode'] ?? 'closed',
        ];

        if (isset($d['input']))   $result['input_type']  = $d['input'];
        if (isset($d['output']))  $result['output_type'] = $d['output'];
        if (isset($d['ceiling'])) $result['ceiling']     = $d['ceiling'];

        // Reactive annotations
        foreach ($annotations as $ann) {
            match ($ann['name']) {
                'trigger' => $result['trigger'] = $ann['args'],
                'action' => $result['action'] = $ann['args'],
                'condition' => $result['conditions'] = $ann['args'],
                'unsafe' => $result['unsafe_acknowledged'] = true,
                default => null,
            };
        }

        return $result;
    }

    private function emitIsland(Node $node): array
    {
        $d = $node->data;
        $result = ['name' => $d['name']];

        if (isset($d['dto']))       $result['dto']       = $d['dto'];
        if (isset($d['component'])) $result['component'] = $d['component'];
        if (isset($d['wasm']))      $result['wasm']      = $d['wasm'];
        if (isset($d['fallback']))  $result['fallback']  = $d['fallback'];
        if (isset($d['lane']))      $result['lane']      = $d['lane'];

        return $result;
    }

    private function emitTypeRef(string $type): array
    {
        if (preg_match('/^Result<(.+),\s*(.+)>$/', $type, $m)) {
            return ['kind' => 'result', 'ok' => trim($m[1]), 'err' => trim($m[2])];
        }
        if (str_ends_with($type, '?')) {
            return ['kind' => 'nullable', 'type' => rtrim($type, '?')];
        }
        if (str_ends_with($type, '[]')) {
            return ['kind' => 'array', 'type' => rtrim($type, '[]')];
        }
        return ['kind' => 'simple', 'type' => $type];
    }

    private function firstArg(array $args): mixed
    {
        if (empty($args)) return null;
        return reset($args);
    }
}
