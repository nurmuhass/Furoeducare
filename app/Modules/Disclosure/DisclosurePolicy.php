<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Disclosure;

use Furoeducare\Core\Config;

final class DisclosurePolicy
{
    public readonly string $version;
    public readonly string $noticeUrl;
    public readonly string $contact;
    private readonly string $approvalRef;

    public function __construct(Config $config)
    {
        $this->version = trim($config->disclosurePolicyVersion);
        $this->approvalRef = trim($config->disclosurePolicyApprovalRef);
        $noticeUrl = trim($config->disclosurePrivacyNoticeUrl);
        $this->noticeUrl = self::isHttpsNotice($noticeUrl, $config->baseUrl) ? $noticeUrl : '';
        $contact = trim($config->disclosurePrivacyContact);
        $this->contact = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false ? $contact : '';
    }

    public function active(): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/', $this->version) === 1
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/ -]{0,159}\z/', $this->approvalRef) === 1
            && $this->noticeUrl !== ''
            && $this->contact !== '';
    }

    public function limitHours(string $scope): int
    {
        return match ($scope) {
            'professional' => 168,
            'original_cv' => 24,
            default => 0,
        };
    }

    public function contactUrl(): string
    {
        if ($this->contact === '') {
            return '';
        }
        $at = strrpos($this->contact, '@');
        return 'mailto:' . rawurlencode(substr($this->contact, 0, $at)) . substr($this->contact, $at);
    }

    private static function isHttpsNotice(string $url, string $siteUrl): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $siteHost = parse_url($siteUrl, PHP_URL_HOST);
        $draftPage = is_string($siteHost) && strcasecmp(rtrim($siteHost, '.'), rtrim($host, '.')) === 0
            && trim(rawurldecode($parts['path'] ?? ''), '/') === 'privacy-policy';
        return strtolower($parts['scheme'] ?? '') === 'https'
            && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && !isset($parts['user']) && !isset($parts['pass'])
            && !$draftPage;
    }
}
