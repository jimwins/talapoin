<?php

declare(strict_types=1);

namespace Talapoin\Model;

class Entry extends \Talapoin\Model
{
    use HasTags;

    protected $tags_model = 'EntryTag';

    public function slug()
    {
        if ($this->title) {
            return preg_replace('/[^-A-Za-z0-9,]/u', '_', strtolower($this->title));
        } else {
            return $this->id;
        }
    }

    public function routeComponents()
    {
        $created_at = new \DateTime($this->created_at);
        return [
            'year' => $created_at->format('Y'),
            'month' => $created_at->format('m'),
            'day' => $created_at->format('d'),
            'slug' => $this->slug(),
        ];
    }

    public function canonicalUrl()
    {
        return sprintf(
            '/%s/%s',
            (new \DateTime($this->created_at))->format("Y/m/d"),
            $this->slug()
        );
    }

    public function fullCanonicalUrl($request)
    {
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        $uri = $request->getUri();
        $date = new \DateTimeImmutable($this->created_at);

        return $routeParser->fullUrlFor($uri, 'entry', [
            'year' => $date->format('Y'),
            'month' => $date->format('m'),
            'day' => $date->format('d'),
            'slug' => $this->slug()
        ]);
    }

    public function comments()
    {
        return $this->has_many('Comment');
    }
}
