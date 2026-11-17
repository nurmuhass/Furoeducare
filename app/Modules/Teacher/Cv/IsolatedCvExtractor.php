<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher\Cv;

final class IsolatedCvExtractor implements CvExtractor
{
    public function __construct(private readonly string $script)
    {
    }

    public function extract(string $path, string $mediaType): array
    {
        $output = tmpfile();
        if ($output === false) {
            throw new ExtractionFailure('unreadable');
        }

        $command = [PHP_BINARY];
        if (php_ini_loaded_file() !== false) {
            array_push($command, '-c', php_ini_loaded_file());
        }
        array_push($command, '-d', 'memory_limit=128M', '-d', 'display_errors=0', '-d', 'log_errors=0',
            $this->script, $path, $mediaType);

        try {
            $process = proc_open($command, [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => $output,
                2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            ], $pipes, options: ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new ExtractionFailure('unreadable');
            }

            $deadline = hrtime(true) + 30_000_000_000;
            try {
                while (($status = proc_get_status($process))['running']) {
                    if (hrtime(true) >= $deadline) {
                        proc_terminate($process, 9);
                        throw new ExtractionFailure('limit_exceeded');
                    }
                    usleep(50_000);
                }
            } finally {
                proc_close($process);
            }

            rewind($output);
            $result = stream_get_contents($output, 300_000);
            if ($status['exitcode'] !== 0 || $result === false || strlen($result) === 300_000) {
                throw new ExtractionFailure('unreadable');
            }

            $payload = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
            if (isset($payload['failure']) && is_string($payload['failure'])) {
                throw new ExtractionFailure($payload['failure']);
            }
            return CvDraft::validate($payload['draft'] ?? []);
        } finally {
            fclose($output);
        }
    }
}
