<?php
namespace Talapoin\Service;

class Page
{
  public function __construct(
    private Data $data
  ) {
  }

  public function getPages($include_draft= false) {
    $pages=
      $this->data->factory('Page')
        ->select('*');
    return $include_draft ? $pages : $pages->where_not_equal('draft', 1);
  }

  public function getPageById($id) {
    return
      $this->getPages(true)
        ->find_one($id);
  }

  public function getPageBySlug($slug) {
    return
      $this->getPages(true)
        ->where('slug', $slug)
        ->find_one();
  }
}

