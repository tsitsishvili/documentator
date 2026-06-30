<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;

/**
 * Shared, best-effort PHP source analysis for extraction strategies.
 * Parsing failures return null so one unusual controller never breaks generation.
 */
final class SourceAnalyzer
{
    private readonly Parser $parser;

    /** @var array<string, array<int, Node>|null> parsed+name-resolved AST per file */
    private array $cache = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /**
     * @return array<int, Node>|null
     */
    public function astForFile(string $file): ?array
    {
        if (array_key_exists($file, $this->cache)) {
            return $this->cache[$file];
        }

        try {
            $ast = $this->parser->parse((string) file_get_contents($file));

            if ($ast === null) {
                return $this->cache[$file] = null;
            }

            return $this->cache[$file] = (new NodeTraverser(new NameResolver))->traverse($ast);
        } catch (Throwable) {
            return $this->cache[$file] = null;
        }
    }

    public function methodNode(ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
        $file = $method->getFileName();
        $ast = is_string($file) ? $this->astForFile($file) : null;

        if ($ast === null) {
            return null;
        }

        $node = (new NodeFinder)->findFirst(
            $ast,
            fn (Node $node) => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $method->getName()
                && $node->getStartLine() <= $method->getStartLine()
                && $node->getEndLine() >= $method->getStartLine(),
        );

        return $node instanceof Node\Stmt\ClassMethod ? $node : null;
    }

    public function functionLikeNode(ReflectionFunctionAbstract $function): Node\Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction|null
    {
        if ($function instanceof ReflectionMethod) {
            return $this->methodNode($function);
        }

        if (! $function instanceof ReflectionFunction) {
            return null;
        }

        $file = $function->getFileName();
        $ast = is_string($file) ? $this->astForFile($file) : null;

        if ($ast === null) {
            return null;
        }

        $startLine = $function->getStartLine();
        $endLine = $function->getEndLine();
        $candidates = (new NodeFinder)->find(
            $ast,
            fn (Node $node) => ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)
                && $node->getStartLine() <= $startLine
                && $node->getEndLine() >= $endLine,
        );

        usort(
            $candidates,
            fn (Node $a, Node $b) => ($a->getEndLine() - $a->getStartLine()) <=> ($b->getEndLine() - $b->getStartLine()),
        );

        $node = $candidates[0] ?? null;

        return $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction ? $node : null;
    }

    public function firstReturnExpression(ReflectionFunctionAbstract $function): ?Node\Expr
    {
        $functionNode = $this->functionLikeNode($function);

        if ($functionNode === null) {
            return null;
        }

        if ($functionNode instanceof Node\Expr\ArrowFunction) {
            return $functionNode->expr;
        }

        $return = (new NodeFinder)->findFirst(
            $functionNode,
            fn (Node $node) => $node instanceof Node\Stmt\Return_ && $node->expr instanceof Node\Expr,
        );

        return $return instanceof Node\Stmt\Return_ && $return->expr instanceof Node\Expr
            ? $return->expr
            : null;
    }
}
