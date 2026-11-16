<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Site\Controllers;

use Furoeducare\Core\Response;
use Furoeducare\Core\View;

final class SiteController
{
    public function __construct(private readonly View $view)
    {
    }

    public function page(string $path, string $template, string $title, array $data = []): Response
    {
        return Response::html($this->view->render(
            dirname(__DIR__) . '/Views/' . $template . '.php',
            $title,
            ['path' => $path] + $data
        ));
    }

    public function health(): Response
    {
        return Response::json(['status' => 'ok']);
    }
}
