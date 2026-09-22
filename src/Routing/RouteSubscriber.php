<?php

namespace Drupal\upstream_embed\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\upstream_embed\ModuleSettings;
use Symfony\Component\Routing\RouteCollection;

/**
 * Applies the public path selected in ModuleSettings to every module route.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $prefix = ModuleSettings::publicPrefix();
    $paths = [
      'upstream_embed.home' => $prefix,
      'upstream_embed.item' => $prefix . '/{name}',
      'upstream_embed.nested' => $prefix . '/{dir}/{file}',
    ];

    foreach ($paths as $route_name => $path) {
      if ($route = $collection->get($route_name)) {
        $route->setPath($path);
      }
    }
  }

}
