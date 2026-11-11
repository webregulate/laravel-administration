<?php

namespace WebRegulate\LaravelAdministration\Services\Upgrades;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Services\WRLASettingsService;

class ConfigReferenceRewriter
{
    public static function rewrite(string $source): string
    {
        // Avoid parsing files unrelated to WRLA config or settings
        if (!str_contains($source, 'wr-laravel-administration') && !str_contains($source, 'wrla.') && !str_contains($source, "'wrla'") && !str_contains($source, '"wrla"') && !str_contains($source, 'WRLASettings')) {
            return $source;
        }

        // Rewrite Blade text before parsing so the printer preserves PHP boundaries
        $source = implode('', array_map(
            fn (array|string $token): string => is_array($token)
                ? ($token[0] === T_INLINE_HTML ? static::inline($token[1]) : $token[1])
                : $token,
            token_get_all($source),
        ));

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $original = $parser->parse($source) ?? [];
        $nodes = (new NodeTraverser(new CloningVisitor(), new NameResolver(null, ['replaceNodes' => false])))->traverse($original);
        $nodes = (new NodeTraverser(new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): ?Node
            {
                foreach ($node->getComments() as $index => $comment) {
                    $text = str_replace('wr-laravel-administration.', 'wrla.', $comment->getText());
                    $text = strtr($text, [
                        '// File manager - Enabled via `wrla.file_manager.enabled` configuration' => '// File manager - Access is configured in runtime().',
                        '// File manager - Enabled by file_manager.enabled in config/wrla.php.' => '// File manager - Access is configured in runtime().',
                        '// Scheduler & Jobs - Access via `wrla.scheduler.enabled` configuration' => '// Scheduler & Jobs - Access is configured in runtime().',
                        '// Database schema viewer - Enabled via `wrla.database_schema_viewer.enabled` configuration' => '// Database schema viewer - Access is configured in runtime().',
                        '// Database schema viewer - Enabled via `wrla.database_schema_viewer.enabled`' => '// Database schema viewer - Access is configured in runtime().',
                        '// View logs - Set via `wrla.logs.current` configuration' => '// View logs - Access is configured in runtime(); select the viewer in config/wrla.php.',
                        '// View logs - Viewer selected by logs.current in config/wrla.php.' => '// View logs - Access is configured in runtime(); select the viewer in config/wrla.php.',
                        '// Show the documentation link for these users.' => '// Documentation access and top-bar link visibility.',
                        '// Build user_id identifier for wrlaUserData, if not set will default to simply $user->id' => '// Identifier linking the User model to its WRLA user data.',
                        '// Allow adhoc running, use either: bool, fn($wrlaUserData): bool, null (fallback using WRLAHelper::userIsDev())' => '// null uses scheduler access; bool or callback sets an independent rule.',
                    ]);

                    // Keep published, commented-out navigation examples usable
                    if (str_starts_with($text, '// NavigationItem::')) {
                        $text = ConfigReferenceRewriter::inline($text);
                    }

                    // Keep published comments aligned with each setting's owner
                    if ($text !== $comment->getText()) {
                        $comments = $node->getComments();
                        $class = $comment instanceof Comment\Doc ? Comment\Doc::class : Comment::class;
                        $comments[$index] = new $class($text);
                        $node->setAttribute('comments', $comments);
                    }
                }

                // Published config filenames change, not asset or view namespaces
                if ($node instanceof Scalar\String_ && ($node->value === 'wr-laravel-administration.php' || str_ends_with($node->value, '/wr-laravel-administration.php') || str_ends_with($node->value, '\\wr-laravel-administration.php'))) {
                    $node->value = substr($node->value, 0, -strlen('wr-laravel-administration.php')) . 'wrla.php';
                }

                return ConfigReferenceRewriter::call($node);
            }
        }))->traverse($nodes);

        return (new Standard(['newline' => str_contains($source, "\r\n") ? "\r\n" : "\n"]))->printFormatPreserving($nodes, $original, $parser->getTokens());
    }

    public static function call(Node $node): ?Node
    {
        $operation = null;

        // Laravel's config helper accepts both reads and array assignments
        if ($node instanceof Expr\FuncCall && static::functionNamed($node, 'config')) {
            $operation = isset($node->args[0]) && $node->args[0] instanceof Node\Arg && $node->args[0]->value instanceof Expr\Array_ ? 'set' : 'get';
        } elseif ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $class = ($node->class->getAttribute('resolvedName') ?? $node->class)->toString();

            // Resolve imported Config facade aliases without altering other classes
            if ($class === 'Illuminate\\Support\\Facades\\Config' || $class === 'Config') {
                $operation = strtolower($node->name->name);
            }
        } elseif ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && $node->var instanceof Expr\FuncCall) {
            $function = $node->var;
            $repository = static::functionNamed($function, 'config') && $function->args === [];
            $repository = $repository || (static::functionNamed($function, 'app') && isset($function->args[0]) && $function->args[0] instanceof Node\Arg && $function->args[0]->value instanceof Scalar\String_ && $function->args[0]->value->value === 'config');

            // Support config()->get() and app('config')->get()
            if ($repository) {
                $operation = strtolower($node->name->name);
            }
        }

        // Leave unrelated calls and repository access alone
        if (!in_array($operation, ['get', 'set', 'has'], true) || !isset($node->args[0]) || !$node->args[0] instanceof Node\Arg) {
            return null;
        }

        $key = $node->args[0]->value;

        // Array assignment keys remain static config keys, not runtime reads
        if ($operation === 'set' && $key instanceof Expr\Array_) {
            foreach ($key->items as $item) {
                // Only explicit keys can contain the config namespace
                if ($item?->key !== null) {
                    static::assertScalarAssignment($item->key, $item->value);
                    [$renamed] = static::key($item->key);
                    $item->key = $renamed;
                }
            }

            return $node;
        }

        // Inspect nested section writes before their keys are rewritten
        if ($operation === 'set') {
            static::assertScalarAssignment($key, $node->args[1]->value ?? null);
        }

        [$prefix, $complete] = static::prefix($key);

        // The root config repository exists before and after the namespace rename
        if ($operation === 'has' && $complete && in_array($prefix, ['wr-laravel-administration', 'wrla'], true)) {
            $node->args[0]->value = new Scalar\String_('wrla');

            return $node;
        }

        [$renamed, $runtime] = static::key($key);

        $node->args[0]->value = $renamed;

        // Runtime reads require explicit settings, not the original config fallback
        if ($operation === 'get' && $runtime) {
            $accessChecks = [
                'file_manager.enabled' => 'fileManagerEnabled',
                'logs.enabled' => 'logsEnabled',
                'documentation.enabled' => 'documentationEnabled',
            ];
            [$relative, $complete] = static::prefix($renamed);

            // Former boolean-only reads must evaluate their newly supported callbacks
            if ($complete && isset($accessChecks[$relative])) {
                return new Expr\StaticCall(
                    new Node\Name\FullyQualified(WRLAHelper::class),
                    $accessChecks[$relative],
                    [],
                    ['comments' => $node->getComments()],
                );
            }

            return new Expr\StaticCall(
                new Node\Name\FullyQualified(WRLASettingsService::class),
                'get',
                [$node->args[0]],
                ['comments' => $node->getComments()],
            );
        }

        // Existence checks for moved callbacks need an explicit application decision
        if ($operation === 'has' && $runtime) {
            throw new \RuntimeException('A Config::has() check includes runtime settings. Replace it with an explicit WRLASettingsService::get() check before upgrading.');
        }

        return $node;
    }

    private static function assertScalarAssignment(Expr $key, ?Expr $value): void
    {
        [$prefix, $complete] = static::prefix($key);
        $relative = preg_replace('/^(wr-laravel-administration|wrla)(?:\\.|$)/', '', $prefix, 1, $matched);

        // Unrelated config assignments need no runtime inspection
        if ($matched === 0) {
            return;
        }

        foreach (WRLASettingsService::RUNTIME_KEYS as $runtimeKey) {
            // Runtime values must be changed in the settings method, not config
            if ($relative === $runtimeKey || str_starts_with($relative, $runtimeKey . '.')) {
                throw new \RuntimeException('A config assignment targets runtime settings. Move the rule into WRLASettings::runtime() before upgrading.');
            }

            // Inspect literal parent arrays without executing the assigned value
            if ($relative === '' || str_starts_with($runtimeKey, $relative . '.')) {
                // Dynamic mixed-section writes cannot be safely split
                if (!$complete || !$value instanceof Expr\Array_) {
                    throw new \RuntimeException('A config assignment may include runtime settings. Assign scalar settings individually before upgrading.');
                }

                foreach ($value->items as $item) {
                    // Unpacked or computed keys hide the actual setting owner
                    if ($item === null || $item->unpack || !$item->key instanceof Scalar\String_) {
                        throw new \RuntimeException('A computed config assignment needs manual migration.');
                    }

                    static::assertScalarAssignment(new Scalar\String_($prefix . '.' . $item->key->value), $item->value);
                }

                return;
            }
        }
    }

    private static function functionNamed(Expr\FuncCall $call, string $name): bool
    {
        return $call->name instanceof Node\Name
            && ($call->name->toString() === $name || $call->name->getAttribute('resolvedName')?->toString() === $name);
    }

    /**
     * @return array{Expr, bool}
     */
    private static function key(Expr $expression): array
    {
        [$prefix, $complete] = static::prefix($expression);
        $legacy = $prefix === 'wr-laravel-administration' || str_starts_with($prefix, 'wr-laravel-administration.');
        $namespace = $legacy ? 'wr-laravel-administration' : 'wrla';

        // Whole-config reads span two distinct setting owners
        if ($prefix === $namespace && $complete) {
            // New-namespace reads explicitly address the scalar configuration
            if (!$legacy) {
                return [$expression, false];
            }

            throw new \RuntimeException('A whole legacy WRLA config read or assignment spans scalar and runtime settings. Read individual settings explicitly before upgrading.');
        }

        // Ignore keys from other configuration files
        if (!str_starts_with($prefix, $namespace . '.')) {
            $unresolved = (new NodeFinder())->findFirst([$expression], fn (Node $node) => $node instanceof Scalar\String_
                && ($node->value === 'wr-laravel-administration' || str_starts_with($node->value, 'wr-laravel-administration.') || str_starts_with($node->value, 'wrla.')));

            // A factory such as sprintf() hides the effective key from static rewriting
            if ($unresolved !== null) {
                throw new \RuntimeException('A computed WRLA config key needs manual migration. Use a literal key or WRLASettingsService::get() explicitly.');
            }

            return [$expression, false];
        }

        $key = substr($prefix, strlen($namespace) + 1);
        $runtime = false;
        foreach (WRLASettingsService::RUNTIME_KEYS as $runtimeKey) {
            // Parent sections contain both scalar and runtime settings
            if ($complete && str_starts_with($runtimeKey, $key . '.')) {
                // Existing new-namespace section reads continue to read scalar config
                if (!$legacy) {
                    return [$expression, false];
                }

                throw new \RuntimeException("The legacy WRLA {$key} section spans scalar and runtime settings. Read its individual settings explicitly before upgrading.");
            }

            // Exact settings and their descendants belong exclusively to runtime
            if ($key === $runtimeKey || str_starts_with($key, $runtimeKey . '.')) {
                $runtime = true;
                break;
            }

            // A computed key spanning static and runtime settings cannot be guessed
            if (!$complete && str_starts_with($runtimeKey, $key)) {
                throw new \RuntimeException('A computed WRLA config key may include runtime settings. Use WRLASettingsService::get() explicitly before upgrading.');
            }
        }

        // Rewrite a read to a relative runtime key
        if ($runtime) {
            return [$complete ? new Scalar\String_($key) : static::strip($expression, strlen($namespace) + 1), true];
        }

        // Keep scalar reads and config assignments in the new namespace
        if ($legacy) {
            // Keep literal keys readable rather than generating redundant concatenations
            if ($complete) {
                return [new Scalar\String_('wrla.' . $key), false];
            }

            $remaining = static::strip($expression, strlen($namespace));

            return [new Expr\BinaryOp\Concat(new Scalar\String_('wrla'), $remaining), false];
        }

        return [$expression, false];
    }

    private static function prefix(Expr $expression): array
    {
        // Literal strings are complete keys
        if ($expression instanceof Scalar\String_) {
            return [$expression->value, true];
        }

        // Interpolation has a known prefix before the first dynamic segment
        if ($expression instanceof Scalar\InterpolatedString) {
            $first = $expression->parts[0] ?? null;

            return [$first instanceof Node\InterpolatedStringPart ? $first->value : '', false];
        }

        // Constant concatenations can be resolved without executing PHP
        if ($expression instanceof Expr\BinaryOp\Concat) {
            [$left, $complete] = static::prefix($expression->left);

            // Stop before any dynamic part of the key
            if (!$complete) {
                return [$left, false];
            }

            [$right, $complete] = static::prefix($expression->right);

            return [$left . $right, $complete];
        }

        return ['', false];
    }

    private static function strip(Expr $expression, int $length): Expr
    {
        // Produce new nodes so reads and writes never mutate a shared key expression
        if ($expression instanceof Scalar\String_) {
            return new Scalar\String_(substr($expression->value, $length));
        }

        // Remove the namespace from a dynamic, interpolated key
        if ($expression instanceof Scalar\InterpolatedString && $expression->parts[0] instanceof Node\InterpolatedStringPart) {
            $parts = $expression->parts;
            $parts[0] = new Node\InterpolatedStringPart(substr($parts[0]->value, $length));

            return new Scalar\InterpolatedString($parts);
        }

        // Strip a namespace even when it spans several literal concatenations
        if ($expression instanceof Expr\BinaryOp\Concat) {
            [$left] = static::prefix($expression->left);
            $remove = min(strlen($left), $length);

            return new Expr\BinaryOp\Concat(static::strip($expression->left, $remove), $length > $remove ? static::strip($expression->right, $length - $remove) : $expression->right);
        }

        throw new \RuntimeException('Unable to safely rewrite a computed WRLA config key.');
    }

    public static function inline(string $source): string
    {
        $source = preg_replace_callback('~(@php\b(?!\s*\())(.*?)(@endphp)~s', function (array $match): string {
            return $match[1] . substr(static::rewrite('<?php ' . $match[2]), 6) . $match[3];
        }, $source) ?? throw new \RuntimeException('Unable to parse a Blade @php block.');

        $getter = '(?<![a-zA-Z0-9_\\\\])(?:\\\\?config\s*\(|(?:\\\\?Illuminate\\\\Support\\\\Facades\\\\)?Config::get\s*\(|\\\\?config\s*\(\s*\)\s*->\s*get\s*\()\s*';

        $count = preg_match_all('~(' . $getter . ')([\'"])(wr-laravel-administration(?:\.[^\'"\r\n]*)?|wrla\.[^\'"\r\n]*)\2~', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        // Pattern errors must not leave a partially rewritten template
        if ($count === false) {
            throw new \RuntimeException('Unable to find Blade configuration references.');
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $printer = new Standard();
        foreach (array_reverse($matches) as $match) {
            $nodes = $parser->parse('<?php return ' . $match[2][0] . $match[3][0] . $match[2][0] . ';');
            [$key, $runtime] = static::key($nodes[0]->expr);
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);

            // Rewrite the whole runtime call so obsolete fallback arguments disappear
            if ($runtime) {
                $end = static::callEnd($source, $end);
                $call = substr($source, $start, $end - $start);
                $rewritten = static::rewrite('<?php return ' . $call . ';');
                $replacement = substr($rewritten, strlen('<?php return '), -1);
            } else {
                $replacement = $match[1][0] . $printer->prettyPrintExpr($key);
            }

            $source = substr_replace($source, $replacement, $start, $end - $start);
        }

        return $source;
    }

    private static function callEnd(string $source, int $offset): int
    {
        $depth = 1;
        $tokens = token_get_all('<?php ' . substr($source, $offset));
        array_shift($tokens);

        foreach ($tokens as $token) {
            // Strings and comments are tokens, not structural parentheses
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            }

            $offset += strlen(is_array($token) ? $token[1] : $token);

            // Return just after the getter's closing parenthesis
            if ($depth === 0) {
                return $offset;
            }
        }

        throw new \RuntimeException('An unterminated Blade config call needs manual migration.');
    }
}
