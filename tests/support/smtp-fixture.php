<?php

declare(strict_types=1);

// Loopback-only SMTP endpoint: never relays or records message contents.
// argv: readiness-file, response (250/451/550/disconnect).
$ready = $argv[1] ?? '';
$response = $argv[2] ?? '250';
if ($ready === '' || !in_array($response, ['250', '451', '550', 'disconnect'], true)) {
    exit(2);
}
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) { exit(3); }
file_put_contents($ready, stream_socket_get_name($server, false));
$connection = stream_socket_accept($server, 20);
if ($connection === false) { fclose($server); exit(4); }
stream_set_timeout($connection, 10);
fwrite($connection, "220 loopback.example.test ESMTP fixture\r\n");
$data = false;
while (($line = fgets($connection)) !== false) {
    if ($data) {
        if (rtrim($line, "\r\n") !== '.') { continue; }
        $data = false;
        if ($response === 'disconnect') { break; }
        fwrite($connection, match ($response) {
            '451' => "451 4.3.0 Temporary fixture failure\r\n",
            '550' => "550 5.7.1 Permanent fixture rejection\r\n",
            default => "250 2.0.0 Accepted by fixture\r\n",
        });
        continue;
    }
    $command = strtoupper(strtok($line, ' '));
    if (str_starts_with($command, 'EHLO')) {
        fwrite($connection, "250-loopback.example.test\r\n250 SIZE 1048576\r\n");
    } elseif (str_starts_with($command, 'HELO') || str_starts_with($command, 'MAIL') || str_starts_with($command, 'RCPT') || str_starts_with($command, 'RSET')) {
        fwrite($connection, "250 2.0.0 OK\r\n");
    } elseif (str_starts_with($command, 'DATA')) {
        $data = true;
        fwrite($connection, "354 End with a dot\r\n");
    } elseif (str_starts_with($command, 'QUIT')) {
        fwrite($connection, "221 2.0.0 Bye\r\n");
        break;
    } else {
        fwrite($connection, "502 5.5.1 Unsupported fixture command\r\n");
    }
}
fclose($connection);
fclose($server);
