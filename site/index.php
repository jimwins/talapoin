<?php

declare(strict_types=1);

require '../vendor/autoload.php';

$DEBUG = getenv('TALAPOIN_DEBUG');

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response as Response;
use Slim\Views\Twig as View;
use Respect\Validation\Validator as v;
use Slim\Routing\RouteCollectorProxy as RouteCollectorProxy;

/* Some defaults */
error_reporting(E_ALL ^ E_DEPRECATED);
$tz = getenv('PHP_TIMEZONE') ?: getenv('TZ');
if ($tz) {
    date_default_timezone_set($tz);
}

$config_file = getenv('TALAPOIN_CONFIG') ?: dirname(__FILE__) . '/../config.ini';

if (file_exists($config_file)) {
    $config = parse_ini_file($config_file, true, INI_SCANNER_TYPED);
} else {
    die("Unable to find config");
}

$builder = new \DI\ContainerBuilder();
$builder->addDefinitions([
    'Slim\Views\Twig' => \DI\get('view'),
    'Talapoin\Service\Data' => \DI\get('data'),
    'Talapoin\Service\Config' => \DI\get('config'),
]);
$container = $builder->build();
$container->set('config', new \Talapoin\Service\Config($config));

/* Hook up the data service, but not lazily because we rely on side-effects */
$container->set('data', new \Talapoin\Service\Data($config));

$app = \DI\Bridge\Slim\Bridge::create($container);

$app->addRoutingMiddleware();

/* PDO */
$container->set('db', function ($c) {
    $db = $c->get('config')['db'];
    $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'];
    $pdo = new PDO($dsn . ';charset=utf8mb4', $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
});

/* Twig for templating */
$container->set('view', function ($container) use($tz) {
    /* No cache for now */
    $view = \Slim\Views\Twig::create(
        [ '../ui' ],
        [ 'cache' => false ]
    );

    /* Set timezone for date functions */
    if ($tz) {
        $view->getEnvironment()
            ->getExtension(\Twig\Extension\CoreExtension::class)
            ->setTimezone($tz);
    }

    // Add the HTML extension
    $view->addExtension(new \Twig\Extra\Html\HtmlExtension());

    // Add StringLoader extension
    $view->addExtension(new \Twig\Extension\StringLoaderExtension());

    // Add Markdown extension
    $view->addExtension(new \Twig\Extra\Markdown\MarkdownExtension());

    // Add Markdown runtime, too
    $view->addRuntimeLoader(new class implements \Twig\RuntimeLoader\RuntimeLoaderInterface {
        public function load($class) {
            if (\Twig\Extra\Markdown\MarkdownRuntime::class === $class) {
                return new \Twig\Extra\Markdown\MarkdownRuntime(
                    new \Twig\Extra\Markdown\DefaultMarkdown()
                );
            }
            return null;
        }
    });

    return $view;
});

/* Add filters for blog entries */
$filter = new \Twig\TwigFilter('expand_psuedo_urls', function ($text) {
    $text = preg_replace(
        '/isbn:([0-9x]+)/i',
        'https://bookshop.org/a/94608/$1',
        $text
    );
    $text = preg_replace(
        '/asin:(\w+)/i',
        'http://www.amazon.com/exec/obidos/ASIN/$1/trainedmonkey',
        $text
    );
    return $text;
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter = new \Twig\TwigFilter('get_debug_type', function ($value) {
    return get_debug_type($value);
});
$container->get('view')->getEnvironment()->addFilter($filter);

$func = new \Twig\TwigFunction('current_release', function () {
    $path = getenv("DEPLOY_PATH");
    if ($path) {
        $link = @readlink($path . '/current');
        if ($link) {
            return basename($link);
        }
    }
    return 'dev';
});
$container->get('view')->getEnvironment()->addFunction($func);

$func = new \Twig\TwigFunction('includeFragment', function ($slug) use ($container) {
    $pages = $container->get(\Talapoin\Service\Page::class);
    return $pages->getPageBySlug($slug);
});
$container->get('view')->getEnvironment()->addFunction($func);

$app->add(\Slim\Views\TwigMiddleware::createFromContainer($app));

$app->add((new \Middlewares\TrailingSlash(false))->redirect());

$errorMiddleware = $app->addErrorMiddleware($DEBUG || @$config['displayErrorDetails'], true, true);

/* Technically this can return a callable but Slim says that has to implement
 * ErrorHandlerInterface, so we should be fine. */
/** @var \Slim\Handlers\ErrorHandler $errorHandler */
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->registerErrorRenderer(
    'text/html',
    new \Talapoin\Handler\ErrorRenderer($container->get('view'))
);

/* 404 & 410 */
$errorMiddleware->setErrorHandler(
    \Slim\Exception\HttpNotFoundException::class,
    function (Request $request, Throwable $exception, bool $displayErrorDetails) use ($container) {
        $response = new \Slim\Psr7\Response();
        return $container->get('view')->render($response->withStatus(404), '404.html');
    }
);
$errorMiddleware->setErrorHandler(
    \Slim\Exception\HttpGoneException::class,
    function (Request $request, Throwable $exception, bool $displayErrorDetails) use ($container) {
        $response = new \Slim\Psr7\Response();
        return $container->get('view')->render($response->withStatus(410), '404.html');
    }
);

/* ROUTES */

/* A single entry */
$app->get(
    '/{year:[0-9]+}/{month:[0-9]+}/{day:[0-9]+}/{slug}',
    [ \Talapoin\Controller\Blog::class, 'entry' ]
)->setName('entry');

$app->post('/entry/{id:[0-9]+}/comment', [ \Talapoin\Controller\Blog::class, 'addComment' ])
    ->setName('add-comment');

/* Year archive */
$app->get('/{year:[0-9]+}', [ \Talapoin\Controller\Blog::class, 'year' ])
    ->setName('year');

$app->get('/{year:[0-9]+}/{month:[0-9]+}', [ \Talapoin\Controller\Blog::class, 'month' ])
    ->setName('month');

/* Day archive */
$app->get('/{year:[0-9]+}/{month:[0-9]+}/{day:[0-9]+}', [ \Talapoin\Controller\Blog::class, 'day' ])
    ->setName('day');

$app->get('/', [ \Talapoin\Controller\Blog::class, 'top' ])
    ->setName('top');

$app->get('/archive', [ \Talapoin\Controller\Blog::class, 'archive' ])
    ->setName('archive');

$app->get('/tag/{tag}', [ \Talapoin\Controller\Blog::class, 'tag' ])
    ->setName('blog.tag');

$app->get('/search', [ \Talapoin\Controller\Blog::class, 'search' ])
    ->setName('search');

$app->get('/scratch[/{path:.*}]', function (Request $request, Response $response, $path) {
    $config = $GLOBALS['container']->get('config');
    return $response->withRedirect($config['static'] . '/' . $path);
});

/* Atom feeds */
$app->get('/index.atom', [ \Talapoin\Controller\Blog::class, 'atomFeed' ])
    ->setName('blog.atom');

$app->get('/tag/{tag}/index.atom', [ \Talapoin\Controller\Blog::class, 'atomFeed' ])
    ->setName('tag_atom');

$app->get('/{tag}/index.atom', function (Request $request, Response $response, $tag) {
    $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
    $routeParser = $routeContext->getRouteParser();
    return $response->withRedirect(
        $routeParser->fullUrlFor($request->getUri(), 'tag_atom', [ 'tag' => $tag ]),
        301 /* Permanently */
    );
});

/* Handle /entry/123 as redirect to blog entry (tmky.us goes through GLOBALS['container']) */
$app->get('/entry/{id:[0-9]+}', [ \Talapoin\Controller\Blog::class, 'entryRedirect' ]);

$app->get('/entry', function (Request $request, Response $response) {
    return $response->withRedirect('/');
});

/* Photos */
$app->group('/photo', function (RouteCollectorProxy $app) {
    \Talapoin\Controller\PhotoLibrary::registerRoutes($app);
});

/* Behind the scenes stuff */
$app->get('/~reindex', [ \Talapoin\Controller\Blog::class, 'reindex' ])
    ->setName('reindex');

$app->post('/~webmention', [ \Talapoin\Controller\Blog::class, 'handleWebmention' ])
    ->setName('webmention');

/* Admin */
$app->post('/login', [ \Talapoin\Controller\Admin::class, 'login' ])
    ->setName('login');
$app->group('/~admin', function (RouteCollectorProxy $app) {
    \Talapoin\Controller\Admin::registerRoutes($app);
})->add($container->get(\Talapoin\Middleware\Auth::class));

/* DEBUG only */
if ($DEBUG) {
    $app->get(
        '/info',
        function (Request $request, Response $response) {
            ob_start();
            phpinfo();
            $response->getBody()->write(ob_get_clean());
            return $response;
        }
    )->setName('info');

    $app->get(
        '/info/db',
        function (Request $request, Response $response) use ($container) {
            $db = $container->get('db');
            $stmt = $db->prepare("SHOW VARIABLES");
            $stmt->execute();
            $vars = $stmt->fetchAll();
            return $container->get('view')->render($response, 'info-db.html', [ 'vars' => $vars ]);
        }
    );

    $app->get(
        '/info/xdebug',
        function (Request $request, Response $response) {
            ob_start();
            xdebug_info();
            $response->getBody()->write(ob_get_clean());
            return $response;
        }
    );
}

$app->get('/news/rss.php', function (Request $request, Response $response) {
    throw new \Slim\Exception\HttpGoneException($request);
});

/* Default for everything else (pages, redirects) */
$app->get('/{path:.*}', [ \Talapoin\Controller\Page::class, 'showPage' ])->setName('page');

$app->run();
