<?php

declare(strict_types=1);

namespace Talapoin\Controller;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response as Response;
use Slim\Views\Twig as View;
use Slim\Exception\HttpUnauthorizedException;

class Admin
{
    public function __construct(
        private \Talapoin\Service\Blog $blog,
        private \Talapoin\Service\Page $page,
        private \Talapoin\Service\Config $config,
        private \Talapoin\Service\Data $data
    ) {
    }

    public static function registerRoutes(\Slim\Routing\RouteCollectorProxy $app)
    {
        $app->get('', [ \Talapoin\Controller\Admin::class, 'top' ])
            ->setName('admin');

        $app->get('/entry[/{id}]', [ self::class, 'editEntry' ])
            ->setName('editEntry');
        $app->post('/entry[/{id}]', [ self::class, 'updateEntry' ]);

        $app->get('/page[/{id}]', [ self::class, 'editPage' ])
            ->setName('editPage');
        $app->post('/page[/{id}]', [ self::class, 'updatePage' ]);

        $app->post('/photo[/{id}]', [ self::class, 'updatePhoto' ])->setName('updatePhoto');
    }

    public function login(Request $request, Response $response)
    {
        $expected = $this->config['auth']['token'];

        $token = $request->getParam('token');

        if ($token == $expected) {
            $domain = $request->getHeaderLine('Host');
            $expires = new \Datetime('+1 month');

            SetCookie('token', $token, $expires->getTimeStamp(), '/', $domain, true, true);
            SetCookie('hasToken', '1', $expires->getTimeStamp(), '/', $domain, true, false);

            $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
            $routeParser = $routeContext->getRouteParser();
            return $response->withRedirect($routeParser->urlFor('admin'));
        } else {
            throw new HttpUnauthorizedException($request);
        }
    }

    public function top(Request $request, Response $response, View $view)
    {
        $entries =
            $this->blog->getEntries(true)
                ->where('draft', 1)
                ->order_by_desc('created_at')
                ->find_many();

        $pages =
            $this->page->getPages(true)
                ->order_by_asc('slug')
                ->find_many();

        $tagList = $this->blog->getTags();

        return $view->render($response, 'admin/index.html', [
            'entries' => $entries,
            'pages' => $pages,
            'tag_list' => $tagList,
        ]);
    }

    public function editEntry(Request $request, Response $response, View $view, $id = null)
    {
        if ($id) {
            $entry = $this->blog->getEntryById($id);
            if (!$entry) {
                throw new \Slim\Exception\HttpNotFoundException($request);
            }
        } else {
            $entry = [
                'title' => '',
                'entry' => '',
                'toot' => '',
                'tags' => [],
                'draft' => 1
            ];

            if (($url = $request->getParam('url'))) {
                $title = $request->getParam('title') ?: $url;
                $quote = $request->getParam('description');

                $entry['entry'] =
                    '<a href="' . htmlspecialchars($url) . '">' .
                    htmlspecialchars($title) . '</a>';
                if ($quote) {
                    $entry['entry'] .= "\n\n<blockquote>" . htmlspecialchars($quote) . "</blockquote>";
                }
            }
        }

        $tagList = $this->blog->getTags();

        return $view->render($response, 'admin/edit-entry.html', [
            'entry' => $entry,
            'tag_list' => $tagList,
        ]);
    }

    public function updateEntry(
        Request $request,
        Response $response,
        View $view,
        \Talapoin\Service\Bluesky $bluesky,
        \Talapoin\Service\Mastodon $mastodon,
        \Talapoin\Service\Blodotgs $blogs,
        \Talapoin\Service\Meilisearch $search,
        $id = null
    ) {
        if ($id) {
            $entry = $this->blog->getEntryById($id);
            if (!$entry) {
                throw new \Slim\Exception\HttpNotFoundException($request);
            }
        } else {
            $entry = $this->data->factory('Entry')->create();
            $entry->draft = 1;
        }

        $was_draft = $entry->draft;

        $title = $request->getParam('title');
        $text = $request->getParam('entry');
        $toot = $request->getParam('toot');
        $tags = $request->getParam('tags');
        $draft = (int)$request->getParam('draft');

        /* Wrapped in a transaction because tags() also does stuff */
        $this->data->beginTransaction();

        $entry->title = $title;
        $entry->entry = $text;
        $entry->toot = $toot;
        $entry->tags($tags);

        /* When going from draft -> !draft, we set our created_at date */
        if ($entry->draft && !$draft) {
            $entry->set_expr('created_at', 'NOW()');
        }

        $entry->draft = $draft;

        $entry->save();

        $this->data->commit();

        // reload to make sure we have created_at
        $entry->reload();

        if (!$entry->draft) {
            $search->reindexEntries($entry->id);
        }

        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        if ($entry->draft) {
            $url = $routeParser->urlFor('editEntry', [ 'id' => $entry->id ]);
        } else {
            $uri = $request->getUri();
            $date = new \DateTimeImmutable($entry->created_at);
            $url = $routeParser->fullUrlFor($uri, 'entry', [
                'year' => $date->format('Y'),
                'month' => $date->format('m'),
                'day' => $date->format('d'),
                'slug' => $entry->slug()
            ]);

            // first time and it has a title or toot? syndicate it and ping
            // blo.gs and send WebMentions
            if ($was_draft && $entry->title) {
                try {
                    $status = $mastodon->post(($entry->toot ?: $entry->title) . " " . $url);
                    if ($status) {
                        $entry->mastodon_uri = $status->uri;
                        $entry->save();
                    }
                } catch (\Exception $e) {
                    // XXX better logging
                    error_log((string)$e);
                }

                try {
                    $status = $bluesky->post(($entry->toot ?: $entry->title), $url);
                    if ($status) {
                        if (property_exists($status, 'uri')) {
                            $record = $bluesky->getRecord($status->uri);
                            if ($record) {
                                $entry->bluesky_uri =
                                    'https://bsky.app/profile/' .
                                    $record->thread->post->author->handle . '/post/' .
                                    // this identifier isn't available except by pulling apart the uri?
                                    preg_replace('!^.+/!', '', $record->thread->post->uri);
                                $entry->save();
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // XXX better logging
                    error_log((string)$e);
                }

                $root = $routeParser->fullUrlFor($uri, 'top');
                $feed = $routeParser->fullUrlFor($uri, 'atom');
                $template = $view->getEnvironment()->load('index.html');
                $title = $template->renderBlock('title');

                $blogs->ping($root, $title, $feed);

                $client = new \IndieWeb\MentionClient();
                $sent = $client->sendMentions($url, $entry->entry);

                error_log("Sent $sent mentions.");
            }
            // TODO send WebMention updates?
        }

        return $response->withRedirect($url);
    }

    public function editPage(Request $request, Response $response, View $view, $id = null)
    {
        if ($id) {
            $page = $this->page->getPageById($id);
            if (!$page) {
                throw new \Slim\Exception\HttpNotFoundException($request);
            }
        } else {
            $page = [
                'title' => '',
                'slug' => '',
                'content' => '',
                'description' => '',
                'draft' => 1
            ];
        }

        return $view->render($response, 'admin/edit-page.html', [ 'page' => $page ]);
    }

    public function updatePage(
        Request $request,
        Response $response,
        View $view,
        $id = null
    ) {
        if ($id) {
            $page = $this->page->getPageById($id);
            if (!$page) {
                throw new \Slim\Exception\HttpNotFoundException($request);
            }
        } else {
            $page = $this->data->factory('Page')->create();
        }

        $title = $request->getParam('title');
        $content = $request->getParam('content');
        $description = $request->getParam('description');
        $slug = $request->getParam('slug');
        $draft = (int)$request->getParam('draft');

        $page->title = $title;
        $page->content = $content;
        $page->description = $description;
        $page->slug = $slug;
        $page->draft = $draft;

        $page->save();

        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        if ($page->draft || str_starts_with($page->slug, '@')) {
            $url = $routeParser->urlFor('editPage', [ 'id' => $page->id ]);
        } else {
            $url = '/' . $page->slug;
        }

        return $response->withRedirect($url);
    }

    public function updatePhoto(
        Request $request,
        Response $response,
        \Talapoin\Service\Gumlet $gumlet,
        \Talapoin\Service\FileStorage $storage,
        \Talapoin\Service\Meilisearch $search,
        View $view,
        $id = null
    ) {
        if ($id) {
            $photo = $this->data->factory('Photo')->find_one($id);
            if (!$photo) {
                throw new \Slim\Exception\HttpNotFoundException($request);
            }
        } else {
            $photo = $this->data->factory('Photo')->create();
        }

        if ($request->getUploadedFiles()) {
            $file = $request->getUploadedFiles()['file'];

            if ($file === null) {
                throw new \Exception("There was no file uploaded.");
            }

            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errno = $file->getError();
                throw new \Exception("Got error $errno instead of uploaded file");
            }

            $fn = $file->getClientFilename();

            // turn whitespace into _ and non-alphanumeric characters
            // TODO: validate file type
            $fn = preg_replace('/\s+/', '_', $fn);
            $fn = preg_replace('/[^A-Za-z0-9_.]/', '', $fn);

            $fn = '/upload/' . $fn;

            $upload = $storage->uploadFile($fn, $file->getStream());
        } else {
            throw new \Exception("No photo uploaded.");
        }

        /* Wrapped in a transaction because tags() also does stuff */
        $this->data->beginTransaction();

        $details = $gumlet->getImageDetails($fn);
        $thumbhash = $gumlet->getThumbHash($fn);

        $photo->ulid = \Ulid\Ulid::generate(true);
        $photo->filename = $fn;

        $photo->name = $request->getParam('name');
        $photo->alt_text = $request->getParam('alt_text');
        $photo->caption = $request->getParam('caption');
        $photo->privacy = $request->getParam('privacy');

        $photo->details = json_encode($details);
        $photo->thumbhash = $thumbhash;

        $photo->width = $details->width;
        $photo->height = $details->height;

        if (@$details->exif?->Image?->DateTime) {
            $photo->taken_at = (new \DateTime($details->exif->Image->DateTime))->format('Y-m-d H:i:s');
        }

        $photo->tags($request->getParam('tags'));

        $photo->save();

        $this->data->commit();

        $search->reindexEntries($photo->id);

        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        $url = $routeParser->urlFor('photo', [ 'ulid' => (string)$photo->ulid ]);

        return $response->withRedirect($url);
    }
}
