<?php

declare(strict_types=1);

namespace Utils\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use Rector\PhpParser\Enum\NodeGroup;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Chain consecutive `expect()` calls using Pest's `->and()` method.
 *
 * Before:
 *   expect($a)->toBe(1);
 *   expect($b)->toBe(2);
 *
 * After:
 *   expect($a)->toBe(1)
 *       ->and($b)->toBe(2);
 */
final class ChainExpectCallsRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Chain consecutive expect() calls using ->and()',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
expect($a)->toBe(1);
expect($b)->toBe(2);
CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
expect($a)->toBe(1)
    ->and($b)->toBe(2);
CODE_SAMPLE,
                ),
            ]
        );
    }

    /** @return array<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return NodeGroup::STMTS_AWARE;
    }

    public function refactor(Node $node): ?Node
    {
        /** @var Stmt[] $stmts */
        $stmts = $node->stmts;

        if ($stmts === null || count($stmts) < 2) {
            return null;
        }

        $hasChanged = false;

        // Walk backwards so index removal doesn't shift subsequent indices
        for ($i = count($stmts) - 1; $i >= 1; $i--) {
            $current = $stmts[$i];
            $previous = $stmts[$i - 1];

            if (! $current instanceof Expression || ! $previous instanceof Expression) {
                continue;
            }

            if (! $this->isExpectChain($current->expr) || ! $this->isExpectChain($previous->expr)) {
                continue;
            }

            // Don't chain across blank lines or comments — they signal intentional grouping
            if ($this->hasBlankLineBetween($previous, $current) || $current->getComments() !== []) {
                continue;
            }

            // Extract the expect() args from the current (second) call
            $expectArgs = $this->getExpectArgs($current->expr);

            if ($expectArgs === null) {
                continue;
            }

            // Build ->and(...) call on top of the previous chain
            $andCall = new MethodCall($previous->expr, new Identifier('and'), $expectArgs);

            // Graft the remaining method chain from the current expression onto ->and(...)
            $previous->expr = $this->graftChain($current->expr, $andCall);

            array_splice($stmts, $i, 1);
            $hasChanged = true;
        }

        if (! $hasChanged) {
            return null;
        }

        $node->stmts = $stmts;

        return $node;
    }

    /**
     * Check if there is a blank line between two statements (indicating intentional grouping).
     */
    private function hasBlankLineBetween(Expression $previous, Expression $current): bool
    {
        $previousEndLine = $previous->getEndLine();
        $currentStartLine = $current->getStartLine();

        if ($previousEndLine === -1 || $currentStartLine === -1) {
            return false;
        }

        // If the current statement starts more than 1 line after the previous ends,
        // there's a blank line between them
        return ($currentStartLine - $previousEndLine) > 1;
    }

    /**
     * Check if an expression is an expect() call chain (method calls rooted in expect()).
     */
    private function isExpectChain(Expr $expr): bool
    {
        $root = $this->findRootCall($expr);

        return $root instanceof FuncCall && $this->isName($root, 'expect');
    }

    /**
     * Walk down the ->var chain to find the root call.
     */
    private function findRootCall(Expr $expr): Expr
    {
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        return $expr;
    }

    /**
     * Extract the arguments from the root expect() FuncCall.
     *
     * @return Arg[]|null
     */
    private function getExpectArgs(Expr $expr): ?array
    {
        $root = $this->findRootCall($expr);

        if (! $root instanceof FuncCall || ! $this->isName($root, 'expect')) {
            return null;
        }

        return $root->args;
    }

    /**
     * Replace the root expect() FuncCall in $source chain with $replacement.
     *
     * Given: expect($b)->toBe(2)  and replacement: ->and($b)
     * Result: ->and($b)->toBe(2)
     */
    private function graftChain(Expr $source, Expr $replacement): Expr
    {
        if ($source instanceof FuncCall && $this->isName($source, 'expect')) {
            return $replacement;
        }

        if ($source instanceof MethodCall) {
            $source->var = $this->graftChain($source->var, $replacement);

            return $source;
        }

        return $replacement;
    }
}
