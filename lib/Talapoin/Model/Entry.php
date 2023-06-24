<?php
namespace Talapoin\Model;

class Entry extends \Talapoin\Model {

  public function slug() {
    if ($this->title) {
      return preg_replace('/[^-A-Za-z0-9,]/u', '_', $this->title);
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


  public function tags($new_tags= null) {
    if ($new_tags) {

      if (!$this->id) {
        $this->save();
      }

      if (!is_array($new_tags)) {
        $new_tags= preg_split('/, */', $new_tags);
      }

      $this->has_many('EntryTag')->delete_many();

      foreach ($new_tags as $tag_name) {
        $tag_name= trim($tag_name);

        $tag= $this->factory('Tag')->where('name', $tag_name)->find_one();
        if (!$tag) {
          $tag= $this->factory('Tag')->create();
          $tag->name= $tag_name;
          $tag->save();
        }

        $assoc= $this->factory('EntryTag')->create();
        $assoc->entry_id= $this->id;
        $assoc->tag_id= $tag->id;
        $assoc->save();
      }
    }

    if ($this->tags_json) {
      return json_decode($this->tags_json);
    }

    return $this->has_many_through('Tag')->find_many();
  }

  public function comments() {
    return $this->has_many('Comment');
  }
}

class EntryTag extends \Talapoin\Model {
  public static $_table= 'entry_to_tag';
}

class Tag extends \Talapoin\Model {
  public function __toString() {
    return $this->name;
  }
}
