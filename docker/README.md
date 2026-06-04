# Running a Telaris instance with Docker

This packages a **Telaris instance** (the node you run to publish galaxies and join the Pluriverse federation) as containers: the app (php-fpm), nginx, an optional bundled database, the federation schedulers, and optional automatic HTTPS.

Published images (multi-arch, linux/amd64 + linux/arm64):

- `ghcr.io/theagitist/telaris-app` (php-fpm + the Telaris code)
- `ghcr.io/theagitist/telaris-web` (nginx)

Pin a released version with `TELARIS_TAG` in `.env` (e.g. `TELARIS_TAG=6.11.30`); `latest` tracks the newest published build.

## Requirements

- Docker Engine + Docker Compose v2.
- **A stable, dedicated DNS name for this instance** (required). Federation identity is tied to the hostname, and it is what the TLS certificate is issued for. Point an A/AAAA record at this host.
- Ports **80 and 443** reachable from the internet if you use the bundled auto-TLS proxy.

## Quick start

```sh
cp .env.example .env
# edit .env: set TELARIS_HOSTNAME (your DNS name), ACME_EMAIL, and a strong DB_PASS
docker compose up -d
```

Then open `https://<your-hostname>/admin/setup.php` to create the first operator account. The 3D archive is at `/`.

The database schema builds itself on first run (no migration step). First start also mints this instance's federation keys into the `secrets` volume.

## Choosing your database

One line in `.env` (`COMPOSE_PROFILES`) decides everything; the command never changes.

| Goal | `COMPOSE_PROFILES` | DB settings in `.env` |
|---|---|---|
| Local bundled DB + auto HTTPS (turnkey) | `bundled-db,tls` | leave `DB_HOST=db` |
| Local bundled DB, your own TLS proxy | `bundled-db` | leave `DB_HOST=db` |
| **Your own external DB** + auto HTTPS | `tls` | set `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` |
| Your own external DB + your own proxy | (empty) | set `DB_*` |

### Using your own external database

If you already run a MySQL 8+ or MariaDB 10.6+ server, point the instance at it instead of the bundled container. Two steps:

**1. Create a database and a dedicated user on your server** (run once, as an admin):

```sql
CREATE DATABASE telaris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'telaris'@'%' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON telaris.* TO 'telaris'@'%';
FLUSH PRIVILEGES;
```

The app builds its own schema on first request (no migration step); the user just needs full rights on its own database.

**2. Edit `.env`** to drop the bundled DB and point at your server:

```ini
# remove bundled-db; keep tls if you want the bundled auto-HTTPS proxy
COMPOSE_PROFILES=tls
DB_HOST=db.example.org      # your server's hostname or IP
DB_PORT=3306
DB_NAME=telaris
DB_USER=telaris
DB_PASS=a-strong-password
```

Then `docker compose up -d`. The `db` container never starts (its `bundled-db` profile is off), and the app connects out to your server.

**Managed databases that require TLS** (e.g. DigitalOcean, AWS RDS, PlanetScale): put the provider's CA certificate on the host, mount it into the app container, and set `DB_SSL_CA` to its in-container path. Uncomment the `./certs` volume in `docker-compose.yml`:

```yaml
    volumes:
      # ...
      - ./certs:/certs:ro
```

```ini
DB_SSL_CA=/certs/ca.pem
```

The app then validates the chain against that CA and skips hostname verification (managed-cluster certificates often have a CN that does not match the private endpoint host). Place the CA file at `./certs/ca.pem` next to your `docker-compose.yml`.

**Moving from bundled to external later:** dump the bundled DB, import it into your server, then flip `.env` (clear `bundled-db` from `COMPOSE_PROFILES`, set `DB_HOST` to your server) and `docker compose up -d`:

```sh
docker compose exec db mariadb-dump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > telaris-db.sql
# import telaris-db.sql into your server, edit .env, then:
docker compose up -d
```

## TLS / reverse proxy

- **Bundled (`tls` profile):** Caddy obtains and renews a Let's Encrypt certificate for `TELARIS_HOSTNAME` automatically. Just point DNS at the host and open 80/443.
- **Your own proxy:** drop the `tls` profile and point your proxy (nginx/Traefik/Caddy) at the `web` container on `WEB_HTTP_PORT`. Federation requires HTTPS, so terminate TLS there.

## Persistence & backups

Data lives in named volumes, not the image: `db_data`, `uploads`, `secrets` (federation keys, back these up), `snapshots`, `logs`. Use the in-app snapshot tools (admin) or `docker compose exec` + `mariadb-dump` for DB backups.

## Updating

```sh
docker compose pull && docker compose up -d
```

Pin `TELARIS_TAG` in `.env` to a released version for reproducible installs.

## Security notes

- **No secrets or PII are in the image.** It is built in CI from the public, committed source only; `config.php`, `secrets/`, `uploads/`, snapshots, and logs are gitignored and `.dockerignore`d, so they never enter the build. Your secrets live only in `.env` (read at runtime via environment) and in the volumes on your host.
- Keep `.env` and the `secrets` volume private; they are this instance's credentials and federation identity.

## Building locally instead of pulling

```sh
docker compose build           # builds app + web from this repo
docker compose up -d
```

## For maintainers: publishing the image

Images publish to GHCR via `.github/workflows/docker-publish.yml` when a version tag is pushed:

```sh
git tag v6.11.29 && git push origin v6.11.29
```

After the first publish, set the `telaris-app` and `telaris-web` packages to **Public** once in GitHub (Packages → Package settings → visibility) so operators can pull anonymously.
