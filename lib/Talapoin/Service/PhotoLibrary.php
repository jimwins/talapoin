<?php

namespace Talapoin\Service;

class PhotoLibrary {
  public function __construct(
    private Gumlet $gumlet,
    private Data $data,
  ) {
  }

  public function findPhotos($q, $page, $page_size) {
    /* TODO use $q */

    $photos= $this->data
      ->factory('Photo')
      ->select('*')
      ->select_expr('COUNT(*) OVER()', 'records')
      ->where('privacy', 'public')
      ->order_by_desc('taken_at')
      ->limit($page_size)->offset($page * $page_size)
      ->find_many();

    return $photos;
  }

  public function createPhoto() {
    return $this->data->factory('Photo')->create();
  }

  public function getPhotoByUlid($ulid) {
    return $this->data
      ->factory('Photo')
      ->where('privacy', 'public')
      ->where('ulid', $ulid)
      ->find_one();
  }
}
