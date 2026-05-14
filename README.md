# snipeit-oidc

OpenID Connect (OIDC) authentication plugin for [Snipe-IT](https://snipeitapp.com/).

Snipe-IT supports SAML and LDAP out of the box, but not OIDC. This package
plugs into Snipe-IT's Laravel auth pipeline to add a working OIDC login
flow that works with Keycloak, Authentik, Auth0, Azure AD, Google, Okta,
or any other compliant IdP.

---

## What it does

- Adds three routes: `/oidc/login`, `/oidc/callback`, `/oidc/logout`
- Reads OIDC claims and resolves them to a Snipe-IT `User`
- Supports Just-In-Time (JIT) provisioning, or strict mode (user must exist)
- Hook for mapping OIDC groups onto Snipe-IT permissions/groups
- Renders a "Login with SSO" button on the Snipe-IT login page

## What it does NOT do

- Replace local/LDAP/SAML auth — it runs alongside them
- Two-factor enforcement (Snipe-IT's own 2FA still applies post-login)
- Single-logout (SLO) — there's a stub in `logout()` you can extend

---

## Install

1. Copy `snipeit-oidc/` into your Snipe-IT install (e.g. `<snipeit>/packages/snipeit-oidc/`).

2. In Snipe-IT's `composer.json`, add a path repository and require:

   ```jsonc
   {
     "repositories": [
       { "type": "path", "url": "packages/snipeit-oidc" }
     ],
     "require": {
       "bausteln/snipeit-oidc": "*"
     }
   }
   ```

3. ```bash
   composer update bausteln/snipeit-oidc
   php artisan vendor:publish --tag=oidc-config
   ```

4. Add to Snipe-IT's `.env`:

   ```ini
   OIDC_ENABLED=true
   OIDC_PROVIDER_URL=https://keycloak.example.com/realms/snipeit
   OIDC_CLIENT_ID=snipeit
   OIDC_CLIENT_SECRET=...
   OIDC_SCOPES="openid profile email groups"
   OIDC_PROVISIONING=jit
   OIDC_ADMIN_GROUPS=snipeit-admins,it-ops
   ```

5. Add the SSO button to the login page. Edit
   `resources/views/auth/login.blade.php` in your Snipe-IT install and
   add this line below the existing login form:

   ```blade
   @include('oidc::login-button')
   ```

6. Register the redirect URI in your IdP: `https://<your-snipeit>/oidc/callback`

---

## Configuration

All settings live in `config/oidc.php` and read from `.env`. The most
important section is the **claim map** — which OIDC claim corresponds to
which Snipe-IT column. Defaults work for most IdPs; override if yours
emits non-standard claim names.

### Claim map defaults

| Snipe-IT field | OIDC claim          | Env override            |
|----------------|---------------------|-------------------------|
| `username`     | `preferred_username`| `OIDC_CLAIM_USERNAME`   |
| `email`        | `email`             | `OIDC_CLAIM_EMAIL`      |
| `first_name`   | `given_name`        | `OIDC_CLAIM_FIRST_NAME` |
| `last_name`    | `family_name`       | `OIDC_CLAIM_LAST_NAME`  |
| `groups`       | `groups`            | `OIDC_CLAIM_GROUPS`     |

> ⚠️ **Stability matters.** Pick claims that don't change across logins.
> Never use `name` (display name) for `username` — people get married, get
> promoted, change their preferred name. `sub` is always stable but rarely
> human-readable; `preferred_username` is the usual sweet spot.

### Group mapping (USER DECISION REQUIRED)

Open `src/Services/OidcUserResolver.php` and implement `applyGroupMapping()`.
The skeleton is in place; you decide the policy. See the docblock on that
method for the three common strategies (additive / authoritative / first-login).

---

## IdP recipes

### Keycloak

1. Realm → Clients → Create client
2. Client type: **OpenID Connect**, Client ID: `snipeit`
3. Capability config: **Client authentication ON**, Standard flow ON
4. Valid redirect URI: `https://snipeit.example.com/oidc/callback`
5. Client scopes → add a `groups` mapper of type **Group Membership**,
   token claim name `groups`, full group path **off**
6. Credentials tab → copy the secret to `OIDC_CLIENT_SECRET`
7. `.env`:
   ```ini
   OIDC_PROVIDER_URL=https://keycloak.example.com/realms/<your-realm>
   ```

### Azure AD / Entra ID

1. App registrations → New registration
2. Redirect URI: Web → `https://snipeit.example.com/oidc/callback`
3. Certificates & secrets → New client secret
4. Token configuration → add optional claim **groups** (Security groups)
5. `.env`:
   ```ini
   OIDC_PROVIDER_URL=https://login.microsoftonline.com/<tenant-id>/v2.0
   OIDC_CLAIM_GROUPS=groups
   ```
   Note: Azure emits group **object IDs** in the `groups` claim, not names.
   Configure `OIDC_ADMIN_GROUPS` with the GUIDs.

### Authentik (recommended setup for this install)

1. **Customization → Property Mappings**: confirm a Scope Mapping named
   "authentik default OAuth Mapping: OpenID 'groups'" exists. If not,
   create one of type **Scope Mapping**, scope name `groups`, expression:
   ```python
   return {"groups": [group.name for group in user.ak_groups.all()]}
   ```
2. **Applications → Providers → Create → OAuth2/OpenID Provider**
   - Name: `snipeit`
   - Authorization flow: `default-provider-authorization-explicit-consent` (or implicit)
   - Client type: **Confidential**
   - Redirect URIs / Origins: `https://snipeit.example.com/oidc/callback`
   - Signing Key: pick your default certificate
   - **Scopes**: add `openid`, `email`, `profile`, **and `groups`** ← critical
3. **Applications → Create**: bind the provider to an app with slug `snipeit`.
4. Copy the **Client ID** and **Client Secret** from the provider page.
5. **Directory → Groups**: create the groups you want to grant in Snipe-IT.
   Their `name` field must match exactly what you'll put in
   `OIDC_ADMIN_GROUPS` (admins) and what you create as Snipe-IT permission
   groups (non-admin sync targets).
6. `.env`:
   ```ini
   OIDC_ENABLED=true
   OIDC_PROVIDER_URL=https://authentik.example.com/application/o/snipeit/
   OIDC_CLIENT_ID=<from step 4>
   OIDC_CLIENT_SECRET=<from step 4>
   OIDC_SCOPES="openid profile email groups"
   OIDC_PROVISIONING=jit
   OIDC_ADMIN_GROUPS=snipeit-admins
   ```

**Authentik gotchas:**
- The issuer URL **must** end with a trailing slash (`/application/o/snipeit/`)
  or jumbojett's discovery fails with a confusing JWKS error.
- If groups don't appear in Snipe-IT after login, the `groups` scope is
  almost always missing from the provider config (step 2, last bullet).
  Check the Token in Authentik's "Test" view — if `groups` is absent
  there, fix Authentik before debugging Snipe-IT.

---

## Docker deployment

For installs that don't need a full Kubernetes orchestrator,
`docker compose` is the simplest way to run Snipe-IT with this plugin
baked in. The flow is:

1. Build a custom Snipe-IT image with the plugin vendored in
2. Stand up Snipe-IT + MariaDB with `docker compose`
3. Front it with TLS (in production)
4. Register the callback URL in Authentik

### 1. Build the custom image

```bash
mkdir my-snipeit && cd my-snipeit
git clone git@github.com:Bausteln/SnipeIT-OIDC.git snipeit-oidc
cp snipeit-oidc/examples/Dockerfile .
docker build --build-arg SNIPEIT_VERSION=v8.5.0 \
             -t snipeit-oidc:v8.5.0-oidc1 .
```

Push to a registry if you're deploying to a different host:

```bash
docker tag  snipeit-oidc:v8.5.0-oidc1 registry.example.com/snipeit-oidc:v8.5.0-oidc1
docker push registry.example.com/snipeit-oidc:v8.5.0-oidc1
```

### 2. docker-compose stack

`docker-compose.yml`:

```yaml
services:
  snipeit:
    image: snipeit-oidc:v8.5.0-oidc1
    # Or build inline (delete the image: line above if you do):
    # build:
    #   context: .
    #   args:
    #     SNIPEIT_VERSION: v8.5.0
    ports:
      - "8080:80"            # bind to 127.0.0.1:8080 if a reverse proxy fronts it
    env_file: .env
    depends_on:
      mysql:
        condition: service_healthy
    volumes:
      - uploads:/var/www/html/public/uploads
      - storage:/var/www/html/storage
    restart: unless-stopped

  mysql:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: snipeit
      MARIADB_USER: snipeit
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - mysql:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped

volumes:
  uploads:
  storage:
  mysql:
```

### 3. Environment file

`.env` (alongside `docker-compose.yml` — make sure it's in `.gitignore`):

```ini
# --- Snipe-IT ---
APP_URL=https://snipeit.example.com
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:GENERATE_ME           # see below

# --- Database ---
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=snipeit
DB_USERNAME=snipeit
DB_PASSWORD=change-this-strong-password
DB_ROOT_PASSWORD=different-strong-password

# --- OIDC plugin ---
OIDC_ENABLED=true
OIDC_PROVIDER_URL=https://authentik.example.com/application/o/snipeit/
OIDC_CLIENT_ID=snipeit
OIDC_CLIENT_SECRET=copy-from-authentik
OIDC_SCOPES="openid profile email groups"
OIDC_PROVISIONING=jit
OIDC_ADMIN_GROUPS=snipeit-admins
```

Generate the `APP_KEY` once and paste the output into `.env`:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
```

> ⚠️ `APP_KEY` rotates session cookies and encrypted DB columns. Set it
> **before first launch** and never change it on a populated database.

### 4. Launch

```bash
docker compose up -d
docker compose logs -f snipeit       # watch boot + migrations
```

First boot runs Snipe-IT's migrations automatically. Once the logs
settle, open `${APP_URL}` and:

1. **Complete the Snipe-IT setup wizard** — create the first local admin.
   Keep this account: it's your break-glass if OIDC ever breaks.
2. Log out, click **Login with SSO**, verify the round-trip to Authentik.
3. Optionally, demote your local admin to a regular user once at least
   one OIDC-provisioned superuser exists.

### 5. TLS in production

The official Snipe-IT image ships **HTTP-only** Apache. In production
you almost always want a reverse proxy in front of it:

- **Caddy** is the lowest-effort: a single-line `Caddyfile`
  (`snipeit.example.com { reverse_proxy snipeit:80 }`) gets you
  automatic Let's Encrypt certs.
- **Traefik** if you already run it for other containers.
- **Nginx + certbot** if you need fine-grained control.

The OIDC plugin builds the `redirect_uri` from `APP_URL`, so set that
to the **public HTTPS URL**, not `http://localhost:8080`. Get this wrong
and you'll see a `redirect_uri_mismatch` from Authentik.

A minimal Caddy add-on to the compose file:

```yaml
  caddy:
    image: caddy:2
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    restart: unless-stopped

volumes:
  caddy_data:
  caddy_config:
```

`Caddyfile`:

```caddy
snipeit.example.com {
    reverse_proxy snipeit:80
}
```

Then change the Snipe-IT service to expose only on the internal network
(remove the `ports:` block) and set `APP_URL=https://snipeit.example.com`.

### 6. Authentik registration

Identical to the bare-install case — see
[Authentik](#authentik-recommended-setup-for-this-install) above. The
only Docker-specific note: the redirect URI must match `APP_URL`
exactly, including scheme and port (or lack thereof). Mixing
`http://localhost:8080` in the `.env` with `https://snipeit.example.com`
in Authentik is the single most common failure mode.

### Operational tips

- **Backups:**
  ```bash
  docker compose exec mysql mariadb-dump -uroot -p"$DB_ROOT_PASSWORD" snipeit > backup-$(date +%F).sql
  ```
  Plus a snapshot of the `uploads` and `storage` named volumes for files.
- **Upgrading Snipe-IT:** bump `SNIPEIT_VERSION` in the Dockerfile,
  rebuild, `docker compose up -d`. Migrations run on container boot.
- **Upgrading this plugin:**
  `cd snipeit-oidc && git pull && cd .. && docker compose build && docker compose up -d`.
- **Logs:**
  - App: `docker compose logs -f snipeit`
  - Plugin-specific errors: `docker compose exec snipeit tail -f storage/logs/laravel.log`
- **Sessions** are file-based and stored on the `storage` volume — fine
  for a single Snipe-IT replica. For HA, switch to `SESSION_DRIVER=redis`
  and add a Redis service.
- **Hard reset (dev only):** `docker compose down -v` wipes all volumes,
  including the database. Never run this in production.

---

## Kubernetes deployment

Snipe-IT runs cleanly in Kubernetes from the official `snipe/snipe-it`
image. The catch: this plugin is a Composer package, so it must be
**baked into the image** — you cannot just mount config and call it a
day. The Snipe-IT login template also needs one line added so the SSO
button appears.

The flow is:

1. Build a custom image that vendors the plugin and patches the login view
2. Push it to your registry
3. Apply manifests (Secret + ConfigMap + Deployment + Service + Ingress)
4. Register the public callback URL in Authentik

### 1. Custom Dockerfile

Save next to the `snipeit-oidc/` directory:

```dockerfile
# Dockerfile
ARG SNIPEIT_VERSION=v8.5.0
FROM snipe/snipe-it:${SNIPEIT_VERSION}

# 1. Copy the plugin into the image
COPY snipeit-oidc /var/www/html/packages/snipeit-oidc

# 2. Register the path repository and install the plugin
WORKDIR /var/www/html
RUN composer config repositories.snipeit-oidc path packages/snipeit-oidc \
 && composer require bausteln/snipeit-oidc:* --no-interaction --no-scripts \
 && composer dump-autoload --optimize

# 3. Patch login.blade.php to include the SSO button.
#    The anchor is the closing </form> of the login form — stable across
#    recent Snipe-IT minor versions. If you upgrade to a major Snipe-IT
#    release, re-verify this line still matches before deploying.
RUN sed -i '0,/<\/form>/{s|</form>|</form>\n        @include('"'"'oidc::login-button'"'"')|}' \
        resources/views/auth/login.blade.php \
 && grep -q "oidc::login-button" resources/views/auth/login.blade.php \
        || (echo "ERROR: login.blade.php patch did not apply" && exit 1)

# 4. Fix permissions (Snipe-IT image runs as www-data)
RUN chown -R www-data:www-data packages/snipeit-oidc
```

Build and push:

```bash
docker build -t registry.example.com/snipeit-oidc:v8.5.0-oidc1 .
docker push   registry.example.com/snipeit-oidc:v8.5.0-oidc1
```

> Pin the Snipe-IT version. Don't use `:latest` — auth code is exactly
> the kind of thing where a silent upstream change to `User`/`Group`
> models would break login at the worst time.

### 2. Kubernetes manifests

These assume:
- Namespace: `snipeit`
- Existing MySQL/MariaDB reachable at `mysql.snipeit.svc.cluster.local`
- cert-manager + an `Issuer` named `letsencrypt-prod` (adjust if you don't use it)

```yaml
# secret-oidc.yaml — sensitive values, kubectl create or sealed-secrets
apiVersion: v1
kind: Secret
metadata:
  name: snipeit-oidc
  namespace: snipeit
type: Opaque
stringData:
  OIDC_CLIENT_SECRET: "REPLACE_ME_FROM_AUTHENTIK"
  # Snipe-IT itself needs these too — keep them in this Secret or a separate one
  APP_KEY:            "base64:REPLACE_ME_php_artisan_key_generate"
  DB_PASSWORD:        "REPLACE_ME"
```

```yaml
# configmap-snipeit.yaml — non-sensitive config
apiVersion: v1
kind: ConfigMap
metadata:
  name: snipeit-env
  namespace: snipeit
data:
  APP_URL:              "https://snipeit.example.com"
  APP_ENV:              "production"
  APP_DEBUG:            "false"
  DB_CONNECTION:        "mysql"
  DB_HOST:              "mysql.snipeit.svc.cluster.local"
  DB_DATABASE:          "snipeit"
  DB_USERNAME:          "snipeit"
  # ---- OIDC plugin ----
  OIDC_ENABLED:         "true"
  OIDC_PROVIDER_URL:    "https://authentik.example.com/application/o/snipeit/"
  OIDC_CLIENT_ID:       "snipeit"
  OIDC_SCOPES:          "openid profile email groups"
  OIDC_PROVISIONING:    "jit"
  OIDC_ADMIN_GROUPS:    "snipeit-admins"
```

```yaml
# deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: snipeit
  namespace: snipeit
spec:
  replicas: 1                       # raise once you've set up sticky sessions or shared session store
  strategy:
    type: Recreate                  # avoids two pods racing on DB migrations
  selector:
    matchLabels: { app: snipeit }
  template:
    metadata:
      labels: { app: snipeit }
    spec:
      containers:
        - name: snipeit
          image: registry.example.com/snipeit-oidc:v8.5.0-oidc1
          ports:
            - containerPort: 80
          envFrom:
            - configMapRef: { name: snipeit-env }
            - secretRef:    { name: snipeit-oidc }
          volumeMounts:
            - { name: uploads, mountPath: /var/www/html/public/uploads }
            - { name: storage, mountPath: /var/www/html/storage }
          readinessProbe:
            httpGet: { path: /login, port: 80 }
            initialDelaySeconds: 30
            periodSeconds: 10
          resources:
            requests: { cpu: "100m", memory: "256Mi" }
            limits:   { cpu: "1",    memory: "1Gi"   }
      volumes:
        - name: uploads
          persistentVolumeClaim: { claimName: snipeit-uploads }
        - name: storage
          persistentVolumeClaim: { claimName: snipeit-storage }
```

```yaml
# pvcs.yaml
apiVersion: v1
kind: PersistentVolumeClaim
metadata: { name: snipeit-uploads, namespace: snipeit }
spec:
  accessModes: [ReadWriteOnce]
  resources: { requests: { storage: 10Gi } }
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata: { name: snipeit-storage, namespace: snipeit }
spec:
  accessModes: [ReadWriteOnce]
  resources: { requests: { storage: 5Gi } }
```

```yaml
# service.yaml
apiVersion: v1
kind: Service
metadata: { name: snipeit, namespace: snipeit }
spec:
  selector: { app: snipeit }
  ports:
    - { port: 80, targetPort: 80 }
```

```yaml
# ingress.yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: snipeit
  namespace: snipeit
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
    nginx.ingress.kubernetes.io/proxy-body-size: "50m"   # asset image uploads
spec:
  ingressClassName: nginx
  tls:
    - hosts: [snipeit.example.com]
      secretName: snipeit-tls
  rules:
    - host: snipeit.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service: { name: snipeit, port: { number: 80 } }
```

Apply:

```bash
kubectl create namespace snipeit
kubectl apply -f secret-oidc.yaml -f configmap-snipeit.yaml \
              -f pvcs.yaml -f deployment.yaml \
              -f service.yaml -f ingress.yaml
```

### 3. Authentik registration

Set the redirect URI in your Authentik provider to the **public** URL,
not the in-cluster one:

```
https://snipeit.example.com/oidc/callback
```

`OIDC_PROVIDER_URL` can also use the public URL — the browser does the
discovery fetch, not the pod. If you really want pod → Authentik traffic
to stay inside the cluster, point `OIDC_PROVIDER_URL` at the in-cluster
Service (e.g. `http://authentik-server.authentik.svc.cluster.local/application/o/snipeit/`)
**and** make sure the issuer claim returned in tokens still matches what
you configured — mismatches there cause JWT validation failures.

### 4. Operational tips for K8s

- **Sessions:** Snipe-IT uses file-based sessions by default. With
  `replicas > 1` you need either sticky sessions on the Ingress
  (`nginx.ingress.kubernetes.io/affinity: cookie`) or — better —
  `SESSION_DRIVER=redis` with a Redis sidecar/Service.
- **Migrations:** the Snipe-IT image runs `php artisan migrate` on
  startup. The `Recreate` strategy above prevents two pods from racing
  on this during a rollout.
- **Re-rolling on plugin changes:** after editing files inside
  `snipeit-oidc/`, rebuild the image with a new tag and bump the
  Deployment's `image:` value. Avoid `imagePullPolicy: Always` + mutable
  tags — you lose the ability to roll back cleanly.
- **Logs:** plugin errors land in `storage/logs/laravel.log` inside the
  container, which is on the `snipeit-storage` PVC. `kubectl exec` into
  the pod and `tail -f` that file while debugging, or ship logs via a
  sidecar.

### Helm chart alternative

If you already use a Snipe-IT Helm chart (e.g. community charts like
`grokzen/snipe-it`), the same approach applies: override the chart's
`image.repository` and `image.tag` with your custom build, and merge the
`OIDC_*` values into the chart's `extraEnv` (or equivalent) and
`existingSecret` parameters. The chart handles the Deployment/Service/
Ingress; you only need the custom image and the values overrides.

---

## Operating notes

- **Local logins still work.** This plugin is additive. Keep at least one
  local admin account in case the IdP becomes unreachable.
- **Token validation** is handled by `jumbojett/openid-connect-php`, which
  uses the JWKS endpoint from your IdP's discovery document.
- **Sessions** are Snipe-IT's normal Laravel sessions. The OIDC tokens
  are not stored — we exchange them for a Snipe-IT login once and discard.
- **Upgrades:** Snipe-IT changes its `User` model rarely, but check
  `OidcUserResolver` after major Snipe-IT releases. The plugin is audited
  against **Snipe-IT v8.5.0** (Laravel 12, PHP 8.2+) as of the v0.2.0 tag.
- **Upstream moved:** as of Snipe-IT v8.1.0, the GitHub repo is at
  `grokability/snipe-it` — the old `snipe/snipe-it` is a redirect that
  occasionally breaks. The Docker Hub image namespace stayed `snipe/snipe-it`,
  so the Dockerfile examples in this README don't need updating, but
  bare-install users should `git clone` from `grokability/snipe-it`.

---

## Alternative approaches (if this plugin is overkill)

1. **Reverse proxy + REMOTE_USER** — put `oauth2-proxy` or Caddy/Traefik
   in front of Snipe-IT and enable `REMOTE_USER` in Snipe-IT settings.
   Zero code, but no fine-grained attribute mapping.

2. **SAML bridge** — point Snipe-IT's SAML at Keycloak/Authentik, which
   in turn federates with your OIDC IdP. Zero code, uses supported
   Snipe-IT auth.

Use this plugin when you want first-class OIDC inside Snipe-IT itself —
proper claim mapping, JIT provisioning, and group sync.

---

## License

MIT
