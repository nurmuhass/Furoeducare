<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Parent;

use Furoeducare\Core\Request;
use Furoeducare\Modules\Auth\AuthInput;
use Furoeducare\Support\NigerianStates;

final class ParentInput
{
    public static function profile(Request $request, bool $registration = false, bool $acceptance = false): array
    {
        $errors = [];
        $values = [
            'first_name' => self::text($request, 'first_name', 'first name', 80, $errors),
            'last_name' => self::text($request, 'last_name', 'last name', 80, $errors),
            'state_code' => $request->form('state_code'),
            'city' => self::text($request, 'city', 'city or town', 100, $errors),
            'phone' => AuthInput::phone($request->form('phone')),
        ];
        if (!isset(NigerianStates::NAMES[$values['state_code']])) {
            $errors['state_code'] = 'Choose a state or the FCT.';
            $values['state_code'] = '';
        }
        if ($values['phone'] === null) {
            $errors['phone'] = 'Enter a Nigerian number or include the international calling code.';
            $values['phone'] = substr($request->form('phone'), 0, 48);
        }
        if (preg_match('/[\p{L}\p{N}]/u', $values['city']) !== 1) {
            $errors['city'] ??= 'Include a letter or number in the city or town.';
        }

        if ($registration) {
            $values['email'] = AuthInput::email($request->form('email'));
            if ($values['email'] === null) {
                $errors['email'] = 'Enter a valid email address.';
                $values['email'] = substr($request->form('email'), 0, 254);
            }
            $errors += AuthInput::passwordErrors($request->form('password'), $request->form('password_confirmation'));
        }
        if ($registration || $acceptance) {
            foreach (['terms' => 'Accept the Terms of Use.', 'privacy' => 'Acknowledge the Privacy Notice.'] as $key => $message) {
                $values[$key] = $request->form($key) === '1' ? '1' : '';
                if ($values[$key] === '') {
                    $errors[$key] = $message;
                }
            }
        }
        return [$values, $errors];
    }

    private static function text(
        Request $request, string $name, string $label, int $length, array &$errors, bool $optionalMultiline = false,
    ): string {
        $input = $request->form($name);
        $value = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $input) ?? trim($input);
        $plain = $optionalMultiline ? str_replace(["\r", "\n", "\t"], '', $value) : $value;
        if ($value === '' && !$optionalMultiline) {
            $errors[$name] = "Enter the $label.";
        } elseif (preg_match('/\A.{0,' . $length . '}\z/us', $value) !== 1 || preg_match('/\p{C}/u', $plain) !== 0) {
            $errors[$name] = "Use up to $length characters for the $label.";
        }
        return substr($value, 0, $length * 4);
    }
}
