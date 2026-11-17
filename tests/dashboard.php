<?php

declare(strict_types=1);

use Furoeducare\Core\Request;
use Furoeducare\Modules\Teacher\Section;
use Furoeducare\Modules\Teacher\TeacherInput;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

$availability = static fn (array $fields): array => TeacherInput::section(
    Section::Availability, Request::fromServer(['REQUEST_METHOD' => 'POST'], $fields), 'teacher'
);

try {
    $fields = ['availability_preferences' => '1'];
    [$data, $errors] = $availability($fields + ['opportunities' => ['full_time', 'part_time', 'private_tutoring', 'online_tutoring']]);
    expect($errors === [] && $data['status'] === 'now'
        && $data['opportunities'] === ['full_time', 'part_time', 'private_tutoring', 'online_tutoring'], 'Save multiple compatible availability options.');

    [$data, $errors] = $availability($fields + ['opportunities' => ['unavailable']]);
    expect($errors === [] && $data['status'] === 'unavailable' && $data['opportunities'] === ['unavailable'], 'Save temporarily unavailable alone.');
    [, $errors] = $availability($fields + ['opportunities' => ['full_time', 'unavailable']]);
    expect(isset($errors['opportunities']), 'Reject conflicting availability options.');
    [, $errors] = $availability($fields + ['opportunities' => ['unknown']]);
    expect(isset($errors['opportunities']), 'Reject unknown availability values.');
    [, $errors] = $availability($fields + ['opportunities' => ['full_time'], 'date' => '2027-02-30']);
    expect(isset($errors['date']), 'Validate optional availability dates.');
    [$data, $errors] = $availability(['status' => 'from_date', 'date' => '2027-01-02']);
    expect($errors === [] && $data['status'] === 'from_date' && $data['date'] === '2027-01-02', 'Preserve pre-dashboard availability form support.');
    [$skills, $errors] = TeacherInput::section(Section::Skills, Request::fromServer(['REQUEST_METHOD' => 'POST'], [
        'list_style' => 'lines', 'skills' => "Assessment, Level 2\nLesson planning", 'certifications' => 'Child Safeguarding, Level 2',
    ]), 'teacher');
    expect($errors === [] && $skills['skills'] === ['Assessment, Level 2', 'Lesson planning']
        && $skills['certifications'] === ['Child Safeguarding, Level 2'], 'A profile edit must preserve commas within existing CV entries.');
    echo "PASS dashboard availability combinations, exclusivity, date validation and legacy values.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
