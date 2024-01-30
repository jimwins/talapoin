<?php
namespace Talapoin\Model;

trait HasTags {
  public function tags($new_tags= null) {
    if ($new_tags) {

      if (!$this->id) {
        $this->save();
      }

      if (!is_array($new_tags)) {
        $new_tags= preg_split('/, */', $new_tags);
      }

      $this->has_many($this->tags_model)->delete_many();

      foreach ($new_tags as $tag_name) {
        $tag_name= trim($tag_name);

        $tag= $this->factory('Tag')->where('name', $tag_name)->find_one();
        if (!$tag) {
          $tag= $this->factory('Tag')->create();
          $tag->name= $tag_name;
          $tag->save();
        }

        $assoc= $this->factory($tags_model)->create();
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
}
