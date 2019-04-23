<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require '../vendor/autoload.php';

$config= parse_ini_file('../config.ini', TRUE, INI_SCANNER_TYPED);

$app= new \Slim\App([ 'settings' => $config ]);

$container= $app->getContainer();

/* We use monolog for logging (but still just through PHP's log for now) */
$container['logger']= function($c) {
  $logger= new \Monolog\Logger('talapoin');
  $handler= new \Monolog\Handler\ErrorLogHandler();
  $logger->pushHandler($handler);
  return $logger;
};

/* PDO */
$container['db']= function ($c) {
  $db= $c['settings']['db'];
  $pdo= new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'],
                $db['user'], $db['pass']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return $pdo;
};

/* Twig for templating */
$container['view']= function ($container) {
  $view= new \Slim\Views\Twig('../ui', [
    'cache' => false /* No cache for now */
  ]);

  // Instantiate and add Slim specific extension
  $basePath= $container->get('request')->getUri()->getBasePath();
  $basePath= rtrim(str_ireplace('index.php', '', $basePath), '/');

  if (($tz= $container['settings']['Twig']['timezone'])) {
    $view->getEnvironment()
      ->getExtension('Twig_Extension_Core')
      ->setTimezone($tz);
  }

  $view->addExtension(new Slim\Views\TwigExtension($container->get('router'),
                                                   $basePath));

  return $view;
};

/* Add filters for blog entries */
$filter= new Twig_SimpleFilter('expand_psuedo_urls', function ($text) {
  $text= preg_replace('/isbn:([0-9x]+)/i',
                      'http://www.amazon.com/exec/obidos/ASIN/$1/trainedmonkey',
                      $text);
  $text= preg_replace('/asin:(\w+)/i',
                      'http://www.amazon.com/exec/obidos/ASIN/$1/trainedmonkey',
                      $text);
  return $text;
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new Twig_SimpleFilter('paragraphs', function ($text) {
  return preg_replace('!\n\n!', '</p><p>', $text);
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new Twig_SimpleFilter('prettify_markup', function ($text) {
  $text= preg_replace('!<q>!', '&ldquo;', $text);
  $text= preg_replace('!</q>!', '&rdquo;', $text);
  return $text;
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new Twig_SimpleFilter('slug', function ($text) {
  return preg_replace('/[^-A-Za-z0-9,]/u', '_', $text);
});
$container->get('view')->getEnvironment()->addFilter($filter);

/* 404 handler */
$container['notFoundHandler']= function ($c) {
  return function ($req, $res) use ($c) {
    return $c['view']->render($res->withStatus(404), '404.html');
  };
};

/* A single entry */
$app->get('/{year:[0-9]+}/{month:[0-9]+}/{day:[0-9]+}/{slug}',
          function (Request $req, Response $res, array $args) {

$year=  (int)$args['year'];
$month= sprintf("%02d", (int)$args['month']);
$day=   sprintf("%02d", (int)$args['day']);
$id=    $args['slug'];

if (is_numeric($id)) {
  $where= "id = $id";
} else {
  $where= "(DATE(created_at) = '$year-$month-$day'
            OR DATE(created_at) = ('$year-$month-$day' + INTERVAL 1 DAY))
           AND title LIKE '" . addslashes($id) . "'";
}

$entry= get_entry($this->db, $where);

/* Use slug in canonical URL for items with title */
if (is_numeric($id) && $entry['title']) {
  return $res->withRedirect(
    sprintf('/%s/%s',
      (new \DateTime($entry['created_at']))->format("Y/m/d"),
      $entry['title'] ?
        preg_replace('/[^-A-Za-z0-9,]/u', '_', $entry['title']) :
        $entry['id']));
}

/* Get next/previous */
$previous= get_entry($this->db, "created_at < '{$entry['created_at']}'", "DESC");
$next= get_entry($this->db, "created_at > '{$entry['created_at']}'", "ASC");

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

  $sth= $this->db->prepare($query);
  $sth->execute([$entry['id']]);

  $comments= $sth->fetchAll();
}

return $this->view->render($res, 'entry.html', [ 'entry' => $entry,
                                                 'next' => $next,
                                                 'previous' => $previous,
                                                 'comments' => $comments ]);

          })->setName('entry');

/* Year archive */
$app->get('/{year:2[0-9][0-9][0-9]}',
          function (Request $req, Response $res, array $args) {
  return $res->withRedirect($this->router->pathFor('year', $args));
});
$app->get('/{year:[0-9]+}/',
          function (Request $req, Response $res, array $args) {
  $year= $args['year'];

  $query= "SELECT DISTINCT YEAR(created_at) AS year
             FROM entry
            ORDER BY year DESC";
  $years= $this->db->query($query);

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

  $entries= $this->db->query($query)->fetchALl();

  return $this->view->render($res, 'year.html', [
  'query' => $query,
    'year' => $year,
    'entries' => $entries,
    'years' => $years,
  ]);
})->setName('year');

/* Month archive */
$app->get('/{year:[0-9]+}/{month:[0-9]+}',
          function (Request $req, Response $res, array $args) {
  return $res->withRedirect($this->router->pathFor('month', $args));
});
$app->get('/{year:[0-9]+}/{month:[0-9]+}/',
          function (Request $req, Response $res, array $args) {
  $year= $args['year'];
  $month= $args['month'];

  $query= "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m-01') AS ym
             FROM entry
            WHERE created_at BETWEEN '$year-1-1' AND '$year-12-31'";
  $months= $this->db->query($query);

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

  $entries= $this->db->query($query)->fetchALl();

  $query= <<<QUERY
    SELECT created_at FROM entry
     WHERE created_at < '$year-$month-1'
       AND NOT draft
     ORDER BY created_at DESC LIMIT 1
QUERY;
  $prev= $this->db->query($query)->fetch();

  $query= <<<QUERY
    SELECT created_at FROM entry
     WHERE created_at >= '$year-$month-1' + INTERVAL 1 MONTH
       AND NOT draft
     ORDER BY created_at ASC LIMIT 1
QUERY;
  $next= $this->db->query($query)->fetch();

  return $this->view->render($res, 'month.html', [
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
          function (Request $req, Response $res, array $args) {
  return $res->withRedirect($this->router->pathFor('day', $args));
});
$app->get('/{year:[0-9]+}/{month:[0-9]+}/{day:[0-9]+}/',
          function (Request $req, Response $res, array $args) {
  $year= $args['year'];
  $month= $args['month'];
  $day= $args['day'];

  $where= "AND created_at BETWEEN '$year-$month-$day' AND
                                  '$year-$month-$day' + INTERVAL 1 DAY";
  $entries= get_entries($this->db, $where, 'ASC', '');

  $query= <<<QUERY
    SELECT created_at FROM entry
     WHERE created_at < '$year-$month-$day'
       AND NOT draft
     ORDER BY created_at DESC LIMIT 1
QUERY;
  $prev= $this->db->query($query)->fetch();

  $query= <<<QUERY
    SELECT created_at FROM entry
     WHERE created_at >= '$year-$month-$day' + INTERVAL 1 DAY
       AND NOT draft
     ORDER BY created_at ASC LIMIT 1
QUERY;
  $next= $this->db->query($query)->fetch();

  return $this->view->render($res, 'day.html', [
  'query' => $query,
    'ymd' => "$year-$month-$day",
    'entries' => $entries,
    'next' => $next,
    'prev' => $prev,
  ]);
})->setName('day');

$app->get('/', function (Request $req, Response $res, array $args) {
  $entries= get_entries($this->db, '', 'DESC', 'LIMIT 12');
  return $this->view->render($res, 'index.html', [ 'entries' => $entries ]);
})->setName('top');

$app->get('/archive/', function (Request $req, Response $res, array $args) {
  $query= "SELECT AVG(total)
           FROM (SELECT COUNT(*) AS total
                   FROM entry_to_tag
                  GROUP BY tag_id) avg";
  $avg= $this->db->query($query)->fetchColumn();

  $query= "SELECT name, COUNT(*) AS total
             FROM tag
             JOIN entry_to_tag ON (id = tag_id)
            GROUP BY id
            ORDER BY name";
  $tags= $this->db->query($query);

  $query= "SELECT DISTINCT YEAR(created_at) AS year
             FROM entry
            ORDER BY year DESC";
  $years= $this->db->query($query);

  return $this->view->render($res, 'archive.html', [
    'avg' => $avg,
    'tags' => $tags,
    'years' => $years,
  ]);
})->setName('archive');

$app->get('/tag/{tag}', function (Request $req, Response $res, array $args) {
  $tag= $this->db->quote($args['tag']);

  $where= " AND $tag IN
                (SELECT name FROM tag, entry_to_tag ec
                  WHERE entry_id = entry.id AND tag_id = tag.id)";

  $entries= get_entries($this->db, $where, "DESC", "");

  return $this->view->render($res, 'index.html', [
    'tag' => $args['tag'],
    'entries' => $entries,
  ]);
})->setName('tag');

$app->get('/scratch[/{path:.*}]',
          function (Request $req, Response $res, array $args) {
  $static= $this->settings['static'];
  return $res->withRedirect($static . '/' . $args['path']);
});

/* Atom feeds */
$app->get('/index.atom', function (Request $req, Response $res, array $args) {
  $entries= get_entries($this->db, "", 'DESC', "LIMIT 15");

  return $this->view
    ->render($res, 'index.atom', [ 'entries' => $entries ])
    ->withHeader('Content-Type', 'application/atom+xml');
})->setName('atom');
$app->get('/{tag}/index.atom',
          function (Request $req, Response $res, array $args) {
  $tag= $this->db->quote($args['tag']);

  $where= " AND $tag IN
                (SELECT name FROM tag, entry_to_tag ec
                  WHERE entry_id = entry.id AND tag_id = tag.id)";

  $entries= get_entries($this->db, $where, "DESC", "LIMIT 15");

  return $this->view
    ->render($res, 'index.atom', [ 'entries' => $entries, 'tag' => $args['tag'] ])
    ->withHeader('Content-Type', 'application/atom+xml');
})->setName('tag_atom');

/* Handle /s/123 as redirect to blog entry (tmky.us goes through this) */
$app->get('/s/{id:[0-9]+}',
          function (Request $req, Response $res, array $args) {
  $entry= get_entry($this->db, "id = {$args['id']}");
  if ($entry) {
    return $res->withRedirect(
      sprintf('/%s/%s',
        (new \DateTime($entry['created_at']))->format("Y/m/d"),
        $entry['title'] ?
          preg_replace('/[^-A-Za-z0-9,]/u', '_', $entry['title']) :
          $entry['id']));
  }
  throw new \Slim\Exception\NotFoundException($req, $res);
});

/* Default for everything else (pages, redirects) */
$app->get('/{path:.*}', function (Request $req, Response $res, array $args) {
  $path= $args['path'];

  // check for redirects
  $query= "SELECT source, dest FROM redirect WHERE ? LIKE source";
  $stmt= $this->db->prepare($query);
  if ($stmt->execute([$path]) && ($redir= $stmt->fetch())) {
    if (($pos= strpos($redir['source'], '%'))) {
      $dest= $redir['dest'] . substr($path, $pos);
    } else {
      $dest= $redir['dest'];
    }
    return $res->withRedirect($dest);
  }

  /* No trailing slash? Might need to redirect to page */
  if (substr($path, -1) != '/') {
    $query= "SELECT * FROM page WHERE slug = ?";
    $stmt= $this->db->prepare($query);
    if ($stmt->execute([$path]) && ($page= $stmt->fetch(\PDO::FETCH_ASSOC))) {
      return $res->withRedirect($path . '/');
    }
  } else {
    $path= substr($path, 0, -1);
    $query= "SELECT * FROM page WHERE slug = ?";
    $stmt= $this->db->prepare($query);
    if ($stmt->execute([$path]) && ($page= $stmt->fetch(\PDO::FETCH_ASSOC))) {
      return $this->view->render($res, 'page.html', [ 'page' => $page ]);
    }
  }

  throw new \Slim\Exception\NotFoundException($req, $res);
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
  $entry['tags']= json_decode($entry['tags']);

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
    $entry['tags']= json_decode($entry['tags']);
    $entries[]= $entry;
  }

  return $entries;
}
