<?php

$fieldPrefix = 'teacher';
require $partials . '/form-fields.php';

$contactFields = static function (array $values, array $errors) use ($field): void {
    $field('first_name', 'First name', $values['first_name'] ?? '', options: ['maxlength' => 80, 'autocomplete' => 'given-name', 'required' => ''], error: $errors['first_name'] ?? '');
    $field('last_name', 'Last name', $values['last_name'] ?? '', options: ['maxlength' => 80, 'autocomplete' => 'family-name', 'required' => ''], error: $errors['last_name'] ?? '');
    $field('phone', 'Phone number', $values['phone'] ?? '', 'tel', ['maxlength' => 48, 'autocomplete' => 'tel', 'required' => ''],
        $errors['phone'] ?? '', 'Use an 11-digit Nigerian number or include the international calling code.');
};
