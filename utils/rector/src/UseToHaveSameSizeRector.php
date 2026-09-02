<?php

declare(strict_types=1);

namespace Utils\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Replace `->toHaveCount(count($x))` with `->toHaveSameSize($x)` in Pest expectations.
 */
final class UseToHaveSameSizeRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace toHaveCount(count($x)) with toHaveSameSize($x)',
            [
                new CodeSample(
                    'expect($items)->toHaveCount(count($other));',
                    'expect($items)->toHaveSameSize($other);',
                ),
            ]
        );
    }

    /** @return array<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        assert($node instanceof MethodCall);

        if (! $this->isName($node->name, 'toHaveCount')) {
            return null;
        }

        if (count($node->args) !== 1) {
            return null;
        }

        $arg = $node->args[0];

        if (! $arg->value instanceof FuncCall) {
            return null;
        }

        if (! $this->isName($arg->value, 'count')) {
            return null;
        }

        if (count($arg->value->args) !== 1) {
            return null;
        }

        $node->name = new Identifier('toHaveSameSize');
        $node->args = [$arg->value->args[0]];

        return $node;
    }
}
