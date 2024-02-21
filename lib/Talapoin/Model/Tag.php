<?php

declare(strict_types=1);

namespace Talapoin\Model;

class Tag extends \Talapoin\Model
{
    public function __toString()
    {
        return $this->name;
    }
}
