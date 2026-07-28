# SkyInclude HeadlessDomains Bridge

Optional, standalone integration for activating a previously reserved
SkyInclude SLD after HeadlessDomains Partners approves a verified legacy-holder
claim.

This service is intentionally separate from Registry Dash. It does not change
or extend Nathan's shared Registry Dash/HNSAU application.

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

- authenticates a dedicated bridge bearer token;
- allows only explicitly configured TLDs;
- verifies that the configured SkyInclude account owns the parent staked TLD;
- requires the parent TLD to remain private (`live = 0`);
- requires an existing owner-created reservation;
- assigns that reservation to the configured SkyInclude account with the exact
  approved expiration;
- disables Registry Dash auto-renewal for the zone; and
- stores an idempotency receipt in the existing registry log.

It does not create a payment, invoice, sale, domain registration, settlement,
or DNS record.

## Why direct database access is required

Registry Dash's current public API can reserve labels, but it cannot activate a
reservation with an exact normalized expiration without invoking legacy
one-year behavior. Keeping Registry Dash unchanged therefore requires a small
sidecar with narrowly scoped access to the same MySQL server.

Use a dedicated MySQL account. It needs only:

```sql
GRANT SELECT ON registry.staked TO 'headlessdomains_bridge'@'%';
GRANT SELECT, INSERT ON registry.log TO 'headlessdomains_bridge'@'%';
GRANT SELECT, UPDATE ON pdns.domains TO 'headlessdomains_bridge'@'%';
```

Replace `registry`, `pdns`, the username, and host restriction with the actual
deployment values. Prefer a private network and restrict the database account
to the bridge service's source host.

Both databases must be on the same MySQL server and the affected tables must
support transactions.

## Configuration

Copy `.env.example` and configure:

- `BRIDGE_API_KEY`: a random secret of at least 32 characters.
- `MYSQL_DSN`, `MYSQL_USER`, `MYSQL_PASSWORD`: the dedicated MySQL connection.
- `REGISTRY_DB_NAME`: Registry Dash application database.
- `PDNS_DB_NAME`: PowerDNS database containing `domains`.
- `SKYINCLUDE_ACCOUNT_ID`: numeric Registry Dash account ID for
  `admin@skyinclude.com`.
- `ALLOWED_TLDS`: explicit comma-separated allowlist.
- `EXPECTED_REGISTRAR`: expected reservation registrar label.
- `MAX_EXPIRATION_YEARS`: maximum accepted future term; defaults to 10.

No secret belongs in GitHub.

## Run

```bash
docker build -t skyinclude-headlessdomains-bridge .
docker run --rm -p 8080:8080 --env-file .env skyinclude-headlessdomains-bridge
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
php -l tests/contract.php
php tests/contract.php
```

The contract test is database-free. A production-like integration test still
requires an isolated MySQL fixture before enabling the Partners feature flag.
