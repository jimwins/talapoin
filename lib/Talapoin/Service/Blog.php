<?php
namespace Talapoin\Service;

class Blog
{
  public function __construct(
    private Data $data
  ) {
  }

  public function getEntries($include_draft= false) {
    $entries=
      $this->data->factory('Entry')
        ->select('*')
        ->select_expr("
           (SELECT JSON_ARRAYAGG(name)
              FROM entry_to_tag, tag
             WHERE entry_id = entry.id AND tag_id = tag.id)", 'tags_json')
        ->select_expr("
           (SELECT COUNT(*)
              FROM comment
             WHERE entry_id = entry.id AND NOT tb)", 'comments');
    return $include_draft ? $entries : $entries->where_not_equal('draft', 1);
  }
}
