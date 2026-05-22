<?php

declare(strict_types=1);

namespace EScript\Compiler;

use EScript\Compiler\Lexer\Lexer;
use EScript\Compiler\Parser\Parser;
use EScript\Compiler\Validator\CompileValidator;
use EScript\Compiler\Emitter\IrEmitter;

final class Cli
{
    private array $args;

    public function __construct(array $argv)
    {
        $this->args = $argv;
    }

    public function run(): int
    {
        $command = $this->args[1] ?? 'help';

        return match ($command) {
            'compile' => $this->compile(),
            'help', '--help', '-h' => $this->help(),
            'version', '--version', '-v' => $this->version(),
            default => $this->unknownCommand($command),
        };
    }

    private function compile(): int
    {
        $options = $this->parseOptions();
        $sourceDir = $options['source'] ?? 'escript';
        $outputDir = $options['output'] ?? 'build/ir';
        $validateOnly = $options['validate-only'] ?? false;
        $dryRun = $options['dry-run'] ?? false;

        // Find .es files
        $files = $this->findEsFiles($sourceDir);

        if (empty($files)) {
            $this->stderr("No .es files found in '{$sourceDir}'");
            return 1;
        }

        $this->stdout(sprintf("EScript Compiler v%s", $this->getVersion()));
        $this->stdout(sprintf("Found %d .es file(s) in '%s'", count($files), $sourceDir));
        $this->stdout(str_repeat('─', 50));

        $validator = new CompileValidator();
        $emitter = new IrEmitter();
        $totalErrors = 0;
        $totalFiles = 0;
        $start = microtime(true);

        foreach ($files as $file) {
            $this->stdout("Compiling: {$file}");

            try {
                $source = file_get_contents($file);
                if ($source === false) {
                    $this->stderr("  ERROR: Cannot read file");
                    $totalErrors++;
                    continue;
                }

                // Lex
                $lexer = new Lexer($source);
                $tokens = $lexer->tokenize();

                // Parse
                $parser = new Parser($tokens, $file);
                $ast = $parser->parse();

                // Validate
                $errors = $validator->validate($ast);

                if (!empty($errors)) {
                    foreach ($errors as $error) {
                        $this->stderr("  COMPILE ERROR: {$error}");
                    }
                    $totalErrors += count($errors);
                    continue;
                }

                if ($validateOnly) {
                    $this->stdout("  ✓ Valid");
                    $totalFiles++;
                    continue;
                }

                // Emit IR
                $ir = $emitter->emit($ast, $file);

                if ($dryRun) {
                    $this->stdout("  Would generate IR:");
                    $this->stdout('  ' . json_encode($ir, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $totalFiles++;
                    continue;
                }

                // Write IR
                $outputFile = $this->irOutputPath($file, $sourceDir, $outputDir);
                $this->writeIr($outputFile, $ir);
                $this->stdout("  → {$outputFile}");
                $totalFiles++;

            } catch (\RuntimeException $e) {
                $this->stderr("  PARSE ERROR: {$e->getMessage()}");
                $totalErrors++;
            }
        }

        $elapsed = round((microtime(true) - $start) * 1000, 1);
        $this->stdout(str_repeat('─', 50));

        if ($totalErrors > 0) {
            $this->stderr("FAILED: {$totalErrors} error(s) in {$elapsed}ms");
            return 1;
        }

        $this->stdout("OK: {$totalFiles} file(s) compiled in {$elapsed}ms");
        return 0;
    }

    private function findEsFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            // Might be a single file
            if (is_file($dir) && str_ends_with($dir, '.es')) {
                return [$dir];
            }
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.es')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    private function irOutputPath(string $sourceFile, string $sourceDir, string $outputDir): string
    {
        // If source is a single file, use its basename
        if (is_file($sourceDir)) {
            $irName = preg_replace('/\.es$/', '.ir.json', basename($sourceFile));
        } else {
            $relative = str_replace($sourceDir, '', $sourceFile);
            $relative = ltrim($relative, '/\\');
            $irName = preg_replace('/\.es$/', '.ir.json', $relative);
        }
        return rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $irName;
    }

    private function writeIr(string $path, array $ir): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($ir, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json . "\n");
    }

    private function parseOptions(): array
    {
        $options = [];
        $positional = [];

        for ($i = 2; $i < count($this->args); $i++) {
            $arg = $this->args[$i];

            if (str_starts_with($arg, '--')) {
                $key = substr($arg, 2);
                if (str_contains($key, '=')) {
                    [$key, $value] = explode('=', $key, 2);
                    $options[$key] = $value;
                } else {
                    $options[$key] = true;
                }
            } else {
                $positional[] = $arg;
            }
        }

        if (!empty($positional)) {
            $options['source'] = $positional[0];
        }

        return $options;
    }

    private function help(): int
    {
        $this->stdout("EScript Compiler v" . $this->getVersion());
        $this->stdout('');
        $this->stdout('Usage:');
        $this->stdout('  escript compile [source]         Compile .es files to IR');
        $this->stdout('  escript compile --validate-only   Validate without writing');
        $this->stdout('  escript compile --dry-run         Show generated IR');
        $this->stdout('  escript compile --output=dir      Output directory');
        $this->stdout('  escript version                   Show version');
        $this->stdout('  escript help                      Show this help');
        $this->stdout('');
        $this->stdout('Examples:');
        $this->stdout('  php escript compile escript/');
        $this->stdout('  php escript compile examples/basic-api.es --dry-run');
        $this->stdout('  php escript compile --validate-only');
        return 0;
    }

    private function version(): int
    {
        $this->stdout("escript " . $this->getVersion());
        return 0;
    }

    private function unknownCommand(string $cmd): int
    {
        $this->stderr("Unknown command: '{$cmd}'");
        $this->stderr("Run 'escript help' for usage.");
        return 1;
    }

    private function getVersion(): string
    {
        return '0.1.0';
    }

    private function stdout(string $msg): void
    {
        fwrite(STDOUT, $msg . PHP_EOL);
    }

    private function stderr(string $msg): void
    {
        fwrite(STDERR, $msg . PHP_EOL);
    }
}
