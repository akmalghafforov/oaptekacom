---
paths:
  - 'app/Services/*DatabasePull*.php'
  - app/Services/database_pull_remote.py
---

# Services

## Pin database pull targets and neutralize inherited libpq routing
Production pulls use the effective Laravel PostgreSQL connection, including DB_URL, and must validate the local Docker endpoint/container against that connection before confirmation. Clear inherited PGHOSTADDR and service routing for both local and remote clients: otherwise libpq can connect to a different server even when PGHOST is set. Keep production credentials on the remote machine and test this flow only with isolated fake executables or disposable databases.

## Unset libpq service routing and preserve PostgreSQL version argument ordering
Remove PGSERVICE from subprocess environments rather than setting it to an empty string: libpq treats the empty value as a service name. Remove inherited PGHOSTADDR too, including in printed recovery commands and the remote helper. Invoke PostgreSQL client version checks with --version first, without prepending --no-password. Fake executables must reject empty PGSERVICE and misplaced --version so tests reflect the real clients.

## Use the production database container Unix socket for remote clients
The production pg_hba policy accepts password-authenticated Unix sockets and the configured database subnet, but rejects TCP loopback. Remote pull discovery and pg_dump run inside the selected PostgreSQL container and must use /var/run/postgresql, matching the production health check. Do not change production HBA rules to accommodate this command.

## Preserve production data throughout database pulls and local recovery
Production export must remain read-only: set default_transaction_read_only=on for remote PostgreSQL sessions and verify transaction_read_only during discovery. Export uses pg_dump only; all database recreation, restore and recovery belong to the validated local target. Reject a Docker source container that is also the local target. Maintain tests proving production fixtures remain unchanged through success, export/backup failures and local recovery failures.
