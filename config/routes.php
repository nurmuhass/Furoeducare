<?php

declare(strict_types=1);

use Furoeducare\Core\Router;
use Furoeducare\Modules\Disclosure\DisclosurePolicy;
use Furoeducare\Modules\Site\Controllers\SiteController;

return static function (Router $router, SiteController $site, ?DisclosurePolicy $policy = null): void {
    $pages = [
        '/' => ['home', 'Education recruitment in Nigeria'],
        '/about' => ['about', 'About us'],
        '/services' => ['services', 'Our services'],
        '/contact' => ['contact', 'Contact us'],
        '/privacy-policy' => ['legal', 'Privacy policy'],
        '/terms' => ['legal', 'Terms of use'],
    ];
    foreach ($pages as $path => [$template, $title]) {
        $router->add('GET', $path, static fn () => $site->page($path, $template, $title,
            $path === '/privacy-policy' ? ['disclosurePolicy' => $policy] : []));
    }

    $resources = require dirname(__DIR__) . '/app/Modules/Site/resources.php';
    foreach ($resources as $path => $resource) {
        $router->add('GET', $path, static fn () => $site->page($path, 'resource', $resource['title'], $resource));
    }

    $router->add('GET', '/health', $site->health(...));
};
