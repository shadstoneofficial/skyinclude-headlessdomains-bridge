<?php
declare(strict_types=1);

final class BridgeError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}

function normalizeActivationRequest(array $input, int $accountId, ?int $now = null): array
{
    $now ??= time();
    $zone = strtolower(trim((string)($input['zone'] ?? '')));
    $key = trim((string)($input['idempotency_key'] ?? ''));
    $expiration = filter_var($input['expiration'] ?? null, FILTER_VALIDATE_INT);

    if (!preg_match('/^[a-f0-9]{32,64}$/', $zone)) {
        throw new BridgeError('invalid_reserved_zone', 'A valid reserved-zone UUID is required.');
    }
    if (
        strlen($key) < 8
        || strlen($key) > 120
        || !preg_match('/^[A-Za-z0-9:._-]+$/', $key)
    ) {
        throw new BridgeError('invalid_idempotency_key', 'A valid idempotency key is required.');
    }
    if ($expiration === false || $expiration <= $now) {
        throw new BridgeError('expiration_must_be_in_the_future', 'Expiration must be in the future.');
    }

    $maximumYears = max(1, (int)(getenv('MAX_EXPIRATION_YEARS') ?: 10));
    if ($expiration > strtotime("+{$maximumYears} years", $now)) {
        throw new BridgeError(
            'expiration_exceeds_configured_horizon',
            'Expiration exceeds the configured maximum horizon.'
        );
    }

    $normalized = [
        'zone' => $zone,
        'expiration' => (int)$expiration,
        'account' => $accountId,
    ];

    return $normalized + [
        'idempotency_key' => $key,
        'receipt_action' => 'activateReserved:'.hash('sha256', $key),
        'request_hash' => hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES)),
    ];
}

function activationEligibility(
    array|false $zone,
    array|false $staked,
    int $accountId,
    array $allowedTlds,
    string $expectedRegistrar
): string|false {
    if (!$zone) {
        return 'reserved_zone_not_found';
    }

    $parts = explode('.', strtolower((string)$zone['name']));
    $tld = (string)end($parts);
    if (!in_array($tld, $allowedTlds, true)) {
        return 'parent_tld_not_allowed';
    }
    if ($zone['account'] !== null || $zone['registrar'] === null) {
        return 'zone_is_not_an_owner_reservation';
    }
    if ($expectedRegistrar !== '' && !hash_equals($expectedRegistrar, (string)$zone['registrar'])) {
        return 'reservation_registrar_mismatch';
    }
    if (!$staked || (int)$staked['owner'] !== $accountId) {
        return 'parent_tld_not_owned_by_configured_account';
    }
    if ((int)$staked['live'] !== 0) {
        return 'parent_tld_must_be_private';
    }

    return false;
}

final class ReservedActivationService
{
    private string $registryDb;
    private string $pdnsDb;

    public function __construct(
        private readonly PDO $pdo,
        string $registryDb,
        string $pdnsDb,
        private readonly int $accountId,
        private readonly array $allowedTlds,
        private readonly string $expectedRegistrar
    ) {
        $this->registryDb = self::identifier($registryDb);
        $this->pdnsDb = self::identifier($pdnsDb);
    }

    public function activate(array $request): array
    {
        $lockName = 'ar:'.substr(hash('sha256', $request['idempotency_key']), 0, 61);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 5) AS acquired');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new BridgeError('activation_lock_unavailable', 'Could not acquire activation lock.');
        }

        try {
            return $this->activateLocked($request);
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private function activateLocked(array $request): array
    {
        try {
            $this->pdo->beginTransaction();

            $receiptQuery = $this->pdo->prepare(
                "SELECT domain, reason FROM `{$this->registryDb}`.`log` ".
                'WHERE action = ? ORDER BY ai DESC LIMIT 1 FOR UPDATE'
            );
            $receiptQuery->execute([$request['receipt_action']]);
            $receipt = $receiptQuery->fetch(PDO::FETCH_ASSOC);

            $zoneQuery = $this->pdo->prepare(
                "SELECT uuid, name, account, expiration, registrar FROM `{$this->pdnsDb}`.`domains` ".
                'WHERE uuid = ? FOR UPDATE'
            );
            $zoneQuery->execute([$request['zone']]);
            $zone = $zoneQuery->fetch(PDO::FETCH_ASSOC);

            if ($receipt) {
                return $this->completeReplay($request, $zone, $receipt);
            }

            $parts = explode('.', strtolower((string)($zone['name'] ?? '')));
            $tld = (string)end($parts);
            $stakedQuery = $this->pdo->prepare(
                "SELECT tld, owner, live FROM `{$this->registryDb}`.`staked` ".
                'WHERE tld = ? FOR UPDATE'
            );
            $stakedQuery->execute([$tld]);
            $staked = $stakedQuery->fetch(PDO::FETCH_ASSOC);

            $error = activationEligibility(
                $zone,
                $staked,
                $this->accountId,
                $this->allowedTlds,
                $this->expectedRegistrar
            );
            if ($error !== false) {
                throw new BridgeError($error, 'The reserved domain is not eligible for activation.');
            }

            $update = $this->pdo->prepare(
                "UPDATE `{$this->pdnsDb}`.`domains` ".
                'SET account = ?, expiration = ?, renew = 0 WHERE uuid = ? AND account IS NULL'
            );
            $update->execute([$this->accountId, $request['expiration'], $request['zone']]);
            if ($update->rowCount() !== 1) {
                throw new BridgeError(
                    'reserved_zone_state_changed',
                    'The reservation changed before activation.'
                );
            }

            $receiptInsert = $this->pdo->prepare(
                "INSERT INTO `{$this->registryDb}`.`log` (domain, action, reason, time) ".
                'VALUES (?, ?, ?, ?)'
            );
            $receiptInsert->execute([
                $request['zone'],
                $request['receipt_action'],
                $request['request_hash'],
                time(),
            ]);

            $this->pdo->commit();
            return self::success($zone, $request, 'activated', false);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function completeReplay(array $request, array|false $zone, array $receipt): array
    {
        $exactReceipt = (
            (string)$receipt['domain'] === $request['zone']
            && hash_equals((string)$receipt['reason'], $request['request_hash'])
        );
        $exactState = (
            $zone
            && (int)$zone['account'] === $this->accountId
            && (int)$zone['expiration'] === $request['expiration']
        );
        if (!$exactReceipt || !$exactState) {
            throw new BridgeError(
                'idempotency_conflict',
                'The idempotency key was used for different data or the activated state changed.'
            );
        }

        $this->pdo->commit();
        return self::success($zone, $request, 'existing', true);
    }

    private static function success(array $zone, array $request, string $state, bool $idempotent): array
    {
        return [
            'domain' => $zone['name'],
            'zone' => $request['zone'],
            'expiration' => $request['expiration'],
            'state' => $state,
            'idempotent' => $idempotent,
            'payment_created' => false,
            'sale_created' => false,
            'registration_created' => false,
            'legacy_dns_imported' => false,
        ];
    }

    private static function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }
        return $value;
    }
}
