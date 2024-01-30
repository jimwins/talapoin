<?php
namespace Talapoin\Model;

class Entry extends \Talapoin\Model {
  protected $tags_model= 'EntryTag';
  use HasTags;

  public function slug() {
    if ($this->title) {
      return preg_replace('/[^-A-Za-z0-9,]/u', '_', strtolower($this->title));
    } else {
      return $this->id;
    }
  }

  public function canonicalUrl() {
    return sprintf('/%s/%s', (new \DateTime($this->created_at))->format("Y/m/d"), $this->slug());
  }

  public function fullCanonicalUrl($request) {
    $routeContext= \Slim\Routing\RouteContext::fromRequest($request);
    $routeParser= $routeContext->getRouteParser();

    $uri= $request->getUri();
    $date= new \DateTimeImmutable($this->created_at);

    return $routeParser->fullUrlFor($uri, 'entry', [
      'year' => $date->format('Y'),
      'month' => $date->format('m'),
      'day' => $date->format('d'),
      'id' => $this->slug()
    ]);
  }

  public function comments() {
    return $this->has_many('Comment');
  }
}

class EntryTag extends \Talapoin\Model {
  public static $_table= 'entry_to_tag';
}
