<?php

declare(strict_types=1);

namespace Talapoin\Service;

class SQLiteSearch
{
    public function __construct(
        private Data $data,
        private Blog $blog,
        private PhotoLibrary $photos,
        private BookmarkLibrary $bookmarks,
    ) {
    }

    public function findEntries($q)
    {
        $query = "SELECT rowid AS id FROM fts_entry WHERE fts_entry MATCH (?)";
        $results = $this->data->fetch_all($query, [ $q ]);

        $ids = array_map(function ($e) {
            return $e['id'];
        }, $results);

        if (!$ids) {
            return [];
        }

        return
            $this->blog->getEntries()
                ->where_in('id', $ids)
                ->order_by_desc('created_at')
                ->find_many();
    }

    public function findPhotos($q)
    {
        $query = "SELECT rowid AS id FROM fts_photo WHERE fts_photo MATCH (?)";
        $results = $this->data->fetch_all($query, [ $q ]);

        $ids = array_map(function ($e) {
            return $e['id'];
        }, $results);

        if (!$ids) {
            return [];
        }

        return
            $this->photos->getPhotos()
                ->where_in('id', $ids)
                ->order_by_desc('created_at')
                ->find_many();
    }

    public function findBookmarks($q)
    {
        $query = "SELECT rowid AS id FROM fts_bookmark WHERE fts_bookmark MATCH (?)";
        $results = $this->data->fetch_all($query, [ $q ]);

        $ids = array_map(function ($e) {
            return $e['id'];
        }, $results);

        if (!$ids) {
            return [];
        }

        return
            $this->bookmarks->getBookmarks()
                ->where_in('id', $ids)
                ->order_by_desc('created_at')
                ->find_many();
    }
}
