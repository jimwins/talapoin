<?php
namespace Talapoin\Controller;

use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;

class Admin {
  public function __construct(
    private \Talapoin\Service\Data $data
  ) {
  }

  public function top(Request $request, Response $response, View $view) {
    $entries=
      $this->data->factory('Entry')
        ->where('draft', 1)
        ->order_by_desc('created_at')
        ->find_many();

    return $view->render($response, 'admin/index.html', [ 'entries' => $entries ]);
  }

  public function editEntry(Request $request, Response $response, View $view, $id= null) {
    if ($id) {
      $entry= $this->data->factory('Entry')->find_one($id);
      if (!$entry) {
        throw new \Slim\Exception\HttpNotFoundException($request);
      }
    } else {
      $entry= [
        'title' => '',
        'entry' => '',
        'tags' => [],
        'draft' => 1
      ];

      if (($url= $request->getParam('url'))) {
        $title= $request->getParam('title') ?: $url;
        $quote= $request->getParam('description');

        $entry['entry']=
          '<a href="' . htmlspecialchars($url) . '">' .
          htmlspecialchars($title) . '</a>';
        if ($quote) {
          $entry['entry'].= "\n\n<blockquote>" . htmlspecialchars($quote) . "</blockquote>";
        }
      }
    }

    return $view->render($response, 'admin/edit-entry.html', [ 'entry' => $entry ]);
  }

  public function updateEntry(
    Request $request, Response $response,
    View $view,
    \Talapoin\Service\Mastodon $mastodon,
    $id= null
  ) {
    if ($id) {
      $entry= $this->data->factory('Entry')->find_one($id);
      if (!$entry) {
        throw new \Slim\Exception\HttpNotFoundException($request);
      }
    } else {
      $entry= $this->data->factory('Entry')->create();
      $entry->draft= 1;
    }

    $was_draft= $entry->draft;

    $title= $request->getParam('title');
    $text= $request->getParam('entry');
    $tags= $request->getParam('tags');
    $draft= (int)$request->getParam('draft');

    /* Wrapped in a transaction because tags() also does stuff */
    $this->data->beginTransaction();

    $entry->title= $title;
    $entry->entry= $text;
    $entry->tags($tags);

    /* When going from draft -> !draft, we set our created_at date */
    if ($entry->draft && !$draft) {
      $entry->set_expr('created_at', 'NOW()');
    }

    $entry->draft= $draft;

    $entry->save();

    $this->data->commit();

    // reload to make sure we have created_at
    $entry->reload();

    $routeContext= \Slim\Routing\RouteContext::fromRequest($request);
    $routeParser= $routeContext->getRouteParser();

    if ($entry->draft) {
      $url= $routeParser->urlFor('editEntry', [ 'id' => $entry->id ]);
    } else {
      $uri= $request->getUri();
      $date= new \DateTimeImmutable($entry->created_at);
      $url= $routeParser->fullUrlFor($uri, 'entry', [
        'year' => $date->format('Y'),
        'month' => $date->format('m'),
        'day' => $date->format('d'),
        'id' => $entry->slug()
      ]);

      // first time and it has a title? post it to mastodon
      if ($was_draft && $entry->title) {
        $mastodon->post($entry->title . " " . $url);
      }
    }

    return $response->withRedirect($url);
  }

  public function editPage(Request $request, Response $response, View $view, $id= null) {
    if ($id) {
      $page= $this->data->factory('Page')->find_one($id);
      if (!$page) {
        throw new \Slim\Exception\HttpNotFoundException($request);
      }
    } else {
      $page= $this->data->factory('Page')->create();
    }

    return $view->render($response, 'admin/edit-page.html', [ 'page' => $page ]);
  }
}