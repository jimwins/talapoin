<?php
require '../vendor/autoload.php';

$DEBUG= false;
$ORM_DEBUG= true;

use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;
use \Respect\Validation\Validator as v;
use \Slim\Routing\RouteCollectorProxy as RouteCollectorProxy;

/* Some defaults */
error_reporting(E_ALL ^ E_DEPRECATED);
$tz= @$_ENV['PHP_TIMEZONE'] ?: @$_ENV['TZ'];
if ($tz) date_default_timezone_set($tz);

$config_file= @$_ENV['TALAPOIN_CONFIG'] ?: dirname(__FILE__).'/../config.ini';

if (file_exists($config_file)) {
  $config= parse_ini_file($config_file, TRUE, INI_SCANNER_TYPED);
} else {
  die("Unable to find config");
}

$builder= new \DI\ContainerBuilder();
$builder->addDefinitions([
  'Slim\Views\Twig' => \DI\get('view'),
  'Talapoin\Service\Data' => \DI\get('data'),
  'Talapoin\Service\Config' => \DI\get('config'),
]);
$container= $builder->build();
$container->set('config', new \Talapoin\Service\Config($config));

/* Hook up the data service, but not lazily because we rely on side-effects */
$container->set('data', new \Talapoin\Service\Data($config));

$app= \DI\Bridge\Slim\Bridge::create($container);

$app->addRoutingMiddleware();

/* PDO */
$container->set('db', function ($c) {
  $db= $c->get('config')['db'];
  $dsn= 'mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'];
  $pdo= new PDO($dsn. ';charset=utf8mb4', $db['user'], $db['pass']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return $pdo;
});

/* Twig for templating */
$container->set('view', function($container) {
  /* No cache for now */
  $view= \Slim\Views\Twig::create(
    [ '../ui' ],
    [ 'cache' => false ]
  );

  /* Set timezone for date functions */
  $tz= @$_ENV['PHP_TIMEZONE'] ?: @$_ENV['TZ'];
  if ($tz) {
    $view->getEnvironment()
      ->getExtension(\Twig\Extension\CoreExtension::class)
      ->setTimezone($tz);
  }

  // Add the HTML extension
  $view->addExtension(new \Twig\Extra\Html\HtmlExtension());

  return $view;
});

/* Add filters for blog entries */
$filter= new \Twig\TwigFilter('expand_psuedo_urls', function ($text) {
  $text= preg_replace('/isbn:([0-9x]+)/i',
                      'https://bookshop.org/a/94608/$1',
                      $text);
  $text= preg_replace('/asin:(\w+)/i',
                      'http://www.amazon.com/exec/obidos/ASIN/$1/trainedmonkey',
                      $text);
  return $text;
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new \Twig\TwigFilter('paragraphs', function ($text) {
  return preg_replace('!\r?\n\r?\n!', '</p><p>', $text);
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new \Twig\TwigFilter('prettify_markup', function ($text) {
  $text= preg_replace('!<q>!', '&ldquo;', $text);
  $text= preg_replace('!</q>!', '&rdquo;', $text);
  return $text;
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new \Twig\TwigFilter('slug', function ($text) {
  return preg_replace('/[^-A-Za-z0-9,]/u', '_', $text);
});
$container->get('view')->getEnvironment()->addFilter($filter);

$func= new \Twig\TwigFunction('current_release', function() {
  $link= @readlink('/app/current');
  if ($link) {
    return basename($link);
  }
  return 'dev';
});
$container->get('view')->getEnvironment()->addFunction($func);

$app->add(\Slim\Views\TwigMiddleware::createFromContainer($app));

$app->add((new \Middlewares\TrailingSlash(false))->redirect());

$errorMiddleware= $app->addErrorMiddleware($DEBUG, true, true);

/* 404 */
$errorMiddleware->setErrorHandler(
  \Slim\Exception\HttpNotFoundException::class,
  function (Request $request, Throwable $exception,
            bool $displayErrorDetails) use ($container)
  {
    $response= new \Slim\Psr7\Response();
    return $container->get('view')->render($response->withStatus(404), '404.html');
  });

/* ROUTES */

/* A single entry */
$app->get('/{year:[0-9]+}/{month:[0-9]+}/{day:[0-9]+}/{id}',
          [ \Talapoin\Controller\Blog::class, 'entry' ])
  ->setName('entry');

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
  ->setName('tag');

$app->get('/search', [ \Talapoin\Controller\Blog::class, 'search' ])
  ->setName('search');

$app->get('/scratch[/{path:.*}]', function (Request $request, Response $response, $path) {
  $config= $GLOBALS['container']->get('config');
  return $response->withRedirect($config['static'] . '/' . $path);
});

/* Atom feeds */
$app->get('/index.atom', [ \Talapoin\Controller\Blog::class, 'atomFeed' ])
  ->setName('atom');

$app->get('/{tag}/index.atom', [ \Talapoin\Controller\Blog::class, 'atomFeed' ])
  ->setName('tag_atom');

/* Handle /entry/123 as redirect to blog entry (tmky.us goes through GLOBALS['container']) */
$app->get('/entry/{id:[0-9]+}', [ \Talapoin\Controller\Blog::class, 'entryRedirect' ]);

$app->get('/entry', function (Request $request, Response $response) {
  return $response->withRedirect('/');
});

/* Behind the scenes stuff */
$app->get('/~reindex', [ \Talapoin\Controller\Blog::class, 'reindex' ])
  ->setName('reindex');

/* Admin */
$app->group('/~admin', function (RouteCollectorProxy $app) {
  $app->get('', [ \Talapoin\Controller\Admin::class, 'top' ])
    ->setName('admin');

  $app->get('/entry[/{id}]', [ \Talapoin\Controller\Admin::class, 'editEntry' ])
    ->setName('editEntry');
  $app->post('/entry[/{id}]', [ \Talapoin\Controller\Admin::class, 'updateEntry' ]);

  $app->get('/page[/{id}]', [ \Talapoin\Controller\Admin::class, 'editPage' ])
    ->setName('editPage');
  $app->post('/page[/{id}]', [ \Talapoin\Controller\Admin::class, 'updatePage' ]);
})->add($container->get(\Talapoin\Middleware\Auth::class));

/* DEBUG only */
if ($DEBUG) {
  $app->get('/info',
            function (Request $request, Response $response) {
              ob_start();
              phpinfo();
              $response->getBody()->write(ob_get_clean());
              return $response;
            })->setName('info');

  $app->get('/info/db',
            function (Request $request, Response $response) {
              $db= $GLOBALS['container']->get('db');

              $stmt= $db->prepare("SHOW VARIABLES");

              $stmt->execute();

              $vars= $stmt->fetchAll();

              return $GLOBALS['container']->get('view')->render($response, 'info-db.html', [ 'vars' => $vars ]);
            });
}

/* Default for everything else (pages, redirects) */
$app->get('/{path:.*}', function (Request $request, Response $response, $path) {
  // check for redirects
  $query= "SELECT source, dest FROM redirect WHERE ? LIKE source";
  $stmt= $GLOBALS['container']->get('db')->prepare($query);
  if ($stmt->execute([$path]) && ($redir= $stmt->fetch())) {
    if (($pos= strpos($redir['source'], '%'))) {
      $dest= $redir['dest'] . substr($path, $pos);
    } else {
      $dest= $redir['dest'];
    }
    return $response->withRedirect($dest);
  }

  /* Trailing slash? Might need to redirect to page */
  if (substr($path, -1) == '/') {
    $path= substr($path, 0, -1);
    $query= "SELECT * FROM page WHERE slug = ?";
    $stmt= $GLOBALS['container']->get('db')->prepare($query);
    if ($stmt->execute([$path]) && ($page= $stmt->fetch(\PDO::FETCH_ASSOC))) {
      return $response->withRedirect($path);
    }
  } else {
    $query= "SELECT * FROM page WHERE slug = ?";
    $stmt= $GLOBALS['container']->get('db')->prepare($query);
    if ($stmt->execute([$path]) && ($page= $stmt->fetch(\PDO::FETCH_ASSOC))) {
      return $GLOBALS['container']->get('view')->render($response, 'page.html', [ 'page' => $page ]);
    }
  }

  throw new \Slim\Exception\HttpNotFoundException($request, $response);
});

$app->run();
