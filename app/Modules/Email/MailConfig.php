<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use Furoeducare\Core\Config;
use Furoeducare\Core\ConfigurationException;

final class MailConfig
{
    public readonly string $transport;
    public readonly string $host;
    public readonly int $port;
    public readonly string $username;
    public readonly string $password;
    public readonly string $encryption;
    public readonly int $timeout;
    public readonly string $from;
    public readonly string $fromName;
    public readonly string $directory;
    public readonly string $baseUrl;
    public readonly string $encryptionKey;
    public readonly string $environment;

    public function __construct(Config $config)
    {
        $options = $config->mailOptions;
        $this->environment = $config->environment;
        $this->transport = (string) ($options['transport'] ?? ($this->environment === 'development' ? 'file' : 'smtp'));
        $this->host = (string) ($options['smtp_host'] ?? '');
        $this->port = filter_var($options['smtp_port'] ?? 587, FILTER_VALIDATE_INT) ?: 0;
        $this->username = (string) ($options['smtp_username'] ?? '');
        $this->password = (string) ($options['smtp_password'] ?? '');
        $this->encryption = (string) ($options['smtp_encryption'] ?? 'tls');
        $this->timeout = filter_var($options['timeout'] ?? 15, FILTER_VALIDATE_INT) ?: 0;
        $this->from = $config->mailFrom !== '' ? $config->mailFrom : ($this->environment === 'development' ? 'no-reply@furoeducare.test' : '');
        $this->fromName = $config->name;
        $this->directory = $config->authMailDirectory;
        $this->baseUrl = rtrim($config->baseUrl, '/');
        $this->encryptionKey = (string) ($options['encryption_key'] ?? '');
    }

    public function validate(): void
    {
        if (!in_array($this->transport, ['file', 'smtp'], true)) {
            throw new ConfigurationException('MAIL_TRANSPORT must be file or smtp.');
        }
        if ($this->environment === 'production' && $this->transport !== 'smtp') {
            throw new ConfigurationException('Production email requires SMTP.');
        }
        if (filter_var($this->from, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n\x00]/', $this->from . $this->fromName)) {
            throw new ConfigurationException('MAIL_FROM must be a valid email address and APP_NAME must not contain header breaks.');
        }
        $origin = parse_url($this->baseUrl);
        if ($origin === false || filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false || empty($origin['host']) || !in_array($origin['scheme'] ?? '', ['http', 'https'], true)
            || isset($origin['user']) || isset($origin['pass'])
            || isset($origin['query']) || isset($origin['fragment']) || !in_array($origin['path'] ?? '', ['', '/'], true)
            || preg_match('/[\x00-\x20\x7f]/', $this->baseUrl)
            || ($this->environment === 'production' && ($origin['scheme'] ?? '') !== 'https')) {
            throw new ConfigurationException('APP_URL must be an origin without credentials, path, query or fragment; production requires HTTPS.');
        }
        if ($this->timeout < 1 || $this->timeout > 30) {
            throw new ConfigurationException('MAIL_TIMEOUT must be between 1 and 30 seconds.');
        }
        if ($this->transport === 'smtp') {
            if ($this->host === '' || preg_match('/[\s;\/\\\\\x00-\x1f]/', $this->host) || $this->port < 1 || $this->port > 65535) {
                throw new ConfigurationException('SMTP host and port are invalid.');
            }
            if (!in_array($this->encryption, ['tls', 'smtps', 'none'], true)
                || ($this->environment === 'production' && $this->encryption === 'none')) {
                throw new ConfigurationException('SMTP encryption must be tls or smtps in production.');
            }
            if ($this->encryption !== 'none' && !extension_loaded('openssl')) {
                throw new ConfigurationException('Encrypted SMTP requires the OpenSSL PHP extension.');
            }
            if (($this->username === '') !== ($this->password === '') || preg_match('/[\r\n\x00]/', $this->username . $this->password)) {
                throw new ConfigurationException('SMTP credentials must be configured together without header breaks.');
            }
            return;
        }
        $this->privateDirectory(false);
    }

    public function privateDirectory(bool $create = true): string
    {
        $public = realpath(dirname(__DIR__, 3) . '/public');
        $parent = realpath(dirname($this->directory));
        $inside = static function (string $path, string $base): bool {
            $path = str_replace('\\', '/', $path);
            $base = rtrim(str_replace('\\', '/', $base), '/');
            if (DIRECTORY_SEPARATOR === '\\') {
                $path = strtolower($path);
                $base = strtolower($base);
            }
            return $path === $base || str_starts_with($path, $base . '/');
        };
        if ($public === false || $parent === false || $inside($parent, $public)) {
            throw new ConfigurationException('Email files require an existing private parent directory outside public.');
        }
        if (!is_dir($this->directory)) {
            if (!$create) {
                return $parent . DIRECTORY_SEPARATOR . basename($this->directory);
            }
            if (!@mkdir($this->directory, 0700) && !is_dir($this->directory)) {
                throw new ConfigurationException('Unable to create the private email directory.');
            }
        }
        $directory = realpath($this->directory);
        if ($directory === false || $inside($directory, $public)) {
            throw new ConfigurationException('Email files must be outside public.');
        }
        if ($create && !@chmod($directory, 0700)) {
            throw new ConfigurationException('Unable to protect the private email directory.');
        }
        return $directory;
    }
}
