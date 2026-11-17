<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher\Cv;

interface CvExtractor
{
    /** Return the structured draft for a validated private document. */
    public function extract(string $path, string $mediaType): array;
}
