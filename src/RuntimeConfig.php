<?php
declare(strict_types=1);

final class BridgeRuntimeConfig
{
    public function __construct(
        public readonly string $dsn,
        public readonly string $databaseUser,
        public readonly string $databasePassword,
        public readonly string $registryDatabase,
        public readonly string $pdnsDatabase,
        public readonly string $expectedRegistrar
    ) {
    }
}

function bridgeDatabaseIdentifier(string $value): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        throw new RuntimeException('Invalid database identifier.');
    }

    return $value;
}

function loadBridgeRuntimeConfig(): BridgeRuntimeConfig
{
    $dashboardConfigPath = trim((string)getenv('REGISTRY_DASH_CONFIG_PATH'));
    if ($dashboardConfigPath !== '') {
        $resolvedPath = realpath($dashboardConfigPath);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException('Registry Dash config is not readable.');
        }

        require $resolvedPath;

        foreach (['sqlHost', 'sqlUser', 'sqlPass', 'sqlDatabase', 'sqlDatabaseDNS'] as $name) {
            if (!array_key_exists($name, $GLOBALS) || trim((string)$GLOBALS[$name]) === '') {
                throw new RuntimeException("Registry Dash config is missing {$name}.");
            }
        }

        $registryDatabase = bridgeDatabaseIdentifier((string)$GLOBALS['sqlDatabase']);
        $pdnsDatabase = bridgeDatabaseIdentifier((string)$GLOBALS['sqlDatabaseDNS']);
        $registrar = trim((string)($GLOBALS['siteName'] ?? ''));

        return new BridgeRuntimeConfig(
            'mysql:host='.(string)$GLOBALS['sqlHost'].
                ';dbname='.$registryDatabase.
                ';charset=utf8mb4',
            (string)$GLOBALS['sqlUser'],
            (string)$GLOBALS['sqlPass'],
            $registryDatabase,
            $pdnsDatabase,
            $registrar
        );
    }

    // Environment fallback exists only for isolated development and CI fixtures.
    $registryDatabase = bridgeDatabaseIdentifier((string)getenv('REGISTRY_DB_NAME'));
    $pdnsDatabase = bridgeDatabaseIdentifier((string)getenv('PDNS_DB_NAME'));

    return new BridgeRuntimeConfig(
        (string)getenv('MYSQL_DSN'),
        (string)getenv('MYSQL_USER'),
        (string)getenv('MYSQL_PASSWORD'),
        $registryDatabase,
        $pdnsDatabase,
        (string)getenv('EXPECTED_REGISTRAR')
    );
}

function authenticateRegistryApiKey(
    PDO $pdo,
    string $registryDatabase,
    string $authorization,
    string $custodyEmail
): int {
    $providedToken = str_starts_with($authorization, 'Bearer ')
        ? trim(substr($authorization, 7))
        : '';
    if ($providedToken === '' || $custodyEmail === '') {
        throw new BridgeError('authentication_required', 'Registry API authentication is required.');
    }

    $registryDatabase = bridgeDatabaseIdentifier($registryDatabase);
    $account = $pdo->prepare(
        "SELECT id FROM `{$registryDatabase}`.`users` WHERE api = ? AND email = ? LIMIT 1"
    );
    $account->execute([$providedToken, $custodyEmail]);
    $accountId = $account->fetchColumn();

    if ($accountId === false || (int)$accountId < 1) {
        throw new BridgeError('authentication_required', 'Registry API authentication is required.');
    }

    return (int)$accountId;
}
