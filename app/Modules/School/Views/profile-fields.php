<?php

use Furoeducare\Modules\School\SchoolInput;

$standard = ['required' => ''];
?>
<fieldset class="d-grid gap-3">
    <legend class="h5">Institution</legend>
    <?php
    $field('school_name', 'School name', $values['school_name'] ?? '', options: $standard + ['maxlength' => 160, 'autocomplete' => 'organization'], error: $errors['school_name'] ?? '');
    $field('category', 'School category', $values['category'] ?? '', 'select', SchoolInput::CATEGORIES, $errors['category'] ?? '', required: true);
    ?>
    <div class="row g-3">
        <div class="col-sm-6">
            <?php $field('state_code', 'State or FCT', $values['state_code'] ?? '', 'select', SchoolInput::STATES, $errors['state_code'] ?? '', required: true); ?>
        </div>
        <div class="col-sm-6">
            <?php $field('city', 'City or town', $values['city'] ?? '', options: $standard + ['maxlength' => 100, 'autocomplete' => 'address-level2'], error: $errors['city'] ?? ''); ?>
        </div>
    </div>
    <?php $field('address', 'School address', $values['address'] ?? '', 'textarea',
        $standard + ['maxlength' => 500, 'rows' => 2, 'autocomplete' => 'street-address'], $errors['address'] ?? ''); ?>
</fieldset>

<fieldset class="d-grid gap-3">
    <legend class="h5">School contact details</legend>
    <?php
    $field('contact_email', 'School contact email', $values['contact_email'] ?? '', 'email', $standard + ['maxlength' => 254], $errors['contact_email'] ?? '');
    $field('contact_phone', 'School contact phone', $values['contact_phone'] ?? '', 'tel',
        $standard + ['maxlength' => 48, 'autocomplete' => 'tel'], $errors['contact_phone'] ?? '');
    ?>
</fieldset>

<fieldset class="d-grid gap-3">
    <legend class="h5">Representative</legend>
    <?php
    $field('representative_name', 'Representative’s name', $values['representative_name'] ?? '',
        options: $standard + ['maxlength' => 120, 'autocomplete' => 'name'], error: $errors['representative_name'] ?? '');
    $field('representative_position', 'Representative’s position', $values['representative_position'] ?? '',
        options: $standard + ['maxlength' => 100, 'autocomplete' => 'organization-title'], error: $errors['representative_position'] ?? '');
    $field('email', 'Representative’s email', $values['email'] ?? '', 'email',
        ($registration ? $standard : ['readonly' => '']) + ['maxlength' => 254, 'autocomplete' => 'email'], $errors['email'] ?? '');
    ?>
</fieldset>
