<?php

declare(strict_types=1);

namespace Talapoin\Service;

class PhotoLibrary
{
    public function __construct(
        private Data $data,
    ) {
    }

    public function getPhotos($privacy = 'public', $page = 0, $page_size = 24)
    {
        $photos =
            $this->data->factory('Photo')
                ->select('*')
                ->select_expr('COUNT(*) OVER()', 'records')
                ->select_expr("
                     (SELECT JSON_ARRAYAGG(name)
                        FROM photo_to_tag, tag
                       WHERE photo_id = photo.id AND tag_id = tag.id)", 'tags_json')
                ->where('privacy', $privacy)
                ->order_by_desc('created_at');

        if ($page_size) {
            $photos = $photos->limit($page_size)->offset($page * $page_size);
        }

        return $photos;
    }

    public function getAlbums($privacy = 'public', $page = 0, $page_size = 24)
    {
        $albums =
            $this->data->factory('Album')
                ->select('*')
                ->select_expr('COUNT(*) OVER()', 'records')
                ->where('privacy', $privacy)
                ->limit($page_size)->offset($page * $page_size);
        return $albums;
    }

    public function getAlbum($album_name, $privacy = 'public')
    {
        return $this->getAlbums($privacy)->where('name', $album_name)->find_one();
    }

    public function createPhoto()
    {
        return $this->data->factory('Photo')->create();
    }

    public function getPhotoByUlid($ulid)
    {
        return $this->data
            ->factory('Photo')
            ->where('privacy', 'public')
            ->where('ulid', $ulid)
            ->find_one();
    }
}
