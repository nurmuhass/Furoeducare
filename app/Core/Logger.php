<?php

declare(strict_types=1);

namespace Furoeducare\Core;

final class Logger
{
    public function __construct(private readonly string $file)
    {
    }

    public function error(array $context): void
    {
        $record = ['timestamp' => gmdate('c'), 'level' => 'error'] + $context;
        $line = json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        if (@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(trim($line));
        }
    }
}
