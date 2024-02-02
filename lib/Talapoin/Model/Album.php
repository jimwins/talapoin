<?php
namespace Talapoin\Model;

class Album extends \Talapoin\Model {
  public function cover_photo($id = null) {
    if ($id) {
      // TODO implement this
      throw new \Exception("Can't change cover photo yet.");
    }

    if ($this->cover_photo_id) {
      return $this->belongs_to('Photo', 'cover_photo_id')->find_one();
    } else {
      return $this->has_many_through('Photo', 'PhotoAlbum')->order_by_desc('taken_at')->find_one();
    }
  }
}
