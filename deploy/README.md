# Deploying Looking for Luck on a server

The app runs in Docker behind [Caddy](https://caddyserver.com), which serves
HTTPS on your domain with a free certificate it renews by itself. One script
installs and updates everything over SSH.

## What you need

- A Linux server you can reach over SSH, with **git** and **Docker** (with the
  Compose plugin, 2.24 or later). With the bundled Caddy, ports **80** and
  **443** must be free; otherwise, see the last section.
- A domain or subdomain whose **DNS A (and AAAA) record points to the server**,
  e.g. `luck.example.com`. Your main site can stay where it is (Vercel or
  elsewhere): only this subdomain points to the server.

## First deployment

```bash
deploy/deploy.sh user@server              # 1. clones the repository and creates .env.prod
ssh user@server nano looking-for-luck/.env.prod   # 2. set LFL_DOMAIN, COMPOSE_PROFILES (and LFL_GA4_ID)
deploy/deploy.sh user@server              # 3. builds and starts the site
```

Open `https://your-domain`. The first visit can take a few seconds while
Caddy gets the certificate and the app downloads the draw history.

## Updates

Push to the repository, then run `deploy/deploy.sh user@server` again: it pulls,
rebuilds and restarts. Data and certificates live in Docker volumes and are
kept.

## Settings (`.env.prod`)

| Variable | Purpose |
|---|---|
| `LFL_DOMAIN` | The site's address. |
| `APP_SECRET` | Generated on the first deployment; keep it. |
| `LFL_GRIDS` | `browser` (default here): each visitor keeps their grids in a cookie of their own browser, as a public site has no accounts. `server`: one list shared by everyone, for a private install only. |
| `LFL_GA4_ID` | Optional Google Analytics 4 ID, behind a consent banner. |
| `LFL_COUNTRY` | Optional country of the mandatory gambling warning (`be`, `fr`…). |

## Already running a web server on 80/443?

Leave `COMPOSE_PROFILES` empty in `.env.prod`: only the app starts, on
`127.0.0.1:8090`. Then proxy your domain to it from your web server, with
HTTPS as for your other sites (e.g. `certbot`), forwarding the protocol.

Apache (`a2enmod proxy proxy_http headers`):

```apache
<VirtualHost *:80>
    ServerName luck.example.com
    ProxyPreserveHost On
    ProxyPass        / http://127.0.0.1:8090/
    ProxyPassReverse / http://127.0.0.1:8090/
    RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}
</VirtualHost>
```

nginx:

```nginx
location / {
    proxy_pass http://127.0.0.1:8090;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

## Useful commands (on the server, in the project directory)

```bash
alias lfl='docker compose -f compose.yaml -f deploy/compose.prod.yaml --env-file .env.prod'
lfl logs -f app          # logs
lfl exec app php bin/console app:sync --force   # fetch the draws now
lfl down                 # stop (data is kept)
```
