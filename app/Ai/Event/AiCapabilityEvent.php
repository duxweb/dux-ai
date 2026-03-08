<?php

declare(strict_types=1);

namespace App\Ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

class AiCapabilityEvent extends Event
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $registry = [];

    /**
     * Create or update a capability definition.
     *
     * Meta fields are free-form, but commonly include:
     * - label/name/description
     * - tool: {type, function}
     * - defaults/settings (agent config UI)
     *
     * @param array<string, mixed> $meta
     */
    public function register(string $code, array $meta = []): void
    {
        $trimmed = trim($code);
        if ($trimmed === '') {
            return;
        }

        $this->ensure($trimmed);

        if ($meta !== []) {
            $this->registry[$trimmed] = array_replace_recursive($this->registry[$trimmed], $meta);
        }
    }

    /**
     * Declare capability available scopes.
     *
     * @param array<int, string>|string $types Supported: flow, agent
     */
    public function type(string $code, array|string $types): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }

        $this->ensure($code);
        $normalized = [];
        $items = is_array($types) ? $types : [$types];
        foreach ($items as $item) {
            $value = strtolower(trim((string)$item));
            if (!in_array($value, ['flow', 'agent'], true)) {
                continue;
            }
            $normalized[$value] = true;
        }
        $this->registry[$code]['types'] = array_keys($normalized) ?: ['flow', 'agent'];
    }

    /**
     * @param array<string, mixed> $schema Input schema (Agent-style JSON schema)
     */
    public function schema(string $code, array $schema): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }
        $this->ensure($code);
        $this->registry[$code]['schema'] = $schema;
    }

    /**
     * Declare capability output fields (for UI & flow field reference).
     *
     * @param array<int, array{name: string, label?: string, type?: string, description?: string}> $fields
     */
    public function output(string $code, array $fields): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }
        $this->ensure($code);
        $fields = array_values(array_filter($fields, static function ($field) {
            return is_array($field) && isset($field['name']) && is_string($field['name']) && trim($field['name']) !== '';
        }));

        $this->registry[$code]['output'] = [
            'fields' => $fields,
            'desc' => self::stringifyOutputFields($fields),
        ];
    }

    public function handler(string $code, callable $handler): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }
        $this->ensure($code);
        $this->registry[$code]['handler'] = $handler;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRegistry(): array
    {
        return $this->registry;
    }

    private function ensure(string $code): void
    {
        if (isset($this->registry[$code])) {
            return;
        }

        $this->registry[$code] = [
            'code' => $code,
            'name' => $code,
            'label' => $code,
            'description' => '',
            'types' => ['flow', 'agent'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private static function stringifyOutputFields(array $fields): string
    {
        $parts = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = trim((string)($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $label = trim((string)($field['label'] ?? ''));
            $desc = trim((string)($field['description'] ?? ''));
            $suffix = $desc !== '' ? $desc : ($label !== '' ? $label : '');
            $parts[] = $suffix !== '' ? sprintf('%s（%s）', $name, $suffix) : $name;
        }

        return implode('，', $parts);
    }
}
