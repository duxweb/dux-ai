<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

final class SourceId
{
    public static function assetName(int $knowledgeId, int $dataId): string
    {
        return sprintf('k%d_d%d', $knowledgeId, $dataId);
    }

    public static function format(string $sourceType, string $sourceName): string
    {
        return $sourceType . '::' . $sourceName;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $id): ?array
    {
        $id = trim($id);
        if ($id === '' || !str_contains($id, '::')) {
            return null;
        }
        [$type, $name] = explode('::', $id, 2);
        $type = trim($type);
        $name = trim($name);
        return ($type !== '' && $name !== '') ? [$type, $name] : null;
    }
}

