<?php

declare(strict_types=1);

namespace Furoeducare\Core;

final class View
{
    public function __construct(private readonly string $layout, private readonly string $appName)
    {
    }

    public function render(string $template, string $title, array $data = []): string
    {
        $context = ['title' => $title, 'appName' => $this->appName] + $data;
        return $this->capture($this->layout, ['content' => $this->capture($template, $context)] + $context);
    }

    private function capture(string $template, array $data): string
    {
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $partials = dirname($this->layout);

        ob_start();
        try {
            require $template;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
