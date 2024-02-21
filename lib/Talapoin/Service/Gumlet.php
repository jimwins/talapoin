<?php

declare(strict_types=1);

namespace Talapoin\Service;

use Thumbhash\Thumbhash;

use function Thumbhash\extract_size_and_pixels_with_gd;

class Gumlet
{
    private $config;

    public function __construct(Config $config)
    {
        $this->config = @$config['gumlet'];
    }

    public function getSignedUrl($path, $options = [])
    {
        $url = $path . ($options ? '?' . http_build_query($options) : '');

        $signature = md5($this->config['secret'] . $url);

        return
            $this->config['base_url'] .
            $url .
            ($options ? '&' : '?') .
            's=' . $signature;
    }

    public function getImageDetails($path)
    {
        $url = $this->getSignedUrl($path, [ 'format' => 'json' ]);
        $body = file_get_contents($url);
        return json_decode($body, flags: \JSON_THROW_ON_ERROR);
    }

    public function getThumbHash($path)
    {
        $url = $this->getSignedUrl($path, [ 'w' => 100, 'h' => 100, 'mode' => 'fit', 'fm' => 'jpeg' ]);
        $body = file_get_contents($url);
        error_log("got $url");
        list($width, $height, $pixels) = extract_size_and_pixels_with_gd($body);
        return Thumbhash::convertHashToString(Thumbhash::RGBAToHash($width, $height, $pixels));
    }
}
