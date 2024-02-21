<?php

declare(strict_types=1);

namespace Talapoin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;

class Auth implements MiddlewareInterface
{
    public function __construct(
        private \Talapoin\Service\Config $config
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $expected = $this->config['auth']['token'];

        $cookies = $request->getCookieParams();
        if (isset($cookies['token'])) {
            $token = $cookies['token'];

            if ($token == $expected) {
                return $handler->handle($request);
            }
        }

        throw new HttpUnauthorizedException($request);
    }
}
