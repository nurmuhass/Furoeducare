<?php

declare(strict_types=1);

namespace Furoeducare\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    private bool $development = false;

    public function __construct(private readonly Logger $logger)
    {
    }

    public function setDevelopmentMode(bool $development): void
    {
        $this->development = $development;
    }

    public function run(callable $dispatch, bool $head): void
    {
        $bufferLevel = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $response = $dispatch();
        } catch (HttpException $exception) {
            $response = Response::text($exception->getMessage(), $exception->status);
        } catch (\DomainException $exception) {
            if (in_array($exception->getCode(), [403, 404, 409, 422], true)) {
                $response = Response::text($exception->getMessage(), $exception->getCode(), ['Cache-Control' => 'private, no-store']);
            } else {
                $failure = $exception;
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            restore_error_handler();
        }

        if (isset($failure)) {
            $this->renderFailure($failure, $head);
            return;
        }

        $response->send($head);
    }

    private function renderFailure(Throwable $failure, bool $head): void
    {
        $requestId = bin2hex(random_bytes(8));
        $record = [
            'request_id' => $requestId,
            'exception' => $failure::class,
            'message' => $failure instanceof ConfigurationException
                ? $failure->getMessage()
                : 'Unhandled application error.',
            'file' => basename($failure->getFile()),
            'line' => $failure->getLine(),
        ];
        $this->logger->error($record);

        http_response_code(500);
        SecurityHeaders::apply();
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-ID: ' . $requestId);
        if (!$head) {
            echo "Internal Server Error\n";
            if ($this->development) {
                echo 'Development diagnostics are available in the application log. Request ID: ', $requestId, "\n";
            }
        }
    }
}
