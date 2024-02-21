<?php

declare(strict_types=1);

namespace Talapoin\Model;

class Comment extends \Talapoin\Model
{
    public function entry()
    {
        return $this->belongs_to('Entry')->find_one();
    }
}
