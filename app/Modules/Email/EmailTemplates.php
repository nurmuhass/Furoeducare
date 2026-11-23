<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use InvalidArgumentException;

/** Only fixed copy and validated navigation data belong in notification emails. */
final class EmailTemplates
{
    private const CATALOG = [
        'account_verification' => [null, 'Verify your Furoeducare email', 'Confirm your email address to continue setting up your Furoeducare account.', 'Verify email'],
        'welcome' => ['account', 'Welcome to Furoeducare', 'Your email has been verified. Sign in to complete your profile and review the next steps for your account.', 'Open dashboard'],
        'security_alert' => ['account', 'Your account security has changed', 'A sensitive account setting changed or was requested. Sign in to review your account.', 'Review account'],
        'recruitment_update' => ['recruitment', 'Your recruitment workspace has an update', 'A recruitment update is available in your account. Sign in to review the details securely.', 'Review update'],
        'password_reset' => [null, 'Reset your Furoeducare password', 'Use the secure link below to choose a new password for your Furoeducare account.', 'Reset password'],
        'email_change' => [null, 'Verify your new Furoeducare email', 'Sign in to the account that requested this change, then confirm your current password to use this address for sign-in.', 'Review email change'],
        'school_approval' => ['account', 'Your school account has an update', 'The review status of your school account has changed. Sign in to view the outcome and any next steps.', 'Review school account'],
        'teacher_verification' => ['account', 'Your teacher verification has an update', 'Your teacher verification status has changed. Sign in to view the outcome and any next steps.', 'Review verification'],
        'new_teacher_request' => ['recruitment', 'A new teacher request is ready to review', 'A teacher request needs your attention. Sign in to review the request securely.', 'Review request'],
        'vacancy_decision' => ['recruitment', 'Your vacancy has an update', 'The review status of your vacancy has changed. Sign in to view the decision and next steps.', 'Review vacancy'],
        'new_application' => ['recruitment', 'A new application is ready to review', 'There is a new application in your recruitment workspace. Sign in to review it securely.', 'Review applications'],
        'interview_invitation' => ['recruitment', 'You have an interview invitation', 'An interview invitation is available in your Furoeducare account. Sign in to review the details and respond.', 'View invitation'],
        'cv_request_result' => ['recruitment', 'Your candidate information request has an update', 'A decision is available for your candidate information request. Sign in to review the outcome and any permitted access.', 'Review request'],
        'new_internal_message' => ['messages', 'You have a new Furoeducare message', 'A new message is available in your secure inbox. Sign in to read it and reply.', 'Open messages'],
        'tutoring_update' => ['tutoring', 'Your tutoring request has an update', 'Your tutoring request has changed. Sign in to review its current status and any next steps.', 'Review tutoring request'],
        'placement_confirmation' => ['placements', 'Your placement has an update', 'An update is available for your placement. Sign in to review the confirmation and recorded next steps.', 'Review placement'],
        'training_confirmation' => ['training', 'A training request has an update', 'A training request update is available in your account. Sign in to review the details securely.', 'Review training request'],
    ];

    private const DECISIONS = [
        'approved' => 'Approved', 'rejected' => 'Rejected', 'verified' => 'Verified',
        'changes_required' => 'Changes required', 'suspended' => 'Suspended', 'confirmed' => 'Confirmed',
        'corrections_required' => 'Changes required', 'revoked' => 'Access revoked',
        'closed' => 'Closed', 'submitted' => 'Submitted', 'under_review' => 'Under review',
        'matching' => 'Matching', 'shortlisted' => 'Shortlisted', 'arrangement_pending' => 'Arrangement pending',
    ];

    public static function names(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function category(string $template): ?string
    {
        return self::definition($template)[0];
    }

    /** @return array{subject:string,text:string,html:string} */
    public static function render(string $template, array $payload, string $baseUrl): array
    {
        [, $subject, $copy, $action] = self::definition($template);
        $origin = self::origin($baseUrl);
        $essential = in_array($template, ['account_verification', 'password_reset', 'email_change'], true);
        $url = $origin . ($essential ? self::authPath($template, $payload) : self::navigationPath($payload['path'] ?? '/dashboard/teacher'));
        $decision = !$essential && is_string($payload['decision'] ?? null) ? (self::DECISIONS[$payload['decision']] ?? null) : null;
        $footer = $essential
            ? 'This security link expires and can only be used once. If you did not request this email, you can ignore it.'
            : 'For your privacy, details are available only after signing in. Manage optional emails in your notification settings.';
        $text = $subject . "\r\n\r\n" . $copy . ($decision === null ? '' : "\r\nStatus: " . $decision)
            . "\r\n\r\n" . $action . ":\r\n" . $url . "\r\n\r\n" . $footer . "\r\n";
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $escape($subject) . '</title></head>'
            . '<body style="margin:0;background:#f3f6f8;color:#18313b;font-family:Arial,sans-serif;line-height:1.6">'
            . '<table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="padding:32px 16px">'
            . '<table role="presentation" style="width:100%;max-width:600px;margin:auto;background:#ffffff;border:1px solid #dce5e9;border-radius:12px"><tr><td style="padding:32px">'
            . '<p style="margin:0 0 20px;font-weight:bold;color:#116763">Furoeducare</p><h1 style="font-size:24px;line-height:1.3">' . $escape($subject) . '</h1><p>' . $escape($copy) . '</p>'
            . ($decision === null ? '' : '<p><strong>Status:</strong> ' . $escape($decision) . '</p>')
            . '<p style="margin:28px 0"><a href="' . $escape($url) . '" style="display:inline-block;padding:12px 20px;background:#116763;color:#ffffff;border-radius:6px;font-weight:bold;text-decoration:underline">' . $escape($action) . '</a></p>'
            . '<p style="font-size:14px">Or copy this link into your browser:<br><a style="overflow-wrap:anywhere;color:#116763" href="' . $escape($url) . '">' . $escape($url) . '</a></p>'
            . '<p style="font-size:14px;color:#455a64">' . $escape($footer) . '</p>'
            . ($essential ? '' : '<p style="font-size:14px"><a style="color:#116763" href="' . $escape($origin . '/settings/notifications') . '">Manage notification settings</a></p>')
            . '</td></tr></table></td></tr></table></body></html>';
        return compact('subject', 'text', 'html');
    }

    private static function definition(string $template): array
    {
        return self::CATALOG[$template] ?? throw new InvalidArgumentException('Unknown email template.');
    }

    private static function origin(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || preg_match('/[\\x00-\\x20\\x7f\\\\]/', $baseUrl) !== 0
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('Email links require a configured HTTP or HTTPS origin.');
        }
        return rtrim($baseUrl, '/');
    }

    private static function authPath(string $template, array $payload): string
    {
        $token = $payload['token'] ?? null;
        if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new InvalidArgumentException('An authentication email requires a valid token.');
        }
        if ($template === 'password_reset') {
            return '/reset-password?token=' . $token;
        }
        if ($template === 'email_change') return '/settings/email-change?token=' . $token;
        $flow = $payload['flow'] ?? null;
        if ($flow !== null && !in_array($flow, ['teacher', 'school', 'parent'], true)) {
            throw new InvalidArgumentException('Invalid verification flow.');
        }
        $path = $flow === null ? '/verify-email' : '/verify-' . $flow;
        $reference = $payload['ref'] ?? null;
        if ($reference !== null && (!is_string($reference) || preg_match('/\AFT-[A-F0-9]{20}\z/D', $reference) !== 1)) {
            throw new InvalidArgumentException('Invalid signup continuation reference.');
        }
        return $path . '?token=' . $token . ($reference !== null && $flow !== 'teacher' ? '&ref=' . $reference : '');
    }

    private static function navigationPath(mixed $path): string
    {
        // No encoded paths, hostnames, fragments, extra query keys or free-form text.
        if (!is_string($path) || preg_match('~\A(?:/dashboard|/dashboard/(?:teacher|school|parent|admin)(?:/[a-z][a-z-]*)*|/messages|/candidate-requests|/placements|/notifications|/settings/account)(?:\?id=[1-9][0-9]{0,17})?\z~D', $path) !== 1) {
            throw new InvalidArgumentException('Invalid email destination.');
        }
        return $path;
    }
}
