# SkyInclude HeadlessDomains Bridge

Optional, standalone integration for activating a previously reserved
SkyInclude SLD after HeadlessDomains Partners approves a verified legacy-holder
claim.

This service is intentionally separate from Registry Dash. It does not change
or extend Nathan's shared Registry Dash/HNSAU application.

## Existing credentials, not a second database account

Per the SkyInclude maintainer's direction, the bridge reads the database
settings already stored in Registry Dash's `config.php`:

- `sqlHost`
- `sqlUser`
- `sqlPass`
- `sqlDatabase`
- `sqlDatabaseDNS`
- `siteName`

Mount that existing file read-only and set `REGISTRY_DASH_CONFIG_PATH` to its
mounted location. Do not copy the file into this repository, Docker image, or
logs.

The request uses the same Registry Dash bearer API key already used by
HeadlessDomains Partners. The bridge verifies that key against the existing
`users.api` field and requires it to belong to `SKYINCLUDE_CUSTODY_EMAIL`.
There is no second MySQL credential, bridge API key, or numeric account ID to
configure.

## What it does

`POST /v1/reserved-activations` accepts:

```json
{
  "zone": "ade97a05d3854ea2b37871a7431f7be2",
  "expiration": 2076278400,
  "idempotency_key": "headlessdomains:first-party:claim-uuid:v1"
}
```

The bridge:

- authenticates the existing Registry Dash API key and custody email;
- allows only explicitly configured TLDs;
- verifies that the authenticated SkyInclude account owns the staked parent;
- requires the parent TLD to remain private (`live = 0`);
- requires an existing owner-created reservation;
- assigns that reservation to the authenticated account with the exact approved
  expiration;
- disables Registry Dash auto-renewal for the zone; and
- stores an idempotency receipt in the existing registry log.

It does not create a payment, invoice, sale, domain registration, settlement,
or DNS record.

## Configuration

```text
REGISTRY_DASH_CONFIG_PATH=/run/secrets/registry-dash-config.php
SKYINCLUDE_CUSTODY_EMAIL=admin@skyinclude.com
ALLOWED_TLDS=coffees,giveaways,use,ez
MAX_EXPIRATION_YEARS=10
```

No secret belongs in GitHub. The optional direct `MYSQL_*` environment fallback
exists only for isolated development and CI; the SkyInclude deployment should
use the mounted dashboard config.

## Run beside Registry Dash

```bash
docker build -t skyinclude-headlessdomains-bridge .
docker run --rm \
  -p 8080:8080 \
  --env-file .env \
  --mount type=bind,src=/absolute/path/to/registry-dash/etc/config.php,dst=/run/secrets/registry-dash-config.php,readonly \
  skyinclude-headlessdomains-bridge
```

Health check:

```bash
curl http://localhost:8080/health
```

The mutation endpoint must only be called by HeadlessDomains Partners after its
existing custody, private-namespace, protected-inventory, claim-approval, fresh
plan-hash, and explicit apply gates pass.

## Tests

```bash
php -l public/index.php
php -l src/ReservedActivation.php
php -l src/RuntimeConfig.php
php -l tests/contract.php
php -l tests/integration.php
php tests/contract.php
```

The contract test uses a credential-free Registry Dash config fixture. GitHub
Actions also runs `tests/integration.php` against an isolated MySQL 8 service,
covering existing API-key authentication, exact activation state, disabled
legacy renewal, and idempotent replay. Production remains disabled until the
same checks pass against an isolated SkyInclude staging fixture.
