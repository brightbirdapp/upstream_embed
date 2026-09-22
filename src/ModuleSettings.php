<?php

namespace Drupal\upstream_embed;

/**
 * EDIT THIS FILE to connect the module to your app and choose Drupal URLs.
 *
 * After changing either value, clear Drupal's cache:
 *   drush cr
 *
 * The two web containers must share a Docker network when BACKEND_URL uses a
 * Docker service name or alias.
 */
final class ModuleSettings {

  /**
   * Address Drupal uses INSIDE Docker to reach the separate web app.
   *
   * Examples:
   *   http://app-backend
   *   http://website-backend
   *   http://host.docker.internal:8090
   *
   * Do not use Drupal's browser URL here. Do not add a trailing slash.
   */
  private const BACKEND_URL = 'http://app-backend';

  /**
   * Public path visitors use on Drupal.
   *
   * Examples:
   *   /embed     gives /embed and /embed/page
   *   /app       gives /app and /app/page
   *   /tools     gives /tools and /tools/page
   *
   * Start with a slash. Do not add a trailing slash.
   */
  private const PUBLIC_PREFIX = '/embed';

  /**
   * Returns the internal app address without a trailing slash.
   */
  public static function backendUrl(): string {
    return rtrim(self::BACKEND_URL, '/');
  }

  /**
   * Returns a normalized public Drupal path.
   */
  public static function publicPrefix(): string {
    $prefix = trim(self::PUBLIC_PREFIX, '/');
    return $prefix === '' ? '/embed' : '/' . $prefix;
  }

}
