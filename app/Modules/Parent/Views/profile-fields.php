<?php

use Furoeducare\Support\NigerianStates;

$required = ['required' => ''];
?>
<div class="row g-3">
    <div class="col-sm-6">
        <?php $field('first_name', 'First name', $values['first_name'] ?? '',
            options: $required + ['maxlength' => 80, 'autocomplete' => 'given-name'], error: $errors['first_name'] ?? ''); ?>
    </div>
    <div class="col-sm-6">
        <?php $field('last_name', 'Last name', $values['last_name'] ?? '',
            options: $required + ['maxlength' => 80, 'autocomplete' => 'family-name'], error: $errors['last_name'] ?? ''); ?>
    </div>
</div>
<?php
$field('email', $registration ? 'Email address' : 'Verified email address', $values['email'] ?? '', 'email',
    ($registration ? $required : ['readonly' => '']) + ['maxlength' => 254, 'autocomplete' => 'email'], $errors['email'] ?? '');
$field('phone', 'Phone number', $values['phone'] ?? '', 'tel', $required + ['maxlength' => 48, 'autocomplete' => 'tel'], $errors['phone'] ?? '',
    'Use a Nigerian phone number or include the international calling code.');
?>
<div class="row g-3">
    <div class="col-sm-6">
        <?php $field('state_code', 'State or FCT', $values['state_code'] ?? '', 'select', NigerianStates::NAMES,
            $errors['state_code'] ?? '', required: true); ?>
    </div>
    <div class="col-sm-6">
        <?php $field('city', 'City or town', $values['city'] ?? '',
            options: $required + ['maxlength' => 100, 'autocomplete' => 'address-level2'], error: $errors['city'] ?? ''); ?>
    </div>
</div>
