<?php

declare(strict_types=1);

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function serve(string $root, array $environment, string $serverLog, callable $verify): void
{
    // Tests use the real background mail worker with one ephemeral key shared by its PHP processes.
    if (!is_string(getenv('MAIL_ENCRYPTION_KEY')) || getenv('MAIL_ENCRYPTION_KEY') === '') {
        putenv('MAIL_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    expect($socket !== false, 'Cannot reserve a loopback port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $command = [PHP_BINARY];
    if (php_ini_loaded_file() !== false) {
        array_push($command, '-c', php_ini_loaded_file());
    }
    array_push($command, '-S', $address, '-t', 'public', 'bin/dev-server.php');

    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $serverLog, 'a'],
        2 => ['file', $serverLog, 'a'],
    ], $pipes, $root, array_replace(getenv(), $environment));
    expect(is_resource($process), 'Cannot start the PHP development server.');
    fclose($pipes[0]);

    try {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @stream_socket_client("tcp://$address", timeout: 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                $verify("http://$address");
                return;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        throw new RuntimeException('The PHP server did not start. ' . file_get_contents($serverLog));
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

function request(string $base, string $path, string $method = 'GET', string $form = '', string $cookie = '', string $contentType = 'application/x-www-form-urlencoded'): array
{
    $options = [
        'method' => $method,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 12.0,
    ];
    $requestHeaders = $cookie === '' ? [] : ['Cookie: ' . $cookie];
    if ($method === 'POST' && $form !== '') {
        $requestHeaders[] = 'Content-Type: ' . $contentType;
        $options['content'] = $form;
    }

    $options['header'] = $requestHeaders;
    $response = fopen($base . $path, 'rb', false, stream_context_create(['http' => $options]));
    expect(is_resource($response), "No HTTP response for $method $path.");
    $lines = stream_get_meta_data($response)['wrapper_data'];
    $body = stream_get_contents($response);
    fclose($response);

    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }

    return [(int) explode(' ', $lines[0])[1], $headers, $body];
}

