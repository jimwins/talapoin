<?php

declare(strict_types=1);

namespace Talapoin\Service;

class BookmarkLibrary extends Library
{
    public function getModelName(): string
    {
        return 'Bookmark';
    }

    public function create()
    {
        $entry = parent::create();
        // XXX assumes we're on a 64-bit system
        $mt = explode(' ', microtime());
        $ts = intval($mt[1]) * 1000 + intval(round(floatval($mt[0]) * 1E3));
        $entry->ulid = \Ulid\Ulid::fromTimestamp($ts, true);
        return $entry;
    }

    public function getBookmarks($page = 0, $page_size = 24)
    {
        $bookmarks =
            $this->data->factory('Bookmark')
                ->select('*')
                ->select_expr('COUNT(*) OVER()', 'records')
                ->select_expr("
                     (SELECT JSON_GROUP_ARRAY(name)
                        FROM bookmark_to_tag, tag
                       WHERE bookmark_id = bookmark.id AND tag_id = tag.id)", 'tags_json')
                ->order_by_desc('created_at');

        if ($page_size) {
            $bookmarks = $bookmarks->limit($page_size)->offset($page * $page_size);
        }

        return $bookmarks;
    }

    public function createBookmark()
    {
        return $this->data->factory('Bookmark')->create();
    }

    public function getBookmarkByUlid($ulid)
    {
        return $this->data
            ->factory('Bookmark')
            ->where('ulid', $ulid)
            ->find_one();
    }
}
