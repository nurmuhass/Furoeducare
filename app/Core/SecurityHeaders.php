<?php
declare(strict_types=1);
namespace Furoeducare\Core;

/** Browser containment shared by normal responses and early bootstrap failures. */
final class SecurityHeaders
{
    public static function apply(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        // Local vanilla scripts only. Existing Bootstrap CSS and Google Fonts
        // remain allowed; inline styles are retained for current components.
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    }
}
