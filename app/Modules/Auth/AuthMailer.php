<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

use Furoeducare\Core\Config;

final class AuthMailer
{
    public function __construct(private readonly Config $config, private readonly string $directory)
    {
    }

    public function ready(): bool
    {
        $origin = parse_url($this->config->baseUrl);
        if (!is_array($origin) || !isset($origin['host'], $origin['scheme'])
            || filter_var($this->config->baseUrl, FILTER_VALIDATE_URL) === false
            || isset($origin['user']) || isset($origin['pass']) || isset($origin['query']) || isset($origin['fragment'])
            || !in_array($origin['path'] ?? '', ['', '/'], true)) {
            return false;
        }

        if ($this->config->environment === 'development') {
            return in_array($origin['scheme'], ['http', 'https'], true);
        }
        return $origin['scheme'] === 'https' && filter_var($this->config->mailFrom, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function deliver(
        EmailToken $purpose,
        string $email,
        #[\SensitiveParameter] string $token,
        ?Role $flow = null,
        ?string $teacherRequestReference = null,
    ): void {
        $path = $purpose === EmailToken::Verification && $flow !== null ? $flow->verificationPath() : $purpose->path();
        $url = rtrim($this->config->baseUrl, '/') . $path . '?token=' . $token;
        $reference = AuthInput::teacherRequestReference($teacherRequestReference);
        if ($purpose === EmailToken::Verification && $flow !== Role::Teacher && $reference !== null) {
            $url .= '&ref=' . $reference;
        }
        $subject = $purpose === EmailToken::Verification ? 'Verify your Furoeducare email' : 'Reset your Furoeducare password';
        $message = $subject . "\r\n\r\n" . $url . "\r\n\r\n"
            . "If you did not request this email, you can ignore it.\r\n";

        $sent = $this->config->environment === 'development'
            ? $this->spool($email, $subject, $message)
            : @mail($email, $subject, $message, ['From' => $this->config->mailFrom]);

        if (!$sent) {
            error_log('Furoeducare authentication email could not be handed to its transport.');
        }
    }

    private function spool(string $email, string $subject, string $message): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return false;
        }
        $path = $this->directory . '/' . bin2hex(random_bytes(16)) . '.eml';
        $mail = "To: $email\r\nSubject: $subject\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $message;
        return @file_put_contents($path, $mail, LOCK_EX) === strlen($mail);
    }
}
