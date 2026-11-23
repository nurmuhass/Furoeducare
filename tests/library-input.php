<?php

declare(strict_types=1);

use Furoeducare\Modules\Library\LibraryInput;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

$fields = ['title' => 'A mathematics guide', 'author' => 'An educator', 'access' => 'free',
    'licence' => 'Publisher permission', 'rights_reference' => 'Permission record 12', 'rights_confirmed' => '1',
    'categories' => "Teaching\nTeaching", 'subjects' => 'Mathematics', 'levels' => 'Primary'];
$valid = LibraryInput::resource($fields);
expect($valid['title'] === $fields['title'] && $valid['tags']['categories'] === ['Teaching'], 'Validate metadata and deduplicate tags.');
foreach ([['title' => ''], ['access' => 'paid'], ['description' => str_repeat('x', 6001)], ['rights_expires_on' => '2026-02-30']] as $invalid) {
    try {
        LibraryInput::resource($invalid + $fields);
        throw new RuntimeException('Invalid library fields were accepted.');
    } catch (DomainException $expected) {
        expect($expected->getCode() === 422, 'Return a validation error for invalid metadata.');
    }
}
echo "PASS library metadata, access categories, dates and taxonomy validation.\n";
