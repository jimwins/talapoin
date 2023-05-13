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
]);
$container= $builder->build();
$container->set('config', $config);

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

/* Search */
$container->set('search', function ($c) {
  $search= $c->get('config')['search'];
  $pdo= new PDO($search['dsn'], $search['user'], $search['password']);
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

  // Add StringLoader extension
  $view->addExtension(new \Twig\Extension\StringLoaderExtension());

  return $view;
});

/* Add filters for blog entries */
$filter= new \Twig\TwigFilter('expand_psuedo_urls', function ($text) {
  $text= preg_replace('/isbn:([0-9x]+)/i',
                      'http://www.amazon.com/exec/obidos/ASIN/$1/trainedmonkey',
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

/* A single entry */
$app->get('/{year:[0-9]+}/{month:[0-9]+}/{day:[0-9]+}/{id}',
          function (Request $request, Response $response, $year, $month, $day, $id) {

if (is_numeric($id)) {
  $where= "id = $id";
} else {
  $where= "(DATE(created_at) = '$year-$month-$day'
            OR DATE(created_at) = ('$year-$month-$day' + INTERVAL 1 DAY))
           AND title LIKE '" . addslashes($id) . "'";
}

$entry= get_entry($GLOBALS['container']->get('db'), $where);

/* Use slug in canonical URL for items with title */
if (is_numeric($id) && $entry['title']) {
  return $response->withRedirect(
    sprintf('/%s/%s',
      (new \DateTime($entry['created_at']))->format("Y/m/d"),
      $entry['title'] ?
        preg_replace('/[^-A-Za-z0-9,]/u', '_', $entry['title']) :
        $entry['id']));
}

/* Get next/previous */
$previous= get_entry($GLOBALS['container']->get('db'), "created_at < '{$entry['created_at']}'", "DESC");
$next= get_entry($GLOBALS['container']->get('db'), "created_at > '{$entry['created_at']}'", "ASC");

/* Get comments */
$comments= [];
if ($entry['comments']) {
  $query=
  " SELECT id, name, email, url, title, comment,
         INET_NTOA(ip) AS ip,
         UNIX_TIMESTAMP(created_at) AS created_at
    FROM comment
   WHERE entry_id = ? AND NOT tb
   ORDER BY created_at ASC
  ";

  $sth= $GLOBALS['container']->get('db')->prepare($query);
  $sth->execute([$entry['id']]);

  $comments= $sth->fetchAll();
}

return $GLOBALS['container']->get('view')->render($response, 'entry.html', [ 'entry' => $entry,
                                                 'next' => $next,
                                                 'previous' => $previous,
                                                 'comments' => $comments ]);

          })->setName('entry');

/* Year archive */
$app->get('/{year:[0-9]+}',
          function (Request $request, Response $response, $year) {
  $query= "SELECT DISTINCT YEAR(created_at) AS year
             FROM entry
            ORDER BY year DESC";
  $years= $GLOBALS['container']->get('db')->query($query);

  $query= <<<QUERY
    SELECT MIN(created_at) AS created_at,
           DAYOFMONTH(MIN(created_at)) AS day,
           MONTH(MIN(created_at)) AS month,
           YEAR(MIN(created_at)) AS year,
           TO_DAYS(created_at) AS ymd
      FROM entry
     WHERE created_at BETWEEN '$year-1-1' AND '$year-1-1' + INTERVAL 1 YEAR
     GROUP BY ymd
     ORDER BY month ASC, day ASC
QUERY;

  $entries= $GLOBALS['container']->get('db')->query($query)->fetchALl();

  return $GLOBALS['container']->get('view')->render($response, 'year.html', [
  'query' => $query,
    'year' => $year,
    'entries' => $entries,
    'years' => $years,
  ]);
})->setName('year');

$app->get('/{year:[0-9]+}/{month:[0-9]+}',
          function (Request $request, Response $response, $year, $month) {
  $query= "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m-01') AS ym
             FROM entry
            WHERE created_at BETWEEN '$year-1-1' AND '$year-12-31'";
  $months= $GLOBALS['container']->get('db')->query($query);

  $query= <<<QUERY
    SELECT MIN(created_at) AS created_at,
           DAYOFMONTH(MIN(created_at)) AS day,
           MONTH(MIN(created_at)) AS month,
           YEAR(MIN(created_at)) AS year,
           TO_DAYS(created_at) AS ymd
      FROM entry
     WHERE created_at BETWEEN '$year-$month-1'
                          AND '$year-$month-1' + INTERVAL 1 MONTH
     GROUP BY ymd
     ORDER BY month ASC, day ASC
QUERY;

  $entries= $GLOBALS['container']->get('db')->query($query)->fetchALl();

  $query= <<<QUERY
    SELECT created_at FROM entry
     WHERE created_at < '$year-$month-1'
       AND NOT draft
     ORDER BY created_at DESC LIMIT 1
QUERY;
  $prev= $GLOBALS['container']->get('db')->query($query)->fetch();

  $query= <<<QUERY
    SELECT created_at FROM entry
     WHERE created_at >= '$year-$month-1' + INTERVAL 1 MONTH
       AND NOT draft
     ORDER BY created_at ASC LIMIT 1
QUERY;
  $next= $GLOBALS['container']->get('db')->query($query)->fetch();

  return $GLOBALS['container']->get('view')->render($response, 'month.html', [
  'query' => $query,
    'year' => $year,
    'month' => $month,
    'entries' => $entries,
    'months' => $months,
    'next' => $next,
    'prev' => $prev,
  ]);
})->setName('month');

/* Day archive */
$app->get('/{year:[0-9]+}/{month:[0-9]+}/{day:[0-9]+}',
          function (Request $request, Response $response, $year, $month, $day) {
  $where= "AND created_at BETWEEN '$year-$month-$day' AND
                                  '$year-$month-$day' + INTERVAL 1 DAY";
  $entries= get_entries($GLOBALS['container']->get('db'), $where, 'ASC', '');

  $query= <<<QUERY
    SELECT created_at FROM entry
     WHERE created_at < '$year-$month-$day'
       AND NOT draft
     ORDER BY created_at DESC LIMIT 1
QUERY;
  $prev= $GLOBALS['container']->get('db')->query($query)->fetch();

  $query= <<<QUERY
    SELECT created_at FROM entry
     WHERE created_at >= '$year-$month-$day' + INTERVAL 1 DAY
       AND NOT draft
     ORDER BY created_at ASC LIMIT 1
QUERY;
  $next= $GLOBALS['container']->get('db')->query($query)->fetch();

  return $GLOBALS['container']->get('view')->render($response, 'day.html', [
  'query' => $query,
    'ymd' => "$year-$month-$day",
    'entries' => $entries,
    'next' => $next,
    'prev' => $prev,
  ]);
})->setName('day');

$app->get('/', function (Request $request, Response $response) {
  $view= $GLOBALS['container']->get('view');
  $entries= get_entries($GLOBALS['container']->get('db'), '', 'DESC', 'LIMIT 12');
  return $view->render($response, 'index.html', [ 'entries' => $entries ]);
})->setName('top');

$app->get('/archive', function (Request $request, Response $response) {
  $query= "SELECT AVG(total)
           FROM (SELECT COUNT(*) AS total
                   FROM entry_to_tag
                  GROUP BY tag_id) avg";
  $avg= $GLOBALS['container']->get('db')->query($query)->fetchColumn();

  $query= "SELECT name, COUNT(*) AS total
             FROM tag
             JOIN entry_to_tag ON (id = tag_id)
            GROUP BY id
            ORDER BY name";
  $tags= $GLOBALS['container']->get('db')->query($query);

  $query= "SELECT DISTINCT YEAR(created_at) AS year
             FROM entry
            ORDER BY year DESC";
  $years= $GLOBALS['container']->get('db')->query($query);

  return $GLOBALS['container']->get('view')->render($response, 'archive.html', [
    'avg' => $avg,
    'tags' => $tags,
    'years' => $years,
  ]);
})->setName('archive');

$app->get('/tag/{tag}', function (Request $request, Response $response, $tag) {
  $qtag= $GLOBALS['container']->get('db')->quote($tag);

  $where= " AND $qtag IN
                (SELECT name FROM tag, entry_to_tag ec
                  WHERE entry_id = entry.id AND tag_id = tag.id)";

  $entries= get_entries($GLOBALS['container']->get('db'), $where, "DESC", "");

  return $GLOBALS['container']->get('view')->render($response, 'index.html', [
    'tag' => $tag,
    'entries' => $entries,
  ]);
})->setName('tag');

$app->get('/search', function (Request $request, Response $response) {
  $q= $request->getParam('q');

  $query= "SELECT id FROM talapoin WHERE MATCH(?)";
  $stmt= $GLOBALS['container']->get('search')->prepare($query);

  $stmt->execute([$q]);

  $ids= array_map(function ($e) { return $e['id']; }, $stmt->fetchAll());

  if ($ids) {
    $entries= get_entries($GLOBALS['container']->get('db'),
                          'AND id IN (' . join(',', $ids) . ')',
                          "DESC", "");
  } else {
    $entries= [];
  }

  return $GLOBALS['container']->get('view')->render($response, 'search.html', [
    'q' => $q,
    'entries' => $entries,
  ]);
});

$app->get('/scratch[/{path:.*}]', function (Request $request, Response $response, $path) {
  $config= $GLOBALS['container']->get('config');
  return $response->withRedirect($config['static'] . '/' . $path);
});

/* Atom feeds */
$app->get('/index.atom', function (Request $request, Response $response) {
  $entries= get_entries($GLOBALS['container']->get('db'), "", 'DESC', "LIMIT 15");

  return $GLOBALS['container']->get('view')
    ->render($response, 'index.atom', [ 'entries' => $entries ])
    ->withHeader('Content-Type', 'application/atom+xml');
})->setName('atom');
$app->get('/{tag}/index.atom',
          function (Request $request, Response $response, $tag) {
  $qtag= $GLOBALS['container']->get('db')->quote($tag);

  $where= " AND $qtag IN
                (SELECT name FROM tag, entry_to_tag ec
                  WHERE entry_id = entry.id AND tag_id = tag.id)";

  $entries= get_entries($GLOBALS['container']->get('db'), $where, "DESC", "LIMIT 15");

  return $GLOBALS['container']->get('view')
    ->render($response, 'index.atom', [ 'entries' => $entries, 'tag' => $tag ])
    ->withHeader('Content-Type', 'application/atom+xml');
})->setName('tag_atom');

/* Handle /entry/123 as redirect to blog entry (tmky.us goes through GLOBALS['container']) */
$app->get('/entry/{id:[0-9]+}',
          function (Request $request, Response $response, $id) {
  $entry= get_entry($GLOBALS['container']->get('db'), "id = $id");
  if ($entry) {
    return $response->withRedirect(
      sprintf('/%s/%s',
        (new \DateTime($entry['created_at']))->format("Y/m/d"),
        $entry['title'] ?
          preg_replace('/[^-A-Za-z0-9,]/u', '_', $entry['title']) :
          $entry['id']));
  }
  throw new \Slim\Exception\HttpNotFoundException($request, $response);
});

$app->get('/entry', function (Request $request, Response $response) {
  return $response->withRedirect('/');
});

/* Behind the scenes stuff */
$app->get('/~reindex', function (Request $request, Response $response) {
  $entries= get_entries($GLOBALS['container']->get('db'), "", "ASC", "");

  $GLOBALS['container']->get('search')->query("DELETE FROM talapoin WHERE id > 0");

  $query= "INSERT INTO talapoin (id, title, content, created_at, tags)
           VALUES (?, ?, ?, ?, ?)";
  $stmt= $GLOBALS['container']->get('search')->prepare($query);

  $rows= 0;
  foreach ($entries as $entry) {
    $stmt->execute([
      $entry['id'],
      $entry['title'],
      $entry['entry'],
      $entry['created_at'],
      $entry['tags'] ? join(' ', $entry['tags']) : ""
    ]);
    $rows+= $stmt->rowCount();
  }

  $response->getBody()->write("Indexed $rows rows.");
  return $response;
});

/* Posting via email2webhook */
$app->post('/~webhook/post-entry',
           function (Request $request, Response $response) {
  $key= $request->getParam('key');
  $post_key= $GLOBALS['container']->get('config')['post_key'];

  if ($key != $post_key) {
    return $response->withStatus(403, "Not allowed.");
  }

  if ($GLOBALS['container']->get('config')['debug_webhook']) {
    file_put_contents("/tmp/debug_webhook", (string)$request->getBody());
  }

  $data= $request->getParsedBody();

  if ($data['sender'] != $GLOBALS['container']->get('config')['post_from']) {
    return $response->withStatus(403, "Not allowed.");
  }

  $title= $data['subject'];
  $entry= $data['body_plain'];

  if (!$entry) {
    throw new \Exception("No entry.");
  }

  $page= null;
  if (preg_match('!^page:\s*([-/a-z0-9]+)$!m', $entry, $m, PREG_OFFSET_CAPTURE))
  {
    $page= $m[1][0];
    // trim off the tags
    $entry= substr_replace($entry, "", $m[0][1], $m[0][1] + strlen($m[0][0]));
  }

  $tags= [];
  if (preg_match('!^tags:\s*(.+)$!m', $entry, $m, PREG_OFFSET_CAPTURE)) {
    $tags= preg_split('!\s*,\s*!', $m[1][0]);
    // trim off the tags
    $entry= substr_replace($entry, "", $m[0][1], $m[0][1] + strlen($m[0][0]));
  }
  if (!$tags && !$page) die($entry);

  trim($title);
  trim($entry);

  $GLOBALS['container']->get('db')->beginTransaction();

  if ($page) {
    $query= "INSERT INTO page (title, slug, entry) VALUES (?,?,?)";
    $stmt= $GLOBALS['container']->get('db')->prepare($query);

    $stmt->execute([$title, $page, $entry]);
  } else {
    $query= "INSERT INTO entry (title, entry) VALUES (?,?)";
    $stmt= $GLOBALS['container']->get('db')->prepare($query);

    $find_tag= $GLOBALS['container']->get('db')->prepare("SELECT id FROM tag WHERE name = ?");
    $add_tag= $GLOBALS['container']->get('db')->prepare("INSERT INTO tag SET name = ?");
    $add_link= $GLOBALS['container']->get('db')->prepare("INSERT INTO entry_to_tag SET entry_id = ?, tag_id = ?");

    $stmt->execute([$title, $entry]);

    $entry_id= $GLOBALS['container']->get('db')->lastInsertId();
    foreach ($tags as $tag) {
      $tag= trim($tag);

      if ($find_tag->execute([$tag])) {
        $tag_id= $find_tag->fetchColumn();
      }

      if (!$tag_id) {
        if (!$add_tag->execute([$tag]))
          throw new \Exception("Unable to add new tag '$tag'.");
        $tag_id= $GLOBALS['container']->get('db')->lastInsertId();
      }

      if ($tag_id) {
        if (!$add_link->execute([$entry_id, $tag_id]))
          throw new \Exception("Unable to add tag for entry.");
      }
    }
  }

  $GLOBALS['container']->get('db')->commit();

  return $response->withStatus(200, "Success.");
});

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
});

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

function get_entry($db, $where, $order= 'ASC') {
  $query= <<<QUERY
    SELECT id, title, entry, closed, created_at, updated_at, article,
           (SELECT JSON_ARRAYAGG(name)
              FROM entry_to_tag, tag
             WHERE entry_id = entry.id AND tag_id = tag.id) AS tags,
           (SELECT COUNT(*)
              FROM comment
             WHERE entry_id = entry.id AND NOT tb) AS comments
      FROM entry
     WHERE $where AND NOT draft
     ORDER BY id $order
QUERY;

  $stmt= $db->query($query);

  $entry= $stmt->fetch();
  if ($entry && $entry['tags']) {
    $entry['tags']= json_decode($entry['tags']);
  }

  return $entry;
}

function get_entries($db, $where, $order, $limit) {
  $query= <<<QUERY
    SELECT id, title, entry, closed, created_at, updated_at, article,
           (SELECT JSON_ARRAYAGG(name)
              FROM entry_to_tag, tag
             WHERE entry_id = entry.id AND tag_id = tag.id) AS tags,
           (SELECT COUNT(*)
              FROM comment
             WHERE entry_id = entry.id AND NOT tb) AS comments
      FROM entry
     WHERE NOT draft $where
     ORDER BY created_at $order
     $limit
QUERY;

  $stmt= $db->query($query);

  $entries= [];
  while (($entry= $stmt->fetch())) {
    if ($entry['tags']) {
      $entry['tags']= json_decode($entry['tags']);
    }
    $entries[]= $entry;
  }

  return $entries;
}
