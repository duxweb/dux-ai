<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

use Core\Handlers\ExceptionBusiness;

final class QaCsvParser
{
    /**
     * @return array<int, array{question: string, answer: string}>
     */
    public static function parse(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new ExceptionBusiness('无法解析 CSV 文件');
        }
        fwrite($resource, $contents);
        rewind($resource);

        $results = [];
        $rowIndex = 0;
        while (($row = fgetcsv($resource)) !== false) {
            $rowIndex++;
            if (!$row || count($row) < 2) {
                continue;
            }
            $question = trim((string)$row[0]);
            $answer = trim((string)$row[1]);
            if ($rowIndex === 1 && strcasecmp($question, 'question') === 0 && strcasecmp($answer, 'answer') === 0) {
                continue;
            }

            if ($question === '' || $answer === '') {
                continue;
            }

            $results[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        fclose($resource);

        return $results;
    }
}

