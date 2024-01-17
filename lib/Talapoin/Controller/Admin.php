<?php
namespace Talapoin\Controller;

use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;

class Admin {
  public function __construct(
    private \Talapoin\Service\Blog $blog,
    private \Talapoin\Service\Config $config,
    private \Talapoin\Service\Data $data
  ) {
  }

  public function login(Request $request, Response $response) {
    $expected= $this->config['auth']['token'];

    $token= $request->getParam('token');

    if ($token == $expected) {
      $domain= $request->getHeaderLine('Host');
      $expires= new \Datetime('+1 month');

      SetCookie('token', $token, $expires->format('U'), '/', $domain, true, true);

      $routeContext= \Slim\Routing\RouteContext::fromRequest($request);
      $routeParser= $routeContext->getRouteParser();
      return $response->withRedirect($routeParser->urlFor('admin'));
    } else {
      throw new HttpUnauthorizedException($request);
    }
  }

  public function top(Request $request, Response $response, View $view) {
    $entries=
      $this->blog->getEntries(true)
        ->where('draft', 1)
        ->order_by_desc('created_at')
        ->find_many();

    $pages=
      $this->data->factory('Page')
        ->order_by_asc('slug')
        ->find_many();

    return $view->render($response, 'admin/index.html', [
      'entries' => $entries,
      'pages' => $pages,
    ]);
  }

  public function editEntry(Request $request, Response $response, View $view, $id= null) {
    if ($id) {
      $entry= $this->blog->getEntryById($id);
      if (!$entry) {
        throw new \Slim\Exception\HttpNotFoundException($request);
      }
    } else {
      $entry= [
        'title' => '',
        'entry' => '',
        'toot' => '',
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
    \Talapoin\Service\Blodotgs $blogs,
    \Talapoin\Service\Search $search,
    $id= null
  ) {
    if ($id) {
      $entry= $this->blog->getEntryById($id);
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
    $toot= $request->getParam('toot');
    $tags= $request->getParam('tags');
    $draft= (int)$request->getParam('draft');

    /* Wrapped in a transaction because tags() also does stuff */
    $this->data->beginTransaction();

    $entry->title= $title;
    $entry->entry= $text;
    $entry->toot= $toot;
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

    if (!$entry->draft) {
      $search->reindex($entry->id);
    }

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

      // first time and it has a title or toot? post it to mastodon and ping blo.gs
      if ($was_draft && $entry->title) {
        $mastodon->post(($entry->toot ?? $entry->title) . " " . $url);

        $root= $routeParser->fullUrlFor($uri, 'top');
        $feed= $routeParser->fullUrlFor($uri, 'atom');
        $template= $view->getEnvironment()->load('index.html');
        $title= $template->renderBlock('title');

        $blogs->ping($root, $title, $feed);
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

  public function updatePage(
    Request $request, Response $response,
    View $view,
    $id= null
  ) {
    if ($id) {
      $page= $this->data->factory('Page')->find_one($id);
      if (!$page) {
        throw new \Slim\Exception\HttpNotFoundException($request);
      }
    } else {
      $page= $this->data->factory('Page')->create();
    }

    $title= $request->getParam('title');
    $content= $request->getParam('content');
    $description= $request->getParam('description');
    $slug= $request->getParam('slug');
    $draft= (int)$request->getParam('draft');

    $page->title= $title;
    $page->content= $content;
    $page->description= $description;
    $page->slug= $slug;
    $page->draft= $draft;

    $page->save();

    $routeContext= \Slim\Routing\RouteContext::fromRequest($request);
    $routeParser= $routeContext->getRouteParser();

    if ($page->draft) {
      $url= $routeParser->urlFor('editPage', [ 'id' => $page->id ]);
    } else {
      $url= '/' . $page->slug;
    }

    return $response->withRedirect($url);
  }
}
