<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Service\Parse\Reader\DocxReader;
use App\Ai\Service\Parse\Reader\ParseDriverReader;
use App\Ai\Service\Parse\Reader\ParseReaderContext;
use App\Ai\Service\Parse\Reader\XlsxReader;
use NeuronAI\RAG\DataLoader\FileDataLoader as NeuronFileDataLoader;
use NeuronAI\RAG\DataLoader\HtmlReader;

final class FileDataLoader extends NeuronFileDataLoader
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $filePath, array $context = [])
    {
        ParseReaderContext::set($context);
        parent::__construct($filePath);
        $this->bootReaders();
    }

    /**
     * @return array<int, \NeuronAI\RAG\Document>
     */
    public function getDocuments(): array
    {
        try {
            return parent::getDocuments();
        } finally {
            ParseReaderContext::clear();
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, \NeuronAI\RAG\Document>
     */
    public static function documents(string $filePath, array $context = []): array
    {
        return (new self($filePath, $context))->getDocuments();
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function content(string $filePath, array $context = []): string
    {
        $documents = self::documents($filePath, $context);
        $parts = array_map(static fn (\NeuronAI\RAG\Document $doc) => trim((string)$doc->content), $documents);
        $parts = array_values(array_filter($parts, static fn (string $item) => $item !== ''));
        return implode("\n\n", $parts);
    }

    private function bootReaders(): void
    {
        $this->addReader(['docx'], new DocxReader());
        $this->addReader(['xls', 'xlsx'], new XlsxReader());
        $this->addReader(['html', 'htm', 'xhtml'], new HtmlReader());
        $this->addReader(['pdf', 'png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif'], new ParseDriverReader());
    }
}
