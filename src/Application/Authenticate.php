<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\AccountRules;
use App\Domain\Authentication;

final readonly class Authenticate
{
    public function __construct(private Authentication $accounts) {}

    public function identify(string $access): array
    {
        return $this->accounts->authenticate($access);
    }

    public function run(string $action, array $values, ?array $user, string $access, string $clientIp): array
    {
        if (in_array($action, ['login', 'register', 'forgot-password', 'reset-password', 'refresh'], true)) {
            $this->accounts->throttle('ip:'.$action, $clientIp, $action === 'login' ? 30 : 20);
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('All fields must be text.');
            }
        }
        $get = fn(string $key): string => $values[$key] ?? '';
        if (in_array($action, ['register', 'reset-password', 'change-password'], true)) {
            AccountRules::password($get('password'));
        }
        switch ($action) {
            case 'register': return $this->accounts->register($values);
            case 'login': return $this->accounts->login($values);
            case 'refresh': return $this->accounts->refresh($get('refresh_token'));
            case 'me': return ['user' => $user];
            case 'logout':
                $this->accounts->logout($get('refresh_token'), $access);
                break;
            case 'forgot-password':
                $this->accounts->forgot($get('email'), $get('language') === 'fr' ? 'fr' : 'en');
                return ['message' => 'If this account exists, the request has been queued for delivery.'];
            case 'reset-password':
                $this->accounts->reset($get('token'), $get('password'));
                break;
            case 'change-password':
                $this->accounts->change($user['id'], $get('current_password'), $get('password'));
                break;
            default: throw new \InvalidArgumentException('Unknown operation.');
        }
        return ['message' => 'Operation completed.'];
    }
}
