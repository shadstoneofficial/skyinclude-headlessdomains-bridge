<?php
declare(strict_types=1);

require_once __DIR__.'/../src/ReservedActivation.php';
require_once __DIR__.'/../src/RuntimeConfig.php';

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

try {
    $input = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new JsonException('Request body must be a JSON object.');
    }

    $runtime = loadBridgeRuntimeConfig();
    $pdo = new PDO(
        $runtime->dsn,
        $runtime->databaseUser,
        $runtime->databasePassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $accountId = authenticateRegistryApiKey(
        $pdo,
        $runtime->registryDatabase,
        (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        strtolower(trim((string)getenv('SKYINCLUDE_CUSTODY_EMAIL')))
    );
    $request = normalizeActivationRequest($input, $accountId);

    $allowedTlds = array_values(array_filter(array_map(
        static fn (string $tld): string => strtolower(trim($tld, " .\t\n\r\0\x0B")),
        explode(',', (string)getenv('ALLOWED_TLDS'))
    )));
    if ($allowedTlds === []) {
        throw new RuntimeException('ALLOWED_TLDS is not configured.');
    }

    $service = new ReservedActivationService(
        $pdo,
        $runtime->registryDatabase,
        $runtime->pdnsDatabase,
        $accountId,
        $allowedTlds,
        $runtime->expectedRegistrar
    );
    respond(200, ['ok' => true, 'data' => $service->activate($request)]);
} catch (BridgeError $error) {
    $status = $error->errorCode === 'authentication_required' ? 401 : 409;
    respond($status, ['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()]);
} catch (JsonException) {
    respond(400, ['ok' => false, 'code' => 'invalid_json']);
} catch (Throwable) {
    respond(500, ['ok' => false, 'code' => 'bridge_error']);
}
