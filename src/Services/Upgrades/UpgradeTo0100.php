<?php

namespace WebRegulate\LaravelAdministration\Services\Upgrades;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\Finder\Finder;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;
use WebRegulate\LaravelAdministration\Services\WRLASettingsService;

class UpgradeTo0100
{
    /**
     * Build a source-only plan. Never require application config or invoke callbacks.
     *
     * @return array<string, array{before: ?string, after: ?string}>
     */
    public static function plan(string $basePath): array
    {
        $oldPath = 'config/wr-laravel-administration.php';
        $newPath = 'config/wrla.php';
        $settingsPath = 'app/WRLA/WRLASettings.php';
        $oldExists = is_file(static::path($basePath, $oldPath));
        $newExists = is_file(static::path($basePath, $newPath));

        // Never guess which of two published configs should win
        if ($oldExists && $newExists) {
            throw new \RuntimeException('Both config/wr-laravel-administration.php and config/wrla.php exist. Merge them manually before upgrading.');
        }

        $changes = [];
        $runtime = new Array_([], ['kind' => Array_::KIND_SHORT]);

        // Migrate published config, including partially renamed applications
        if ($oldExists || $newExists) {
            $sourcePath = $oldExists ? $oldPath : $newPath;
            $source = static::read(static::path($basePath, $sourcePath));
            [$config, $runtime] = static::splitConfig(static::rewriteReferences($source));
            static::change($changes, $newPath, $newExists ? $source : null, $config);

            // Remove the old file only after all replacement files have been written
            if ($oldExists) {
                static::change($changes, $oldPath, $source, null);
            }
        }

        $exists = is_file(static::path($basePath, $settingsPath));
        $before = $exists ? static::read(static::path($basePath, $settingsPath)) : null;
        $settings = $before ?? static::read(dirname(__DIR__, 2) . '/stubs/WRLASettings.stub');
        $after = static::addRuntime(static::rewriteReferences($settings), $runtime, !$exists);
        static::change($changes, $settingsPath, $before, $after);

        $files = Finder::create()->files()->name('*.php')->in($basePath)
            ->exclude(['vendor', 'node_modules', 'storage', 'public', 'bootstrap/cache'])->ignoreVCS(true);

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            // Planned files already contain their complete transformed source
            if (isset($changes[$relative])) {
                continue;
            }

            $before = static::read($file->getPathname());
            try {
                $after = static::rewriteReferences($before);
            } catch (\RuntimeException $exception) {
                throw new \RuntimeException($relative . ': ' . $exception->getMessage(), 0, $exception);
            }
            static::change($changes, $relative, $before, $after);
        }

        foreach ($changes as $relative => $change) {
            static::path($basePath, $relative);

            // Parse generated PHP without executing application code
            if ($change['after'] !== null) {
                (new ParserFactory())->createForNewestSupportedVersion()->parse($change['after']);
            }
        }

        return $changes;
    }

    /**
     * Apply the confirmed plan, retaining originals in memory for write-error rollback.
     *
     * @param array<string, array{before: ?string, after: ?string}> $changes
     */
    public static function apply(string $basePath, array $changes): void
    {
        $lockPath = static::path($basePath, 'storage/framework/cache/wrla-upgrade.lock');
        static::ensureDirectory(dirname($lockPath));
        $lock = fopen($lockPath, 'c');

        // Refuse to run if exclusive application upgrade locking is unavailable
        if ($lock === false) {
            throw new \RuntimeException('Unable to open the WRLA application upgrade lock.');
        }

        // Concurrent upgrades must never roll back each other's writes
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Another WRLA application upgrade is running. Wait for it to finish.');
        }

        try {
            static::applyLocked($basePath, $changes);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function applyLocked(string $basePath, array $changes): void
    {
        // Refuse stale previews before changing source
        foreach ($changes as $relative => $change) {
            $path = static::path($basePath, $relative);
            $current = is_file($path) ? static::read($path) : null;

            // Another editor may have changed a file since confirmation
            if ($current !== $change['before']) {
                throw new \RuntimeException("{$relative} changed after the upgrade preview. Run wrla:upgrade again.");
            }
        }

        $applied = [];

        try {
            // Write replacements before deleting legacy files
            foreach ([false, true] as $deleting) {
                foreach ($changes as $relative => $change) {
                    // Process deletions only on the second pass
                    if (($change['after'] === null) !== $deleting) {
                        continue;
                    }

                    $path = static::path($basePath, $relative);
                    $current = is_file($path) ? static::read($path) : null;

                    // Do not overwrite edits made since the initial stale-file check
                    if ($current !== $change['before']) {
                        throw new \RuntimeException("{$relative} changed while preparing the upgrade.");
                    }

                    $applied[$relative] = $change;
                    static::restore($path, $change['after']);
                }
            }
        } catch (\Throwable $exception) {
            $rollbackErrors = [];
            foreach (array_reverse($applied, true) as $relative => $change) {
                try {
                    $path = static::path($basePath, $relative);
                    $current = is_file($path) ? static::read($path) : null;

                    // Preserve unrelated editor changes made after our attempted write
                    if ($current !== $change['after'] && $current !== $change['before']) {
                        throw new \RuntimeException($relative . ' was edited externally; review and restore it using Git.');
                    }

                    static::restore($path, $change['before']);
                } catch (\RuntimeException $rollbackException) {
                    $rollbackErrors[] = $rollbackException->getMessage();
                }
            }

            throw new \RuntimeException(
                'Upgrade failed: ' . $exception->getMessage()
                . ($rollbackErrors === [] ? '. Source changes were rolled back.' : '. Rollback needs attention; review and restore affected files using Git: ' . implode('; ', $rollbackErrors)),
                0,
                $exception,
            );
        }
    }

    public static function rewriteReferences(string $source): string
    {
        return ConfigReferenceRewriter::rewrite($source);
    }

    private static function splitConfig(string $source): array
    {
        [$parser, $original, $nodes] = static::editable($source);
        $returns = array_values(array_filter($nodes, fn (Node $node) => $node instanceof Stmt\Return_));

        foreach ($nodes as $node) {
            // File-level variables and side effects cannot safely move into a method
            if (!$node instanceof Stmt\Return_ && !$node instanceof Stmt\Use_ && !$node instanceof Stmt\GroupUse && !$node instanceof Stmt\Declare_ && !$node instanceof Stmt\Nop) {
                throw new \RuntimeException('WRLA config must be a literal returned array with imports only. Move file-level logic manually before upgrading.');
            }
        }

        // Dynamic returned config needs developer review rather than evaluation
        if (count($returns) !== 1 || !$returns[0]->expr instanceof Array_) {
            throw new \RuntimeException('WRLA config must return a literal array for automatic migration.');
        }

        $resolved = (new NodeTraverser(new CloningVisitor(), new NameResolver()))->traverse($nodes);
        $resolvedReturn = (new NodeFinder())->findFirstInstanceOf($resolved, Stmt\Return_::class);
        $runtime = static::splitArray($returns[0]->expr, $resolvedReturn->expr);
        $config = (new Standard())->printFormatPreserving($nodes, $original, $parser->getTokens());

        return [$config, $runtime];
    }

    private static function splitArray(Array_ $array, Array_ $resolved, string $prefix = ''): Array_
    {
        $runtime = [];
        $seen = [];

        foreach ($array->items as $index => $item) {
            // Unpacking hides keys and override order from the source migration
            if ($item === null || $item->unpack || $item->byRef) {
                throw new \RuntimeException("Array unpacking or references at {$prefix} need manual migration.");
            }

            $resolvedItem = $resolved->items[$index];
            $key = $item->key === null ? $index : static::key($resolvedItem->key);

            // Duplicate keys would otherwise change which value wins after extraction
            if (array_key_exists($key, $seen)) {
                throw new \RuntimeException("Duplicate WRLA config key {$prefix}{$key} needs manual migration.");
            }

            $seen[$key] = true;
            $path = $prefix . $key;

            // Move a whole supported section so list order and explicit defaults survive
            if (in_array($path, WRLASettingsService::RUNTIME_KEYS, true)) {
                static::assertMovable($resolvedItem->value);
                $runtime[] = static::detached($resolvedItem);
                unset($array->items[$index]);
            } elseif ($item->value instanceof Array_) {
                $nested = static::splitArray($item->value, $resolvedItem->value, $path . '.');

                // Include only branches containing moved values
                if ($nested->items !== []) {
                    $runtime[] = new Node\ArrayItem($nested, static::detached($resolvedItem->key));
                }
            } elseif (static::hasRuntimeValue($item->value)) {
                throw new \RuntimeException("Non-cacheable value at {$path} is not a supported runtime setting. Migrate it manually.");
            }
        }

        $array->items = array_values($array->items);

        return new Array_($runtime, ['kind' => Array_::KIND_SHORT]);
    }

    private static function hasRuntimeValue(Expr $expression): bool
    {
        return (new NodeFinder())->findFirst([$expression], function (Node $node): bool {
            // Calls known to produce scalar config values remain in config
            if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
                return !in_array(strtolower($node->name->toString()), [
                    'env', 'config', 'base_path', 'app_path', 'storage_path', 'resource_path',
                    'public_path', 'database_path', 'lang_path', 'config_path', 'dirname',
                ], true);
            }

            return $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Expr\New_
                || $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall || $node instanceof Expr\Variable
                || $node instanceof Expr\Include_ || $node instanceof Expr\Eval_ || $node instanceof Expr\FuncCall;
        }) !== null;
    }

    private static function assertMovable(Expr $expression): void
    {
        $unsafe = (new NodeFinder())->findFirst([$expression], fn (Node $node) =>
            $node instanceof Expr\ClosureUse || ($node instanceof Expr\Variable && $node->name === 'this')
            || $node instanceof Scalar\MagicConst\Class_ || $node instanceof Scalar\MagicConst\Trait_
            || $node instanceof Scalar\MagicConst\Method || $node instanceof Scalar\MagicConst\Function_);

        // Captured file-scope state cannot be recreated inside runtime()
        if ($unsafe !== null) {
            throw new \RuntimeException('A WRLA callback captures file-scope state. Move it into WRLASettings::runtime() manually.');
        }

        (new NodeTraverser(new class extends NodeVisitorAbstract {
            private array $scopes = [];

            public function enterNode(Node $node): ?Node
            {
                // Arrow functions implicitly capture their enclosing lexical scope
                if ($node instanceof Expr\ArrowFunction || $node instanceof Expr\Closure) {
                    $parent = end($this->scopes);
                    $parameters = array_map(fn (Node\Param $parameter) => $parameter->var->name, $node->params);
                    $this->scopes[] = [
                        'arrow' => $node instanceof Expr\ArrowFunction,
                        'variables' => array_merge($node instanceof Expr\ArrowFunction && $parent !== false ? $parent['variables'] : [], $parameters),
                    ];
                }

                $scope = end($this->scopes);

                // Reject implicit captures from the config loader's own local variables
                if ($node instanceof Expr\Variable && $scope !== false && $scope['arrow']
                    && !in_array($node->name, array_merge($scope['variables'], ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV']), true)) {
                    throw new \RuntimeException('A WRLA arrow callback captures an external variable. Move it into WRLASettings::runtime() manually.');
                }

                return null;
            }

            public function leaveNode(Node $node): ?Node
            {
                // Nested callback parameters do not escape their lexical scope
                if ($node instanceof Expr\ArrowFunction || $node instanceof Expr\Closure) {
                    array_pop($this->scopes);
                }

                return null;
            }
        }))->traverse([$expression]);
    }

    private static function key(Expr $key): string|int
    {
        // Literal keys do not require application code to execute
        if ($key instanceof Scalar\String_ || $key instanceof Scalar\Int_) {
            return $key->value;
        }

        // Class-string config keys are safe to resolve syntactically
        if ($key instanceof Expr\ClassConstFetch && $key->class instanceof Node\Name && $key->name instanceof Node\Identifier && strtolower($key->name->name) === 'class') {
            return ($key->class->getAttribute('resolvedName') ?? $key->class)->toString();
        }

        // WRLA's permission enum is package-owned, not application code
        if ($key instanceof Expr\PropertyFetch && $key->name instanceof Node\Identifier && $key->name->name === 'value'
            && $key->var instanceof Expr\ClassConstFetch && $key->var->class instanceof Node\Name && $key->var->name instanceof Node\Identifier
            && ($key->var->class->getAttribute('resolvedName') ?? $key->var->class)->toString() === ManageableModelPermissions::class) {
            $constant = ManageableModelPermissions::class . '::' . $key->var->name->name;

            // Unknown enum cases need review rather than runtime evaluation
            if (defined($constant)) {
                return constant($constant)->value;
            }
        }

        throw new \RuntimeException('A computed WRLA config key needs manual migration (line ' . $key->getStartLine() . ').');
    }

    private static function detached(?Node $node): ?Node
    {
        // Implicit array keys need no syntax node
        if ($node === null) {
            return null;
        }

        $nodes = (new NodeTraverser(new CloningVisitor(), new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): ?Node
            {
                $line = $node->getStartLine();
                $node->setAttributes(['comments' => $node->getComments()]);

                // Preserve magic paths when moving code out of the config directory
                if ($node instanceof Scalar\MagicConst\Dir) {
                    return new Expr\FuncCall(new Node\Name\FullyQualified('config_path'));
                }

                // __FILE__ now identifies the renamed config file
                if ($node instanceof Scalar\MagicConst\File) {
                    return new Expr\FuncCall(new Node\Name\FullyQualified('config_path'), [new Node\Arg(new Scalar\String_('wrla.php'))]);
                }

                // Preserve source line numbers used by diagnostic callbacks
                if ($node instanceof Scalar\MagicConst\Line) {
                    return new Scalar\Int_($line);
                }

                // Config files are in the global namespace, unlike the settings class
                if ($node instanceof Scalar\MagicConst\Namespace_) {
                    return new Scalar\String_('');
                }

                return null;
            }
        }))->traverse([$node]);

        return $nodes[0];
    }

    private static function addRuntime(string $source, Array_ $runtime, bool $newSettings): string
    {
        // An unchanged installation template already has the intended layout and imports
        if ($newSettings && $runtime->items === []) {
            return $source;
        }

        [$parser, $original, $nodes] = static::editable($source);
        (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($nodes);
        $namespace = (new NodeFinder())->findFirst($nodes, fn (Node $node) => $node instanceof Stmt\Namespace_ && $node->name?->toString() === 'App\\WRLA');
        $class = $namespace === null ? null : (new NodeFinder())->findFirst($namespace->stmts, fn (Node $node) => $node instanceof Stmt\Class_ && $node->name?->name === 'WRLASettings');

        // Do not insert methods into an unexpected or anonymous settings class
        if (!$class instanceof Stmt\Class_) {
            throw new \RuntimeException('Expected App\\WRLA\\WRLASettings in app/WRLA/WRLASettings.php.');
        }

        $method = $class->getMethod('runtime');
        $template = (new ParserFactory())->createForNewestSupportedVersion()->parse(static::read(dirname(__DIR__, 2) . '/stubs/WRLASettings.stub'));
        $template = (new NodeTraverser(new NameResolver()))->traverse($template);
        $templateMethod = (new NodeFinder())->findFirst($template, fn (Node $node) => $node instanceof Stmt\ClassMethod && $node->name->name === 'runtime');
        $templateMethod = static::detached($templateMethod);

        // Add an override without replacing custom routes or navigation
        if ($method === null && $class->extends === null) {
            static::mergeRuntime($runtime, $templateMethod->stmts[0]->expr);
            $runtimeMethod = $templateMethod;
            $runtimeMethod->stmts[0]->expr = $runtime;
            $position = count($class->stmts);
            foreach ($class->stmts as $index => $statement) {
                // Keep properties and constants above the first method
                if ($statement instanceof Stmt\ClassMethod) {
                    $position = $index;
                    break;
                }
            }

            array_splice($class->stmts, $position, 0, [$runtimeMethod]);
        } elseif ($method !== null && $method->isPublic() && $method->isStatic() && $method->params === []
            && count($method->stmts ?? []) === 1 && $method->stmts[0] instanceof Stmt\Return_ && $method->stmts[0]->expr instanceof Array_) {
            // A newly generated class takes application config values before template values
            if ($newSettings) {
                static::mergeRuntime($runtime, $method->stmts[0]->expr);
                $method->stmts[0]->expr = $runtime;
            } else {
                static::mergeRuntime($method->stmts[0]->expr, $runtime);
                static::mergeRuntime($method->stmts[0]->expr, $templateMethod->stmts[0]->expr);
            }
        } else {
            throw new \RuntimeException('Existing WRLASettings inheritance or runtime() logic needs manual migration. No files have been changed.');
        }

        $runtimeMethod = $class->getMethod('runtime');
        WRLASettingsService::validate(static::runtimeShape($runtimeMethod->stmts[0]->expr));

        (new NodeTraverser(new class extends NodeVisitorAbstract {
            private int $callbackDepth = 0;

            public function enterNode(Node $node): ?Node
            {
                // Callback expressions retain normal PHP formatting
                if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                    $this->callbackDepth++;
                }

                // Only configuration arrays need one option per line
                if ($node instanceof Array_) {
                    $node->setAttribute('wrlaConfiguration', $this->callbackDepth === 0);
                }

                // Built-in values never need global namespace prefixes
                if ($node instanceof Expr\ConstFetch && $node->name instanceof Node\Name\FullyQualified && in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true)) {
                    $node->name = new Node\Name(strtolower($node->name->toString()));
                }

                return null;
            }

            public function leaveNode(Node $node): ?Node
            {
                // Resume configuration formatting after each callback
                if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                    $this->callbackDepth--;
                }

                return null;
            }
        }))->traverse([$runtimeMethod]);

        // Keep the runtime method first when completing an earlier partial migration
        if ($method !== null) {
            $position = null;
            foreach ($class->stmts as $index => $statement) {
                // Properties and constants stay in their original positions
                if ($statement instanceof Stmt\ClassMethod && $position === null) {
                    $position = $index;
                }

                // Relocate only the runtime method, preserving other member order
                if ($statement === $runtimeMethod && $index !== $position) {
                    array_splice($class->stmts, $index, 1);
                    array_splice($class->stmts, $position, 0, [$runtimeMethod]);
                    break;
                }
            }
        }

        $printer = new class extends Standard {
            protected function pExpr_Array(Expr\Array_ $node): string
            {
                // Keep callback expressions and intentionally empty lists compact
                if ($node->items === [] || !$node->getAttribute('wrlaConfiguration', false)) {
                    return parent::pExpr_Array($node);
                }

                $long = $node->getAttribute('kind') === Expr\Array_::KIND_LONG;

                return ($long ? 'array(' : '[') . $this->pCommaSeparatedMultiline($node->items, true) . $this->nl . ($long ? ')' : ']');
            }
        };
        $updated = $printer->printFormatPreserving($nodes, $original, $parser->getTokens());

        // Give the generated method exactly one blank line below it
        $updatedNodes = $parser->parse($updated);
        $updatedClass = (new NodeFinder())->findFirst($updatedNodes, fn (Node $node) => $node instanceof Stmt\Class_ && $node->name?->name === 'WRLASettings');
        $end = $updatedClass->getMethod('runtime')->getEndFilePos() + 1;
        $following = substr($updated, $end);
        $whitespace = substr($following, 0, strspn($following, " \t\r\n"));
        $indent = substr($whitespace, strrpos($whitespace, "\n") + 1);
        $newline = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $updated = substr($updated, 0, $end) . $newline . $newline . $indent . ltrim($following, " \t\r\n");

        return $updated;
    }

    private static function runtimeShape(Array_ $array): array
    {
        $shape = [];
        foreach ($array->items as $item) {
            // Dynamic map keys require a developer's explicit migration
            if ($item === null || $item->key === null || $item->unpack) {
                throw new \RuntimeException('runtime() uses implicit or unpacked map keys. Complete the runtime settings manually.');
            }

            $key = static::key($item->key);
            $pathValue = $item->value;
            $shape[$key] = $pathValue instanceof Array_ && static::isAssociative($pathValue) ? static::runtimeShape($pathValue) : ($pathValue instanceof Array_ ? [] : true);
        }

        return $shape;
    }

    private static function mergeRuntime(Array_ $existing, Array_ $incoming, string $prefix = ''): void
    {
        foreach ($incoming->items as $item) {
            $key = static::key($item->key);
            $match = null;
            foreach ($existing->items as $candidate) {
                // Dynamic existing runtime keys cannot be safely merged
                if ($candidate === null || $candidate->key === null || $candidate->unpack) {
                    throw new \RuntimeException('Existing runtime() array uses implicit or unpacked keys. Merge the migrated callbacks manually.');
                }

                // Match resolved keys without evaluating either setting
                if (static::key($candidate->key) === $key) {
                    $match = $candidate;
                }
            }

            // Describe uncommented groups without replacing application comments
            if ($match !== null && $prefix === '' && $match->getComments() === [] && $item->getComments() !== []) {
                $match->setAttribute('comments', $item->getComments());
            }

            // Append only genuinely new settings
            if ($match === null) {
                $existing->items[] = $item;
            } elseif ($match->value instanceof Array_ && $item->value instanceof Array_
                && (static::isRequiredBranch($prefix . $key) || ($match->value->items !== [] && static::isAssociative($match->value) && static::isAssociative($item->value)))) {
                static::mergeRuntime($match->value, $item->value, $prefix . $key . '.');
            }
        }
    }

    private static function isRequiredBranch(string $path): bool
    {
        return in_array($path, ['default_manageable_model_permissions', 'database_browser.permissions'], true)
            || array_filter(WRLASettingsService::RUNTIME_KEYS, fn (string $key) => str_starts_with($key, $path . '.')) !== [];
    }

    private static function editable(string $source): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $original = $parser->parse($source) ?? [];
        $nodes = (new NodeTraverser(new CloningVisitor()))->traverse($original);

        return [$parser, $original, $nodes];
    }

    private static function isAssociative(Array_ $array): bool
    {
        foreach ($array->items as $item) {
            // Lists must be replaced as a whole, not merged by numeric position
            if ($item === null || $item->key === null || $item->unpack || is_int(static::key($item->key))) {
                return false;
            }
        }

        return true;
    }

    private static function change(array &$changes, string $relative, ?string $before, ?string $after): void
    {
        // Omit unchanged files so repeat upgrades are a no-op
        if ($before !== $after) {
            $changes[$relative] = ['before' => $before, 'after' => $after];
        }
    }

    private static function path(string $basePath, string $relative): string
    {
        // Constrain all writes to this application
        if (preg_match('~(^[/\\\\]|^[a-zA-Z]:|(^|[/\\\\])\\.\\.([/\\\\]|$))~', $relative)) {
            throw new \RuntimeException('Invalid upgrade path: ' . $relative);
        }

        $path = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $ancestor = $path;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $ancestor = dirname($ancestor);
        }

        // Replacing a symlink would lose the link rather than migrate its target
        if (is_link($ancestor)) {
            throw new \RuntimeException('Linked upgrade paths need manual migration: ' . $relative);
        }

        $root = realpath($basePath);
        $resolved = realpath($ancestor);

        // Refuse linked paths escaping the application's root
        if ($root === false || $resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Upgrade path leaves the application directory: ' . $relative);
        }

        return $path;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        // File errors must not become empty replacement files
        if ($contents === false) {
            throw new \RuntimeException('Unable to read ' . $path);
        }

        return $contents;
    }

    private static function restore(string $path, ?string $contents): void
    {
        // A null snapshot represents an absent file
        if ($contents === null) {
            // Delete only the individually planned file
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException('Unable to remove ' . $path);
            }
        } else {
            static::write($path, $contents);
        }
    }

    protected static function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        static::ensureDirectory($directory);
        $temporary = tempnam($directory, '.wrla-');

        // Never fall back to writing the target directly
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary file in ' . $directory);
        }

        try {
            // Detect partial writes before replacing any original
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new \RuntimeException('Unable to write ' . $path);
            }

            // Preserve existing source permissions
            if (!chmod($temporary, is_file($path) ? fileperms($path) & 0777 : 0644)) {
                throw new \RuntimeException('Unable to set permissions on ' . $path);
            }

            // Rename in the same directory for atomic file replacement
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Unable to replace ' . $path);
            }
        } finally {
            // Remove only this write's unused temporary file
            if (is_file($temporary) && !unlink($temporary)) {
                throw new \RuntimeException('Unable to remove temporary file ' . $temporary);
            }
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        // Locking and a newly generated settings class may need directories
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create ' . $directory);
        }
    }
}
