<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Reader;

use Core\Handlers\ExceptionBusiness;
use NeuronAI\RAG\DataLoader\ReaderInterface;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Throwable;

final class DocxReader implements ReaderInterface
{
    public static function getText(string $filePath, array $options = []): string
    {
        try {
            $phpWord = IOFactory::load($filePath, 'Word2007');
        } catch (Throwable $e) {
            throw new ExceptionBusiness('本地 DOCX 解析失败：' . $e->getMessage(), 0, $e);
        }

        $lines = [];
        foreach ($phpWord->getSections() as $section) {
            if (!method_exists($section, 'getElements')) {
                continue;
            }
            foreach ((array)$section->getElements() as $element) {
                self::appendElementText($lines, $element);
            }
        }

        $text = trim(implode("\n", array_values(array_filter(array_map('trim', $lines), static fn ($v) => $v !== ''))));
        if ($text === '') {
            throw new ExceptionBusiness('本地 DOCX 解析结果为空');
        }
        return $text;
    }

    /**
     * @param array<int, string> $lines
     */
    private static function appendElementText(array &$lines, mixed $element): void
    {
        if ($element instanceof Text) {
            $value = trim((string)$element->getText());
            if ($value !== '') {
                $lines[] = $value;
            }
            return;
        }

        if ($element instanceof Link) {
            $value = trim((string)$element->getText());
            if ($value !== '') {
                $lines[] = $value;
            }
            return;
        }

        if ($element instanceof TextBreak) {
            $lines[] = '';
            return;
        }

        if ($element instanceof TextRun || $element instanceof AbstractContainer) {
            if (method_exists($element, 'getElements')) {
                foreach ((array)$element->getElements() as $child) {
                    self::appendElementText($lines, $child);
                }
            }
            return;
        }

        if ($element instanceof Table) {
            foreach ((array)$element->getRows() as $row) {
                self::appendElementText($lines, $row);
            }
            $lines[] = '';
            return;
        }

        if ($element instanceof Row) {
            $cells = [];
            foreach ((array)$element->getCells() as $cell) {
                $cells[] = $cell;
            }
            $rowText = self::extractTableRowText($cells);
            if ($rowText !== '') {
                $lines[] = $rowText;
            }
            return;
        }

        if ($element instanceof Cell) {
            $cellLines = [];
            foreach ((array)$element->getElements() as $child) {
                self::appendElementText($cellLines, $child);
            }
            $value = trim(implode(' ', array_values(array_filter(array_map('trim', $cellLines), static fn ($v) => $v !== ''))));
            if ($value !== '') {
                $lines[] = $value;
            }
        }
    }

    /**
     * @param array<int, Cell> $cells
     */
    private static function extractTableRowText(array $cells): string
    {
        $colTexts = [];
        foreach ($cells as $cell) {
            $cellLines = [];
            foreach ((array)$cell->getElements() as $child) {
                self::appendElementText($cellLines, $child);
            }
            $text = trim(implode(' ', array_values(array_filter(array_map('trim', $cellLines), static fn ($v) => $v !== ''))));
            $colTexts[] = $text;
        }
        $colTexts = array_values(array_filter($colTexts, static fn ($v) => $v !== ''));
        return $colTexts === [] ? '' : implode(' | ', $colTexts);
    }
}
