<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

if (!defined('ABSPATH')) {
    exit();
}

/** Walks the code sent to execute-php and collects the rules of analysis.php that apply to it. */
// @mago-expect lint:file-name -- Novamira files are named in kebab case.
final class AnalysisVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    public array $rules = [];

    private int $loops = 0;

    /** @var array<int, true> Closures given to array_map() and the like, which run once per item. */
    private array $loop_bodies = [];

    /** Strings inside a concatenation are read once, as the whole concatenation. */
    private int $concats = 0;

    public function enterNode(Node $node)
    {
        foreach (loop_bodies($node) as $body) {
            $this->loop_bodies[spl_object_id($body)] = true;
        }
        if ($this->is_loop_body($node)) {
            $this->loops++;
        }
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            if ($this->concats === 0) {
                $this->add(sql_rules(concat_text($node)));
            }
            $this->concats++;
            return null;
        }
        $in_concat_string =
            $this->concats > 0
            && ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\InterpolatedString);
        if (!$in_concat_string) {
            $this->add(node_rules($node));
        }
        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($this->is_loop_body($node)) {
            $this->loops--;
        }
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $this->concats--;
        }
        return null;
    }

    private function is_loop_body(Node $node): bool
    {
        return is_loop($node) || ($this->loop_bodies[spl_object_id($node)] ?? false);
    }

    /** @param list<string> $rules */
    private function add(array $rules): void
    {
        foreach ($rules as $rule) {
            $this->rules[] = $this->loops > 0 ? repeated_rule($rule) : $rule;
        }
    }
}
