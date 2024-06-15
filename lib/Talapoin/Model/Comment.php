<?php

declare(strict_types=1);

namespace Talapoin\Model;

class Comment extends \Talapoin\Model
{
    /* For the URL to refer to something external-ish, even if that breaks. */
    public function externalUrl() {
        if preg_match('/^(ftp|gopher|https?|mailto):/i', $this->url) {
            return $this->url;
        }
        return 'https://' . $this->url;
    }

    public function entry()
    {
        return $this->belongs_to('Entry')->find_one();
    }
}
