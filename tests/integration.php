<?php
declare(strict_types=1);

require_once __DIR__.'/../src/ReservedActivation.php';
require_once __DIR__.'/../src/RuntimeConfig.php';

function integrationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO(
    (string)getenv('MYSQL_DSN'),
    (string)getenv('MYSQL_USER'),
    (string)getenv('MYSQL_PASSWORD'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$pdo->exec('DROP DATABASE IF EXISTS registry');
$pdo->exec('DROP DATABASE IF EXISTS pdns');
$pdo->exec('CREATE DATABASE registry');
$pdo->exec('CREATE DATABASE pdns');
$pdo->exec(
    'CREATE TABLE registry.users ('.
    'id INT PRIMARY KEY, email VARCHAR(320), api VARCHAR(32)) ENGINE=InnoDB'
);
$pdo->exec(
    'CREATE TABLE registry.staked ('.
    'tld VARCHAR(255) PRIMARY KEY, owner VARCHAR(40), live TINYINT NOT NULL) ENGINE=InnoDB'
);
$pdo->exec(
    'CREATE TABLE registry.log ('.
    'ai INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255), action VARCHAR(100), '.
    'reason VARCHAR(100), time BIGINT) ENGINE=InnoDB'
);
$pdo->exec(
    'CREATE TABLE pdns.domains ('.
    'uuid VARCHAR(64) PRIMARY KEY, name VARCHAR(255), account INT NULL, '.
    'expiration BIGINT, renew TINYINT, registrar VARCHAR(64) NULL) ENGINE=InnoDB'
);

$apiKey = '0123456789abcdef0123456789abcdef';
$pdo->prepare('INSERT INTO registry.users (id, email, api) VALUES (42, ?, ?)')
    ->execute(['admin@skyinclude.com', $apiKey]);
$pdo->exec("INSERT INTO registry.staked (tld, owner, live) VALUES ('coffees', '42', 0)");
$pdo->exec(
    "INSERT INTO pdns.domains (uuid, name, account, expiration, renew, registrar) VALUES ".
    "('ade97a05d3854ea2b37871a7431f7be2', 'example.coffees', NULL, 0, 1, 'SkyInclude')"
);

$accountId = authenticateRegistryApiKey(
    $pdo,
    'registry',
    'Bearer '.$apiKey,
    'admin@skyinclude.com'
);
integrationExpect($accountId === 42, 'existing Registry Dash API key should resolve custody account');

$request = normalizeActivationRequest([
    'zone' => 'ade97a05d3854ea2b37871a7431f7be2',
    'expiration' => strtotime('+2 years'),
    'idempotency_key' => 'headlessdomains:first-party:claim-123:v1',
], $accountId);
$service = new ReservedActivationService(
    $pdo,
    'registry',
    'pdns',
    $accountId,
    ['coffees'],
    'SkyInclude'
);
$activated = $service->activate($request);
integrationExpect($activated['state'] === 'activated', 'first request should activate reservation');
integrationExpect($activated['sale_created'] === false, 'activation should not create a sale');

$zone = $pdo->query(
    "SELECT account, expiration, renew FROM pdns.domains ".
    "WHERE uuid = 'ade97a05d3854ea2b37871a7431f7be2'"
)->fetch(PDO::FETCH_ASSOC);
integrationExpect((int)$zone['account'] === 42, 'reservation should belong to custody account');
integrationExpect((int)$zone['expiration'] === $request['expiration'], 'exact expiration should persist');
integrationExpect((int)$zone['renew'] === 0, 'legacy automatic renewal should remain disabled');

$replay = $service->activate($request);
integrationExpect($replay['state'] === 'existing', 'exact replay should return existing state');
integrationExpect($replay['idempotent'] === true, 'exact replay should be idempotent');
integrationExpect(
    (int)$pdo->query('SELECT COUNT(*) FROM registry.log')->fetchColumn() === 1,
    'exact replay should not create a second receipt'
);

echo "Bridge MySQL integration checks passed\n";
