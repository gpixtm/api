<?php
declare(strict_types=1);
namespace App\Presentation;

/** Stable client codes; diagnostics remain English and independent of locale. */
final class ErrorCodes
{
    public static function forMessage(string $message): string
    {
        return match ($message) {
            'The password must contain 8 to 128 characters.' => 'invalidPassword',
            'Username: 3 to 32 letters, digits or underscores.' => 'invalidUsername',
            'Invalid email address.' => 'invalidEmail',
            'Sign in to continue.' => 'signInRequired',
            'Incorrect username or password.' => 'invalidCredentials',
            'Session expired. Please sign in again.' => 'sessionExpired',
            'Invalid session. Please sign in again.' => 'invalidSession',
            'Username or email already in use.' => 'accountAlreadyExists',
            'Too many attempts. Try again in 15 minutes.' => 'rateLimited',
            'Email delivery unavailable. Try again later.' => 'resetUnavailable',
            'Invalid or expired code. Request a new code.' => 'invalidResetCode',
            'Incorrect current password.' => 'incorrectCurrentPassword',
            'All fields must be text.' => 'invalidFields',
            'Unknown operation.' => 'unknownOperation',
            'Form too large.' => 'formTooLarge',
            'Text contains a lost character. Correct its name or reimport the original GPX.' => 'damagedText',
            'Invalid origin or destination coordinates.' => 'invalidRouteCoordinates',
            'Wait two seconds before recalculating.' => 'waitBeforeRouting',
            'Walk the whole trail before reviewing it.' => 'completionRequired',
            'Invalid rating' => 'invalidRating',
            'Review comment too long' => 'reviewTooLong',
            'Invalid place' => 'invalidPlace',
            'Place too far from the trail' => 'placeTooFar',
            'Only its author can change this place.' => 'placeNotYours',
            'Invalid map area' => 'invalidMapArea',
            'Search too long' => 'searchTooLong',
            'Invalid group' => 'invalidGroup',
            'Unknown group member' => 'unknownGroupMember',
            'A group cannot contain itself' => 'groupContainsItself',
            'Only its author can change this group.' => 'groupNotYours',
            'Invalid recording reference' => 'invalidRecordingReference',
            'Service unavailable or resource not found' => 'serviceUnavailable',
            default => 'invalidRequest',
        };
    }
}
