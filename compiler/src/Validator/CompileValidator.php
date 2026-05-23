<?php

declare(strict_types=1);

namespace EScript\Compiler\Validator;

use EScript\Compiler\Ast\Node;

final class CompileValidator
{
    /** @var string[] */
    private array $errors = [];

    /**
     * @param Node[] $nodes
     * @return string[] List of compile errors (empty = valid)
     */
    public function validate(array $nodes): array
    {
        $this->errors = [];

        $guardNames = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'guard') {
                $guardNames[] = $node->data['name'];
            }
        }

        foreach ($nodes as $node) {
            match ($node->kind) {
                'route'   => $this->validateRoute($node),
                'guard'   => $this->validateGuard($node),
                'service' => $this->validateService($node, $guardNames),
                'dto'     => $this->validateDto($node),
                default   => null,
            };
        }

        return $this->errors;
    }

    private function validateRoute(Node $node): void
    {
        $d = $node->data;
        $method = $d['method'] ?? '';
        $path = $d['path'] ?? '';
        $annotations = $d['annotations'] ?? [];
        $options = $d['options'] ?? [];
        $line = $node->line;

        $annotationNames = array_map(fn($a) => $a['name'], $annotations);

        // Rule: Mutating methods require @auth
        $mutating = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true);
        if ($mutating) {
            $hasAuth = in_array('auth', $annotationNames) || isset($options['auth']);
            if (!$hasAuth) {
                $this->errors[] = "Line {$line}: Mutating method {$method} on '{$path}' requires @auth annotation. Add @auth(bearer) or explicitly acknowledge with @auth(none).";
            }
        }

        // Rule: Tier must be explicit
        if (empty($d['tier'])) {
            $this->errors[] = "Line {$line}: Route '{$path}' must declare a tier (@php, @rust, @elixir, @node).";
        }
    }

    private function validateGuard(Node $node): void
    {
        $d = $node->data;
        $name = $d['name'] ?? '(unknown)';
        $failMode = $d['fail_mode'] ?? 'closed';
        $annotations = $d['annotations'] ?? [];
        $line = $node->line;

        // Rule: fail_mode: open requires @unsafe
        if ($failMode === 'open') {
            $hasUnsafe = false;
            foreach ($annotations as $ann) {
                if ($ann['name'] === 'unsafe') {
                    $hasUnsafe = true;
                    break;
                }
            }
            if (!$hasUnsafe) {
                $this->errors[] = "Line {$line}: Guard '{$name}' has fail_mode 'open' but no @unsafe annotation. Add @unsafe(\"reason\") to acknowledge the risk.";
            }
        }

        // Rule: tier must be set
        if (empty($d['tier'])) {
            $this->errors[] = "Line {$line}: Guard '{$name}' must declare a tier.";
        }
    }

    private function validateService(Node $node, array $knownGuards): void
    {
        $d = $node->data;
        $name = $d['name'] ?? '(unknown)';
        $annotations = $d['annotations'] ?? [];
        $line = $node->line;

        // Rule: service must have tier annotation
        $hasTier = false;
        $hasFailOpen = false;
        foreach ($annotations as $ann) {
            if ($ann['name'] === 'tier') {
                $hasTier = true;
            }
            if ($ann['name'] === 'fail_open') {
                $hasFailOpen = true;
            }
        }
        if (!$hasTier) {
            $this->errors[] = "Line {$line}: Service '{$name}' must declare @tier(php|rust|elixir|node).";
        }

        if ($hasFailOpen) {
            $hasUnsafeAck = false;
            foreach ($annotations as $ann) {
                if ($ann['name'] === 'unsafe') {
                    $hasUnsafeAck = true;
                    break;
                }
            }
            if (!$hasUnsafeAck) {
                $this->errors[] = "Line {$line}: Service '{$name}' uses @fail_open and must include @unsafe(\"reason\").";
            }
        }

        // Rule: guard references must exist
        foreach (($d['guards'] ?? []) as $guardRef) {
            if (!in_array($guardRef, $knownGuards, true)) {
                $this->errors[] = "Line {$line}: Service '{$name}' references undefined guard '{$guardRef}'.";
            }
        }

        // Rule: check for mixed types without @unsafe
        $hasUnsafe = false;
        foreach ($annotations as $ann) {
            if ($ann['name'] === 'unsafe') {
                $hasUnsafe = true;
            }
        }

        foreach (($d['injects'] ?? []) as $inject) {
            if ($inject['type'] === 'mixed' && !$hasUnsafe) {
                $this->errors[] = "Line {$line}: Service '{$name}' injects 'mixed' type for '{$inject['name']}' without @unsafe.";
            }
        }

        foreach (($d['methods'] ?? []) as $method) {
            foreach (($method['params'] ?? []) as $param) {
                if ($param['type'] === 'mixed' && !$hasUnsafe) {
                    $this->errors[] = "Line {$line}: Method '{$name}::{$method['name']}' uses 'mixed' type for parameter '{$param['name']}' without @unsafe.";
                }
            }
        }
    }

    private function validateDto(Node $node): void
    {
        $d = $node->data;
        $name = $d['name'] ?? '(unknown)';
        $line = $node->line;

        // Rule: DTO must have at least one field
        if (empty($d['fields'])) {
            $this->errors[] = "Line {$line}: DTO '{$name}' must have at least one field.";
        }
    }
}
