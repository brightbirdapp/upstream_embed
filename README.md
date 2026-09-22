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
fetches the content from `http://app-backend/page.php` inside Docker.

After changing either value, clear Drupal's cache:

```sh
drush cr
```

## Install

1. Copy the `upstream_embed` folder to `web/modules/custom/upstream_embed`.
2. Edit `src/ModuleSettings.php` as described above.
3. Make sure the Drupal web container and app web container share a Docker
   network. Give the app container a network alias matching `BACKEND_URL`.
4. Enable the module:

   ```sh
   drush en upstream_embed -y
   drush cr
   ```

## Docker networking example

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
