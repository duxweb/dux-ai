<?php

declare(strict_types=1);

namespace App\Ai\Service\AIFlow;

final class NodeSorter
{
    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array{map: array<string, array<string, mixed>>, orders: array<string, float>}
     */
    public static function normalizeNodes(array $nodes): array
    {
        $map = [];
        $orders = [];

        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = self::resolveNodeId($node, $index);
            $normalized = $node;
            $normalized['id'] = $id;
            $map[$id] = $normalized;
            $orders[$id] = self::resolveNodeOrder($normalized, $index);
        }

        return [
            'map' => $map,
            'orders' => $orders,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, float> $orders
     * @param array<int, array<string, mixed>> $edges
     * @return array<int, array<string, mixed>>
     */
    public static function orderNodes(array $nodeMap, array $orders, array $edges): array
    {
        if ($nodeMap === []) {
            return [];
        }

        $graph = [];
        $incoming = [];
        $hasValidEdge = false;
        $connectedNodes = [];

        foreach ($nodeMap as $id => $_node) {
            $graph[$id] = [];
            $incoming[$id] = 0;
        }

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            if (!is_string($source) || !is_string($target)) {
                continue;
            }
            if (!isset($nodeMap[$source], $nodeMap[$target])) {
                continue;
            }

            $graph[$source][] = $target;
            $incoming[$target]++;
            $hasValidEdge = true;
            $connectedNodes[$source] = true;
            $connectedNodes[$target] = true;
        }

        if (!$hasValidEdge) {
            return [];
        }

        foreach (array_keys($nodeMap) as $id) {
            if (!isset($connectedNodes[$id])) {
                unset($nodeMap[$id], $graph[$id], $incoming[$id]);
            }
        }

        if ($nodeMap === []) {
            return [];
        }

        $queue = [];
        foreach ($incoming as $id => $count) {
            if ($count === 0) {
                $queue[] = ['id' => $id, 'order' => $orders[$id] ?? 0];
            }
        }

        $orderedIds = [];
        while ($queue !== []) {
            usort($queue, static fn ($a, $b) => ($a['order'] <=> $b['order']));
            $current = array_shift($queue);
            if (!$current) {
                break;
            }
            $orderedIds[] = $current['id'];
            foreach ($graph[$current['id']] as $targetId) {
                $incoming[$targetId]--;
                if ($incoming[$targetId] === 0) {
                    $queue[] = ['id' => $targetId, 'order' => $orders[$targetId] ?? 0];
                }
            }
        }

        if (count($orderedIds) < count($nodeMap)) {
            $remaining = array_diff(array_keys($nodeMap), $orderedIds);
            usort($remaining, static function ($a, $b) use ($orders) {
                $orderA = $orders[$a] ?? 0;
                $orderB = $orders[$b] ?? 0;
                return $orderA <=> $orderB;
            });
            $orderedIds = array_merge($orderedIds, $remaining);
        }

        return array_map(static fn ($id) => $nodeMap[$id], $orderedIds);
    }

    private static function resolveNodeId(array $node, int $index): string
    {
        $id = $node['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        return sprintf('__node_%d', $index);
    }

    private static function resolveNodeOrder(array $node, int $index): float
    {
        $order = $node['order'] ?? null;
        if (is_numeric($order)) {
            return (float)$order;
        }

        $position = $node['ui']['position']['x'] ?? null;
        if (is_numeric($position)) {
            return (float)$position;
        }

        return (float)$index;
    }
}
