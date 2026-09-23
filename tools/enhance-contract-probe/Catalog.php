<?php
namespace EnhanceProbe;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/Refusal.php';

final class Catalog
{
    /** Routes are local templates; no response can supply a route or method. */
    public static function all(): array
    {
        return [
            'licence' => ['GET', '/licence'],
            'plans' => ['GET', '/orgs/{master}/plans'],
            'organisations' => ['GET', '/orgs/{master}/customers?limit=500'],
            'organisation' => ['GET', '/orgs/{org}/'],
            'members' => ['GET', '/orgs/{org}/members?limit=250'],
            'emails' => ['GET', '/orgs/{org}/emails'],
            'logins' => ['GET', '/logins?limit=250'],
            'subscriptions' => ['GET', '/orgs/{master}/customers/{org}/subscriptions?limit=250'],
            'subscription' => ['GET', '/orgs/{org}/subscriptions/{subscription}'],
            'websites' => ['GET', '/orgs/{org}/websites?limit=500'],
            'subscription-websites' => ['GET', '/orgs/{org}/websites?subscriptionId={subscription}&limit=500'],
            'website' => ['GET', '/orgs/{org}/websites/{website}'],
            'domains' => ['GET', '/orgs/{org}/websites/{website}/domains?limit=500'],
            'create-org' => ['POST', '/orgs/{master}/customers', 'org'],
            'create-login' => ['POST', '/logins?orgId={org}', 'login'],
            'owner' => ['POST', '/orgs/{org}/members'],
            'create-subscription' => ['POST', '/orgs/{master}/customers/{org}/subscriptions', 'subscription'],
            'create-website' => ['POST', '/orgs/{org}/websites?kind=normal', 'website'],
            'rename-org' => ['PATCH', '/orgs/{org}'],
            'suspend-org' => ['PATCH', '/orgs/{org}'],
            'reactivate-org' => ['PATCH', '/orgs/{org}'],
            'change-plan' => ['PATCH', '/orgs/{org}/subscriptions/{subscription}'],
            'suspend-subscription' => ['PATCH', '/orgs/{org}/subscriptions/{subscription}'],
            'reactivate-subscription' => ['PATCH', '/orgs/{org}/subscriptions/{subscription}'],
            'recover-password' => ['PUT', '/login/password-recovery'],
            'delete-website' => ['DELETE', '/orgs/{org}/websites/{website}'],
            'delete-subscription-soft' => ['DELETE', '/orgs/{org}/subscriptions/{subscription}?force=false'],
            'delete-subscription-hard' => ['DELETE', '/orgs/{org}/subscriptions/{subscription}?force=true'],
            'delete-org' => ['DELETE', '/orgs/{org}'],
        ];
    }
    public static function get(string $operation): array
    {
        if (!isset(self::all()[$operation])) throw new Refusal('unknown_operation');
        $entry = self::all()[$operation];
        // Organisation read uses exactly the productive route without a trailing slash.
        return [$entry[0], rtrim($entry[1], '/'), $entry[2] ?? null];
    }
    public static function route(string $operation): string
    {
        return preg_replace('/\{(?:master|org|subscription|website)\}/', '{id}', explode('?', self::get($operation)[1])[0]);
    }
}
