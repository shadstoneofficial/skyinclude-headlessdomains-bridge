<?php
declare(strict_types=1);

require_once __DIR__.'/../src/ReservedActivation.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$now = 1785211200;
$valid = normalizeActivationRequest([
    'zone' => 'ade97a05d3854ea2b37871a7431f7be2',
    'expiration' => strtotime('+9 years', $now),
    'idempotency_key' => 'headlessdomains:first-party:claim-123:v1',
], 42, $now);
expect($valid['zone'] === 'ade97a05d3854ea2b37871a7431f7be2', 'valid request');
expect(strlen($valid['request_hash']) === 64, 'request hash');
expect(strlen('ar:'.substr(hash('sha256', $valid['idempotency_key']), 0, 61)) <= 64, 'lock limit');

$replay = normalizeActivationRequest([
    'zone' => 'ade97a05d3854ea2b37871a7431f7be2',
    'expiration' => strtotime('+9 years', $now),
    'idempotency_key' => 'headlessdomains:first-party:claim-123:v1',
], 42, $now);
expect($valid['request_hash'] === $replay['request_hash'], 'stable replay');

$reserved = ['name' => 'example.coffees', 'account' => null, 'registrar' => 'SkyInclude'];
$privateParent = ['owner' => 42, 'live' => 0];
expect(
    activationEligibility($reserved, $privateParent, 42, ['coffees'], 'SkyInclude') === false,
    'eligible private reservation'
);
expect(
    activationEligibility($reserved, ['owner' => 42, 'live' => 1], 42, ['coffees'], 'SkyInclude')
        === 'parent_tld_must_be_private',
    'public parent rejected'
);
expect(
    activationEligibility($reserved, $privateParent, 42, ['ez'], 'SkyInclude')
        === 'parent_tld_not_allowed',
    'unlisted parent rejected'
);
expect(
    activationEligibility(
        ['name' => 'example.coffees', 'account' => 42, 'registrar' => 'SkyInclude'],
        $privateParent,
        42,
        ['coffees'],
        'SkyInclude'
    ) === 'zone_is_not_an_owner_reservation',
    'customer zone rejected'
);

$implementation = file_get_contents(__DIR__.'/../src/ReservedActivation.php');
foreach (['INSERT INTO `sales`', 'INSERT INTO `invoices`', 'StripeClient', 'records`'] as $forbidden) {
    expect(strpos($implementation, $forbidden) === false, "forbidden call: {$forbidden}");
}

echo "Bridge contract checks passed\n";
