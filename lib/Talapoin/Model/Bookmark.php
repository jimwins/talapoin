<?php

declare(strict_types=1);

namespace Talapoin\Model;

class Bookmark extends \Talapoin\Model
{
    use HasTags;

    protected $tags_model = 'BookmarkTag';

    public function canonicalUrl()
    {
        return '/bookmark/' . $this->ulid;
    }
}
