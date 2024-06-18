<?php

namespace Talapoin;

use Twig\Error\LoaderError;
use PDO;

class PageLoader implements \Twig\Loader\LoaderInterface
{
    public function __construct(
        private \Talapoin\Service\Page $page
    ) {
    }

    public function getSourceContext(string $name): \Twig\Source
    {
        if (str_starts_with($name, '@')) {
            $page = $this->page->getPageBySlug($name);

            if ($page) {
                $timestamp = (new \Datetime($page->updated_at))->getTimestamp();
                return new \Twig\Source($page->content, $name, $timestamp);
            }
        }

        throw new LoaderError(sprintf('Template "%s" not found.', $name));
    }

    public function exists(string $name): bool
    {
        if (str_starts_with($name, '@')) {
            $page = $this->page->getPageBySlug($name);

            if ($page) {
                return true;
            }
        }

        return false;
    }

    public function isFresh(string $name, int $time): bool
    {
        if (str_starts_with($name, '@')) {
            $page = $this->page->getPageBySlug($name);

            if ($page) {
                $timestamp = (new \Datetime($page->updated_at))->getTimestamp();
                return $time <= $timestamp;
            }
        }

        throw new LoaderError(sprintf('Template "%s" not found.', $name));
    }

    public function getCacheKey(string $name): string
    {
        if (str_starts_with($name, '@')) {
            $page = $this->page->getPageBySlug($name);

            if ($page) {
                $timestamp = (new \Datetime($page->updated_at))->getTimestamp();
                return $name . ($timestamp ? '?' . $timestamp : '');
            }
        }

        throw new LoaderError(sprintf('Template "%s" not found.', $name));
    }
}
