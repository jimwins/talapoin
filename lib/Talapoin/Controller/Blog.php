<?php
namespace Talapoin\Controller;

use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;

class Blog {
  public function __construct(
    private \Talapoin\Service\Blog $blog,
    private View $view
  ) {
  }

  public function top(Response $response) {
    $entries=
      $this->blog->getEntries()
        ->order_by_desc('created_at')
        ->limit(12)
        ->find_many();
    return $this->view->render($response, 'index.html', [ 'entries' => $entries ]);
  }

  public function tag(Response $response, $tag) {
    $entries=
      $this->blog->getEntries()
        ->where_raw("? IN (SELECT name FROM tag, entry_to_tag ec WHERE entry_id = entry.id AND tag_id = tag.id)", $tag)
        ->order_by_desc('created_at')
        ->find_many();
    return $this->view->render($response, 'index.html', [ 'tag' => $tag, 'entries' => $entries ]);
  }

  public function entry(Request $request, Response $response, $year, $month, $day, $id) {
    if (is_numeric($id)) {
      $entry= $this->blog->getEntryById($id);
    } else {
      $entry= $this->blog->getEntryBySlug($year, $month, $day, $id);
    }

    if (!$entry) {
      throw new \Slim\Exception\HttpNotFoundException($request);
    }

    /* Use slug in canonical URL for items with title */
    if (is_numeric($id) && $entry->title) {
      return $response->withRedirect($entry->canonicalUrl());
    }

    $next=
      $this->blog->getEntries()
        ->where_gt('created_at', $entry->created_at)
        ->order_by_asc('created_at')
        ->find_one();

    $previous=
      $this->blog->getEntries()
        ->where_lt('created_at', $entry->created_at)
        ->order_by_desc('created_at')
        ->find_one();

    return $this->view->render($response, 'entry.html', [
      'entry' => $entry,
      'next' => $next,
      'previous' => $previous,
    ]);
  }

  public function entryRedirect(Request $request, Response $response, $id) {
    $entry= $this->blog->getEntryById($id);

    if (!$entry) {
      throw new \Slim\Exception\HttpNotFoundException($request, $response);
    }

    return $response->withRedirect($entry->canonicalUrl());
  }

  public function atomFeed(Response $response, $tag= null) {
    $entries=
      $this->blog->getEntries()
        ->order_by_desc('created_at')
        ->limit(15);
    if ($tag) {
      $entries= $entries->where_raw("? IN (SELECT name FROM tag, entry_to_tag ec WHERE entry_id = entry.id AND tag_id = tag.id)", $tag);
    }

    return $this->view
      ->render($response, 'index.atom', [ 'entries' => $entries->find_many() ])
      ->withHeader('Content-Type', 'application/atom+xml');
  }

  public function search(Request $request, Response $response, \Talapoin\Service\Search $search) {
    $q= $request->getParam('q');

    $entries= $search->findEntries($q);

    return $this->view->render($response, 'search.html', [
      'q' => $q,
      'entries' => $entries,
    ]);
  }

  public function reindex(Response $response, \Talapoin\Service\Search $search) {
    $count= $search->reindex();
    $response->getBody()->write("Indexed $count rows.");
    return $response;
  }
}
