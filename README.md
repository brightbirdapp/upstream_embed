# Generic Upstream Embed for Drupal 10/11

This module fetches pages from a separate HTTP application, rewrites its links
onto Drupal paths, and displays the result inside the Drupal theme. A shadow DOM
keeps the separate app's CSS from affecting the Drupal page.

## The one file you edit

Open:

```text
upstream_embed/src/ModuleSettings.php
```

The comments in that file explain these two values:

```php
private const BACKEND_URL = 'http://app-backend';
private const PUBLIC_PREFIX = '/embed';
```

- `BACKEND_URL` is the address Drupal's container uses to reach the other app.
- `PUBLIC_PREFIX` is the path visitors use on the Drupal website.

Example:

```php
private const BACKEND_URL = 'http://app-backend';
private const PUBLIC_PREFIX = '/portal';
```

That gives URLs such as `https://your-drupal-site/portal/page` while Drupal
fetches the content from `http://app-backend/page.php`.

After changing either value, clear Drupal's cache:

```sh
drush cr
```

## Install

1. Copy the `upstream_embed` folder to `web/modules/custom/upstream_embed`.
2. Edit `src/ModuleSettings.php` as described above.
3. Make sure Drupal can reach the address in `BACKEND_URL`. This can be a
   shared Docker network, a published local port, or a private EC2 address.
4. Enable the module:

   ```sh
   drush en upstream_embed -y
   drush cr
   ```

## Choose the connection method

The module does not need to change for any of these choices. Only
`BACKEND_URL` changes.

### Same Docker host: shared network

App Compose file:

```yaml
services:
  web:
    networks:
      app_bridge:
        aliases:
          - app-backend

networks:
  app_bridge:
    external: true
    name: app_bridge
```

Drupal Compose file:

```yaml
services:
  web:
    networks:
      - default
      - app_bridge

networks:
  app_bridge:
    external: true
    name: app_bridge
```

Then use this in `ModuleSettings.php`:

```php
private const BACKEND_URL = 'http://app-backend';
```

### Local test that resembles two EC2 instances

Publish the app's port to the Docker host, for example:

```yaml
services:
  web:
    ports:
      - "127.0.0.1:8090:80"
```

Configure Drupal to use that published port:

```php
private const BACKEND_URL = 'http://host.docker.internal:8090';
```

On Docker Desktop for Mac/Windows, `host.docker.internal` works automatically.
On Ubuntu/Linux, add this to Drupal's `web` service:

```yaml
extra_hosts:
  - "host.docker.internal:host-gateway"
```

The two Compose projects do not need a shared Docker network for this method.

### Separate EC2 instances

Set `BACKEND_URL` to MyApp's private DNS name, private IP, or internal load
balancer URL:

```php
private const BACKEND_URL = 'http://myapp.internal.example.com';
```

Allow MyApp's HTTP (80) or HTTPS (443) inbound traffic only from Drupal's AWS
security group. Visitors still use Drupal URLs such as `/embed/recipes`;
Drupal fetches MyApp server-to-server and renders it in the shadow DOM. No
shared Docker network or module code change is required.

## URL mapping

With `PUBLIC_PREFIX = '/embed'`:

```text
Drupal URL                    Upstream file
/embed                        index.php
/embed/page                   page.php
/embed/contact                contact.php
/embed/section/page           section/page.php
```

The supplied routes support the home page, one path part, or two path parts.
Add another Drupal route and controller argument if deeper paths are required.

Keep upstream links and form actions relative so the module can rewrite them.
The module intentionally blocks `admin`, `admin/...`, and files whose basename
starts with `_`.
