<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Reader;

use NeuronAI\RAG\DataLoader\ReaderInterface;
use Rap2hpoutre\FastExcel\FastExcel;

final class XlsxReader implements ReaderInterface
{
    public static function getText(string $filePath, array $options = []): string
    {
        $rows = (new FastExcel())->import($filePath)->toArray();
        if (!is_array($rows) || $rows === []) {
            return '';
        }

        $lines = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $parts = [];
            foreach ($row as $key => $value) {
                $label = trim((string)$key);
                $text = trim((string)$value);
                if ($label === '' || $text === '') {
                    continue;
                }
                $parts[] = $label . ':' . $text;
            }

            if ($parts !== []) {
                $lines[] = sprintf('Row %d | %s', $index + 1, implode(' | ', $parts));
            }
        }

        return implode("\n", $lines);
    }
}
