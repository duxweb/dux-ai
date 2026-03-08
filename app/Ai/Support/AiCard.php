<?php

declare(strict_types=1);

namespace App\Ai\Support;

final class AiCard
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [
        'title' => '',
        'desc' => '',
        'image' => '',
        'fields' => [],
        'buttons' => [],
    ];

    public static function make(): self
    {
        return new self();
    }

    public function title(string $title): self
    {
        $this->data['title'] = $title;
        return $this;
    }

    public function desc(string $desc): self
    {
        $this->data['desc'] = $desc;
        return $this;
    }

    public function image(string $image): self
    {
        $this->data['image'] = $image;
        return $this;
    }

    public function field(string $name, string $value): self
    {
        $this->data['fields'][] = [
            'name' => $name,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * 兼容旧调用：button(label, action, payload) 并补充 type=action。
     *
     * @param array<string, mixed> $payload
     */
    public function button(string $label, string $action, array $payload = []): self
    {
        if ($action === '') {
            return $this;
        }
        $this->data['buttons'][] = [
            'label' => $label,
            'type' => 'action',
            'action' => $action,
            'payload' => $payload,
        ];
        return $this;
    }

    public function actionButton(string $action): self
    {
        if ($action === '') {
            return $this;
        }
        $this->data['buttons'][] = [
            'type' => 'action',
            'action' => $action,
        ];
        return $this;
    }

    public function pathButton(string $path): self
    {
        if ($path === '') {
            return $this;
        }
        $this->data['buttons'][] = [
            'type' => 'path',
            'path' => $path,
        ];
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
