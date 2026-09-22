<?php

namespace Drupal\upstream_embed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\upstream_embed\ModuleSettings;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Embeds an upstream HTTP app in Drupal using shadow DOM (no iframe).
 *
 * Edit src/ModuleSettings.php to set the backend URL and public Drupal path.
 */
final class EmbedController extends ControllerBase {

  public function page(Request $request, ?string $name = NULL, ?string $dir = NULL, ?string $file = NULL): array {
    return $this->embedBackend($request, $this->backendPath($name, $dir, $file));
  }

  public function itemTitle(string $name): string {
    return $this->humanize($name);
  }

  public function nestedTitle(string $file): string {
    return $this->humanize($file);
  }

  private function backendPath(?string $name, ?string $dir, ?string $file): string {
    if (is_string($dir) && $dir !== '' && is_string($file) && $file !== '') {
      return $this->phpPath($dir . '/' . $file);
    }
    if (is_string($name) && $name !== '') {
      return $this->phpPath($name);
    }
    return 'index.php';
  }

  private function phpPath(string $path): string {
    $path = ltrim($path, '/');
    if ($path === '' || $path === 'index.php') {
      return 'index.php';
    }
    if (str_contains(basename($path), '.')) {
      return $path;
    }
    return $path . '.php';
  }

  private function embedBackend(Request $request, string $backendPath): array {
    \Drupal::service('page_cache_kill_switch')->trigger();
    $safe = $this->safePath($backendPath);
    if ($this->isStandaloneOnly($safe)) {
      throw new NotFoundHttpException();
    }
    $backend = $this->backendBase();
    $target = $backend . '/' . $safe;

    $headers = [];
    $contentType = $request->headers->get('Content-Type');
    if (is_string($contentType) && $contentType !== '') {
      $headers['Content-Type'] = $contentType;
    }

    $options = [
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
      'connect_timeout' => 2,
      'timeout' => 30,
      'query' => $request->query->all(),
      'headers' => $headers,
    ];
    $method = strtoupper($request->getMethod());
    if (in_array($method, ['POST', 'PUT', 'PATCH'], TRUE)) {
      $options['body'] = $request->getContent();
    }

    try {
      $upstream = \Drupal::httpClient()->request($method, $target, $options);
    }
    catch (GuzzleException $exception) {
      $this->getLogger('upstream_embed')->warning('Upstream fetch failed: @message', ['@message' => $exception->getMessage()]);
      return $this->stageMarkup('<p>The upstream app is temporarily unavailable.</p>', '');
    }

    $status = $upstream->getStatusCode();
    if ($status >= 500) {
      return $this->stageMarkup('<p>The upstream app is temporarily unavailable.</p>', '');
    }
    if ($status === 404) {
      return $this->stageMarkup('<p>That upstream page was not found.</p>', '');
    }

    $raw = (string) $upstream->getBody();
    $type = $upstream->getHeaderLine('Content-Type');
    $css = '';
    if (stripos($type, 'text/html') === FALSE) {
      $raw = '<pre>' . htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
    else {
      $parts = $this->extractParts($raw);
      $css = $parts['css'];
      $raw = $this->rewriteUrls($parts['body'], $safe) . $parts['scripts'];
    }

    return $this->stageMarkup($raw, $css);
  }

  private function stageMarkup(string $html, string $css): array {
    $reset = ':host{display:block;min-height:75vh;background:#fff;color:#1a1a1a;font-family:sans-serif;}a{color:#003da5;}';
    $inner = '<style>' . $reset . $css . '</style>' . $html;
    $stage = '<div class="upstream-embed-host"><template class="upstream-embed-src">' . $inner . '</template></div>';
    return [
      '#attached' => ['library' => ['upstream_embed/stage']],
      '#markup' => Markup::create($stage),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * @return array{css: string, body: string, scripts: string}
   */
  private function extractParts(string $html): array {
    if (stripos($html, '<body') === FALSE && stripos($html, '<style') === FALSE) {
      return ['css' => '', 'body' => $html, 'scripts' => ''];
    }
    $document = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $css = '';
    foreach ($document->getElementsByTagName('style') as $style) {
      $css .= $style->textContent;
    }
    $body = $document->getElementsByTagName('body')->item(0);
    if (!$body instanceof \DOMElement) {
      return ['css' => $css, 'body' => $html, 'scripts' => ''];
    }
    $inner = '';
    $scripts = '';
    foreach ($body->childNodes as $child) {
      if ($child instanceof \DOMElement && strtolower($child->tagName) === 'style') {
        continue;
      }
      if ($child instanceof \DOMElement && strtolower($child->tagName) === 'script') {
        $scripts .= $document->saveHTML($child);
        continue;
      }
      $inner .= $document->saveHTML($child);
    }
    return ['css' => $css, 'body' => $inner, 'scripts' => $scripts];
  }

  private function rewriteUrls(string $html, string $backendPath): string {
    $document = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="upstream-embed-root">' . $html . '</div>');
    libxml_clear_errors();
    $xpath = new \DOMXPath($document);
    foreach (['//a[@href]', '//form[@action]', '//img[@src]', '//link[@href]', '//script[@src]'] as $query) {
      foreach ($xpath->query($query) ?: [] as $node) {
        if (!$node instanceof \DOMElement) {
          continue;
        }
        $attribute = $node->hasAttribute('href') ? 'href' : 'action';
        if ($node->hasAttribute('src')) {
          $attribute = 'src';
        }
        $node->setAttribute($attribute, $this->mapUrl($node->getAttribute($attribute), $backendPath));
        if ($node->tagName === 'a' && $node->getAttribute('target') === '_top') {
          $node->removeAttribute('target');
        }
      }
    }
    $root = $document->getElementById('upstream-embed-root');
    if (!$root instanceof \DOMElement) {
      return $html;
    }
    $inner = '';
    foreach ($root->childNodes as $child) {
      $inner .= $document->saveHTML($child);
    }
    return $inner;
  }

  private function mapUrl(string $url, string $backendPath): string {
    $trimmed = trim($url);
    if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, 'mailto:')) {
      return $url;
    }
    if (preg_match('#^(https?:)?//#i', $trimmed) === 1) {
      return $url;
    }
    $parts = parse_url($trimmed);
    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    if ($path !== '' && !str_starts_with($path, '/')) {
      $dir = dirname($backendPath);
      if ($dir === '.' || $dir === '/') {
        $dir = '';
      }
      $path = $this->normalizePath(($dir === '' ? '' : $dir . '/') . $path);
    }
    else {
      $path = $this->normalizePath(ltrim($path, '/'));
    }
    return $this->drupalPathFor($path) . $query . $fragment;
  }

  private function drupalPathFor(string $upstreamPath): string {
    $prefix = $this->publicPrefix();
    $path = ltrim($upstreamPath, '/');
    $prefixTrim = ltrim($prefix, '/');
    if ($path === $prefixTrim || str_starts_with($path, $prefixTrim . '/')) {
      return '/' . $path;
    }
    if ($path === '' || $path === 'index.php') {
      return $prefix;
    }
    if (str_ends_with($path, '.php')) {
      $path = substr($path, 0, -4);
    }
    return $prefix . '/' . $path;
  }

  private function backendBase(): string {
    return ModuleSettings::backendUrl();
  }

  private function publicPrefix(): string {
    return ModuleSettings::publicPrefix();
  }

  private function humanize(string $value): string {
    $value = preg_replace('/\.php$/', '', $value) ?? $value;
    return ucwords(str_replace(['-', '_'], ' ', $value));
  }

  private function normalizePath(string $path): string {
    $path = str_replace('\\', '/', $path);
    $segments = [];
    foreach (explode('/', $path) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        array_pop($segments);
        continue;
      }
      $segments[] = $segment;
    }
    return implode('/', $segments);
  }

  private function isStandaloneOnly(string $path): bool {
    $path = strtolower($path);
    $first = explode('/', $path, 2)[0];
    if ($first === 'admin' || str_starts_with($first, 'admin.')) {
      return TRUE;
    }
    return str_starts_with(basename($path), '_');
  }

  private function safePath(string $path): string {
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '://')) {
      throw new BadRequestHttpException('Invalid upstream path.');
    }
    return $path;
  }

}
