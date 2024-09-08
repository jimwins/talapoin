<?php

declare(strict_types=1);

namespace Talapoin\Service;

abstract class Library
{
    public function __construct(
        protected Data $data,
    ) {
    }

    abstract protected function getModelName(): string;

    public function create()
    {
        return $this->data->factory($this->getModelName())->create();
    }

    public function fetchById(string $id)
    {
        return $this->data
            ->factory($this->getModelName())
            ->where('id', $id)
            ->find_one();
    }
}
