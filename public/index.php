<?php
declare(strict_types=1);

require_once __DIR__.'/../src/ReservedActivation.php';

header('Content-Type: application/json');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/health') {
    respond(200, ['ok' => true, 'service' => 'skyinclude-headlessdomains-bridge']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/v1/reserved-activations') {
    respond(404, ['ok' => false, 'code' => 'not_found']);
}

$configuredToken = (string)getenv('BRIDGE_API_KEY');
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$providedToken = str_starts_with($authorization, 'Bearer ')
    ? substr($authorization, 7)
    : '';
if (
    strlen($configuredToken) < 32
    || $providedToken === ''
    || !hash_equals($configuredToken, $providedToken)
) {
    respond(401, ['ok' => false, 'code' => 'authentication_required']);
}

try {
    $accountId = filter_var(getenv('SKYINCLUDE_ACCOUNT_ID'), FILTER_VALIDATE_INT);
    if ($accountId === false || $accountId < 1) {
        throw new RuntimeException('SKYINCLUDE_ACCOUNT_ID is not configured.');
    }

    $input = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new JsonException('Request body must be a JSON object.');
    }
    $request = normalizeActivationRequest($input, (int)$accountId);

    $pdo = new PDO(
        (string)getenv('MYSQL_DSN'),
        (string)getenv('MYSQL_USER'),
        (string)getenv('MYSQL_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $allowedTlds = array_values(array_filter(array_map(
        static fn (string $tld): string => strtolower(trim($tld, " .\t\n\r\0\x0B")),
        explode(',', (string)getenv('ALLOWED_TLDS'))
    )));
    if ($allowedTlds === []) {
        throw new RuntimeException('ALLOWED_TLDS is not configured.');
    }

    $service = new ReservedActivationService(
        $pdo,
        (string)getenv('REGISTRY_DB_NAME'),
        (string)getenv('PDNS_DB_NAME'),
        (int)$accountId,
        $allowedTlds,
        (string)getenv('EXPECTED_REGISTRAR')
    );
    respond(200, ['ok' => true, 'data' => $service->activate($request)]);
} catch (BridgeError $error) {
    respond(409, ['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()]);
} catch (JsonException) {
    respond(400, ['ok' => false, 'code' => 'invalid_json']);
} catch (Throwable) {
    respond(500, ['ok' => false, 'code' => 'bridge_error']);
}
