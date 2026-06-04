# Running a Telaris instance with Docker

> Status: draft scaffold (2026-06-04). Not yet built/tested end to end; review before relying on it.

This packages a **Telaris instance** (the node you run to publish galaxies and join the Pluriverse federation) as containers: the app (php-fpm), nginx, an optional bundled database, the federation schedulers, and optional automatic HTTPS.

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

For a managed database that requires TLS, set `DB_SSL_CA` to a CA file mounted into the app container (uncomment the `./certs` volume in `docker-compose.yml`).

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

Data lives in named volumes, not the image: `db_data`, `uploads`, `secrets` (federation keys — back these up), `snapshots`, `logs`. Use the in-app snapshot tools (admin) or `docker compose exec` + `mariadb-dump` for DB backups.

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
