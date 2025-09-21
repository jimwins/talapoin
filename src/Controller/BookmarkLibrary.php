<?php

namespace Talapoin\Controller;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response as Response;
use Slim\Views\Twig as View;

class BookmarkLibrary
{
    public function __construct(
        private \Talapoin\Service\BookmarkLibrary $library,
        private \Talapoin\Service\Data $data,
    ) {
    }

    public static function registerRoutes(\Slim\Routing\RouteCollectorProxy $app)
    {
        $app->get('', [ self::class, 'top' ])->setName('bookmarks');
        $app->post('', [ self::class, 'addBookmark' ])
            ->add($app->getContainer()->get(\Talapoin\Middleware\Auth::class));
        $app->get('/tag', [ self::class, 'showTags' ])->setName('bookmark-tags');
        $app->get('/tag/{tag}', [ self::class, 'showTag' ])->setName('bookmark-tag');
        $app->get('/{ulid:[^_]*}[_{slug:.*}]', [ self::class, 'showBookmark' ])->setName('bookmark');
    }

    public function top(
        Request $request,
        Response $response,
        View $view,
        \Talapoin\Service\SQLiteSearch $search,
    ) {
        $q = $request->getParam('q');
        $page = (int) $request->getParam('page') ?: 0;
        $page_size = (int) $request->getParam('page_size') ?: 24;

        if ($q) {
            $bookmarks = $search->findBookmarks($q);
        } else {
            $bookmarks = $this->library->getBookmarks(page: $page, page_size: $page_size)->find_many();
        }

        return $view->render($response, 'bookmark/index.html', [
            'query_params' => $request->getParams(),
            'bookmarks' => $bookmarks,
            'q' => $q,
            'page' => $page,
            'page_size' => $page_size,
        ]);
    }

    public function showTags(Request $request, Response $response, View $view)
    {
        $query = "SELECT AVG(total)
                    FROM (SELECT COUNT(*) AS total
                            FROM bookmark_to_tag
                           GROUP BY tag_id) avg";
        $avg = $this->data->fetch_single_value($query);

        $query = "SELECT name, COUNT(*) AS total
                    FROM tag
                    JOIN bookmark_to_tag ON (id = tag_id)
                   GROUP BY id
                   ORDER BY name";
        $tags = $this->data->fetch_all($query);

        $query = "SELECT DISTINCT strftime('%Y', created_at) AS year
                    FROM bookmark
                   ORDER BY year DESC";
        $years = $this->data->fetch_all($query);

        return $view->render($response, 'bookmark/tags.html', [
            'avg' => $avg,
            'tags' => $tags,
            'years' => $years,
        ]);
    }

    public function showTag(Request $request, Response $response, View $view, $tag)
    {
        $page = (int) $request->getParam('page') ?: 0;
        $page_size = (int) $request->getParam('page_size') ?: 24;
        $bookmarks =
            $this->library->getBookmarks(page: $page, page_size: $page_size)
                ->where_raw("? IN (SELECT name
                                     FROM tag, bookmark_to_tag ec
                                    WHERE bookmark_id = bookmark.id AND tag_id = tag.id)", $tag)
                ->order_by_desc('created_at')
                ->find_many();
        return $view->render($response, 'bookmark/index.html', [
            'query_params' => $request->getParams(),
            'tag' => $tag,
            'bookmarks' => $bookmarks,
            'page' => $page,
            'page_size' => $page_size,
        ]);
    }

    public function showBookmark(Request $request, Response $response, View $view, $ulid)
    {
        $bookmark = $this->library->getBookmarkByUlid($ulid);
        if (!$bookmark) {
            throw new \Slim\Exception\HttpNotFoundException($request);
        }

        return $view->render($response, 'bookmark/bookmark.html', [
            'bookmark' => $bookmark,
        ]);
    }

    public function addBookmark(Request $request, Response $response)
    {
        $method = $request->getParam('method');

        switch ($method) {
            case 'pinboardJson':
                return $this->addBookmarksFromPinboardJson($request, $response);
            default:
                throw new \Exception("Don't know how to handle that method.");
        }
    }

    public function addBookmarksFromPinboardJson(Request $request, Response $response)
    {
        if ($request->getUploadedFiles()) {
            $file = $request->getUploadedFiles()['pinboard'];

            $pinboard = json_decode($file->getStream(), flags: \JSON_THROW_ON_ERROR);

            $this->data->beginTransaction();

            foreach ($pinboard as $pin) {
                // Cheat here because time isn't in milliseconds
                $ts = (new \DateTime($pin->time))->getTimestamp() * 1000;

                $bookmark = $this->library->createBookmark();
                $bookmark->ulid = \Ulid\Ulid::fromTimestamp($ts, true);
                $bookmark->href = $pin->href;
                $bookmark->title = $pin->description;
                $bookmark->comment = $pin->extended;
                if ($pin->to_read === 'yes') {
                    $bookmark->to_read = 1;
                }
                $bookmark->created_at = (new \DateTime($pin->time))->format('Y-m-d H:i:s');

                $tags = preg_split('/\s+/', $pin->tags);
                $bookmark->tags($tags);

                $bookmark->save();
            }

            $this->data->commit();

            return $response->withJson(['message' => 'Success!']);
        } else {
            throw new \Exception("Expected Pinboard JSON data");
        }
    }
}
