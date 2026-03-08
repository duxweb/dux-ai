<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use Psr\Http\Message\StreamInterface;

final class SseGeneratorStream implements StreamInterface
{
    /**
     * @var callable(): (string|false|null)
     */
    private mixed $source;

    private bool $closed = false;

    private int $position = 0;

    private string $buffer = '';

    /**
     * @param callable(): (string|false|null) $source
     */
    public function __construct(callable $source)
    {
        $this->source = $source;
    }

    /**
     * @param \Generator<string> $generator
     */
    public static function fromGenerator(\Generator $generator): self
    {
        $generator->rewind();
        $started = false;

        return new self(static function () use ($generator, &$started) {
            if ($started) {
                $generator->next();
            } else {
                $started = true;
            }

            if (!$generator->valid()) {
                return false;
            }

            return (string)$generator->current();
        });
    }

    public function __toString(): string
    {
        try {
            $content = '';
            while (!$this->eof()) {
                $content .= $this->read(8192);
            }

            return $content;
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->closed = true;
        $this->buffer = '';
    }

    public function detach(): mixed
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->closed;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new \RuntimeException('SSE 流不支持 seek');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('SSE 流不支持 rewind');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('SSE 流不支持写入');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        if ($this->closed || $length <= 0) {
            return '';
        }

        if ($this->buffer === '') {
            $chunk = ($this->source)();
            if ($chunk === false || $chunk === null) {
                $this->closed = true;
                return '';
            }
            $this->buffer = (string)$chunk;
        }

        $out = substr($this->buffer, 0, $length);
        $this->buffer = (string)substr($this->buffer, strlen($out));
        $this->position += strlen($out);

        return $out;
    }

    public function getContents(): string
    {
        if ($this->closed) {
            return '';
        }

        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata($key = null): mixed
    {
        if ($key === null) {
            return [];
        }

        return null;
    }
}

