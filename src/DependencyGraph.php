<?php
declare(strict_types=1);

namespace DevDelivery;

final class DependencyGraph
{
    /** Predecessors precede their consumers; a cycle is returned with its starting node repeated. */
    public static function resolve(array $upstream, array $roots): array
    {
        $visited = [];
        $order = [];
        foreach ($roots as $root) {
            if (isset($visited[$root])) {
                continue;
            }
            $stack = [[$root, 0]];
            $active = [$root => 0];
            while ($stack) {
                $last = count($stack) - 1;
                [$node, $index] = $stack[$last];
                $predecessor = $upstream[$node][$index] ?? null;
                if ($predecessor === null) {
                    $order[] = $node;
                    $visited[$node] = true;
                    unset($active[$node]);
                    array_pop($stack);
                    continue;
                }
                $stack[$last][1]++;
                if (isset($active[$predecessor])) {
                    $cycle = [...array_column(array_slice($stack, $active[$predecessor]), 0), $predecessor];
                    return ['order' => [], 'cycle' => $cycle];
                }
                if (!isset($visited[$predecessor])) {
                    $active[$predecessor] = count($stack);
                    $stack[] = [$predecessor, 0];
                }
            }
        }
        return ['order' => $order, 'cycle' => []];
    }
}
