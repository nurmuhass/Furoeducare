<?php

declare(strict_types=1);

namespace Furoeducare\Core;

final class Config
{
    private function __construct(
        public readonly string $environment,
        public readonly string $name,
        public readonly string $databaseHost,
        public readonly int $databasePort,
        public readonly string $databaseName,
        public readonly string $databaseUser,
        #[\SensitiveParameter]
        public readonly string $databasePassword,
        public readonly string $baseUrl,
        public readonly string $mailFrom,
        public readonly string $authMailDirectory,
        public readonly string $authSessionDirectory,
        public readonly string $teacherDocumentDirectory,
        public readonly string $libraryStorageDirectory,
        public readonly string $disclosurePolicyVersion,
        public readonly string $disclosurePolicyApprovalRef,
        public readonly string $disclosurePrivacyNoticeUrl,
        public readonly string $disclosurePrivacyContact,
        public readonly array $mailOptions,
    ) {
    }

    public static function load(string $root): self
    {
        $values = self::readLocalFile($root . '/.env');

        $read = static function (string $key, string $default) use ($values): string {
            $value = getenv($key, true);
            if ($value !== false) {
                return $value;
            }
            return $values[$key] ?? $default;
        };

        $environment = $read('APP_ENV', 'production');
        if (!in_array($environment, ['development', 'production'], true)) {
            throw new ConfigurationException('APP_ENV must be development or production.');
        }

        $name = $read('APP_NAME', 'Furoeducare');
        if (trim($name) === '') {
            throw new ConfigurationException('APP_NAME is required.');
        }

        $port = filter_var($read('DB_PORT', '3306'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new ConfigurationException('DB_PORT must be an integer from 1 to 65535.');
        }

        $host = $read('DB_HOST', '127.0.0.1');
        $database = $read('DB_DATABASE', 'furoeducare');
        foreach (['DB_HOST' => $host, 'DB_DATABASE' => $database] as $key => $value) {
            if (trim($value) === '' || preg_match('/[;\x00-\x1f]/', $value) === 1) {
                throw new ConfigurationException($key . ' is empty or contains an invalid character.');
            }
        }

        $user = $read('DB_USERNAME', 'furoeducare');
        if (trim($user) === '') {
            throw new ConfigurationException('DB_USERNAME is required.');
        }

        return new self(
            $environment, $name, $host, $port, $database, $user, $read('DB_PASSWORD', ''),
            $read('APP_URL', $environment === 'development' ? 'http://127.0.0.1:8000' : ''),
            $read('MAIL_FROM', ''),
            $read('AUTH_MAIL_DIR', $root . '/storage/mail'),
            $read('AUTH_SESSION_DIR', $root . '/storage/sessions'),
            $read('TEACHER_DOCUMENT_DIR', $root . '/storage/teacher-documents'),
            $read('LIBRARY_STORAGE_DIR', $root . '/storage/library'),
            $read('DISCLOSURE_POLICY_VERSION', ''),
            $read('DISCLOSURE_POLICY_APPROVAL_REF', ''),
            $read('DISCLOSURE_PRIVACY_NOTICE_URL', ''),
            $read('DISCLOSURE_PRIVACY_CONTACT', ''),
            [
                'transport' => $read('MAIL_TRANSPORT', $environment === 'development' ? 'file' : 'smtp'),
                'smtp_host' => $read('MAIL_SMTP_HOST', ''),
                'smtp_port' => $read('MAIL_SMTP_PORT', '587'),
                'smtp_username' => $read('MAIL_SMTP_USERNAME', ''),
                'smtp_password' => $read('MAIL_SMTP_PASSWORD', ''),
                'smtp_encryption' => $read('MAIL_SMTP_ENCRYPTION', 'tls'),
                'timeout' => $read('MAIL_TIMEOUT', '15'),
                'encryption_key' => $read('MAIL_ENCRYPTION_KEY', ''),
                'log_file' => $read('APP_LOG_FILE', $root . '/storage/logs/app.log'),
            ],
        );
    }

    private static function readLocalFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = @file($path);
        if ($lines === false) {
            throw new ConfigurationException('The .env file could not be read.');
        }

        // A flat KEY=value file with optional quoting and full-line comments.
        $assignment = <<<'PATTERN'
~\A([A-Z_][A-Z0-9_]*)[ \t]*=[ \t]*(?:"([^"]*)"|'([^']*)'|([^\s"'#;]*))\z~
PATTERN;
        $values = [];
        foreach ($lines as $index => $line) {
            if ($index === 0 && str_starts_with($line, "\xEF\xBB\xBF")) {
                $line = substr($line, 3);
            }
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }

            if (preg_match($assignment, $line, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
                throw new ConfigurationException('Invalid .env assignment on line ' . ($index + 1) . '.');
            }

            $values[$matches[1]] = $matches[2] ?? $matches[3] ?? $matches[4];
        }

        return $values;
    }
}
