<?php

declare(strict_types=1);

namespace Talapoin\Service;

class SpamFilter
{
    private $config;

    public function __construct(Config $config)
    {
        $this->config = @$config['spam'];
    }

    public function isSpam($comment, $request)
    {
        if (!$this->config) {
            error_log("No spam config, so not checking");
            return;
        }

        $api = new \Akismet\API($this->config['akismet_key'], $this->config['akismet_blog']);

        if (@$this->config['akismet_debug']) {
            $comment['is_test'] = true;
        }

        return $api->isSpam($comment, $request);
    }
}
