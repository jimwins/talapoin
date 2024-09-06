<?php

declare(strict_types=1);

namespace Talapoin\Controller;

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response as Response;
use Slim\Views\Twig as View;

class Blog
{
    public function __construct(
        private \Talapoin\Service\Blog $blog,
        private \Talapoin\Service\Data $data,
        private View $view
    ) {
    }

    public function top(Response $response)
    {
        $entries =
            $this->blog->getEntries()
                ->order_by_desc('created_at')
                ->limit(12)
                ->find_many();
        return $this->view->render($response, 'index.html', [ 'entries' => $entries ]);
    }

    public function tag(Response $response, $tag)
    {
        $entries =
            $this->blog->getEntries()
                ->where_raw("? IN (SELECT name
                                     FROM tag, entry_to_tag ec
                                    WHERE entry_id = entry.id AND tag_id = tag.id)", $tag)
                ->order_by_desc('created_at')
                ->find_many();
        return $this->view->render(
            $response,
            'index.html',
            [ 'tag' => $tag, 'entries' => $entries ]
        );
    }

    public function year(Response $response, $year)
    {
        $query = "SELECT DISTINCT YEAR(created_at) AS year
                    FROM entry
                   ORDER BY year DESC";
        $years = $this->data->fetch_all($query);

        $query = <<<QUERY
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
        $entries = $this->data->fetch_all($query);

        return $this->view->render($response, 'year.html', [
            'year' => $year,
            'entries' => $entries,
            'years' => $years,
        ]);
    }

    public function month(Response $response, $year, $month)
    {
        $query = "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m-01') AS ym
                    FROM entry
                   WHERE created_at BETWEEN '$year-1-1' AND '$year-12-31'";
        $months = $this->data->fetch_all($query);

        $query = <<<QUERY
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
        $entries = $this->data->fetch_all($query);

        $query = <<<QUERY
            SELECT created_at FROM entry
             WHERE created_at < '$year-$month-1'
               AND NOT draft
             ORDER BY created_at DESC LIMIT 1
        QUERY;
        $prev = $this->data->fetch_single_value($query);

        $query = <<<QUERY
            SELECT created_at FROM entry
             WHERE created_at >= '$year-$month-1' + INTERVAL 1 MONTH
               AND NOT draft
             ORDER BY created_at ASC LIMIT 1
        QUERY;
        $next = $this->data->fetch_single_value($query);

        return $this->view->render($response, 'month.html', [
            'year' => $year,
            'month' => $month,
            'entries' => $entries,
            'months' => $months,
            'next' => $next,
            'prev' => $prev,
        ]);
    }

    public function day(Response $response, $year, $month, $day)
    {
        $ymd = "$year-$month-$day";

        $entries =
            $this->blog->getEntries()
                ->where_raw("created_at BETWEEN ? and ? + INTERVAL 1 DAY", [ $ymd, $ymd ])
                ->order_by_asc('created_at')
                ->find_many();

        $query = <<<QUERY
            SELECT created_at FROM entry
             WHERE created_at < ?
               AND NOT draft
             ORDER BY created_at DESC LIMIT 1
        QUERY;
        $prev = $this->data->fetch_single_value($query, [ $ymd ]);

        $query = <<<QUERY
            SELECT created_at FROM entry
             WHERE created_at >= ? + INTERVAL 1 DAY
               AND NOT draft
             ORDER BY created_at ASC LIMIT 1
        QUERY;
        $next = $this->data->fetch_single_value($query, [ $ymd ]);

        return $this->view->render($response, 'day.html', [
            'ymd' => $ymd,
            'entries' => $entries,
            'next' => $next,
            'prev' => $prev,
        ]);
    }

    public function archive(Response $response)
    {
        $query = "SELECT AVG(total)
                    FROM (SELECT COUNT(*) AS total
                            FROM entry_to_tag
                           GROUP BY tag_id) avg";
        $avg = $this->data->fetch_single_value($query);

        $query = "SELECT name, COUNT(*) AS total
                    FROM tag
                    JOIN entry_to_tag ON (id = tag_id)
                   GROUP BY id
                   ORDER BY name";
        $tags = $this->data->fetch_all($query);

        $query = "SELECT DISTINCT YEAR(created_at) AS year
                    FROM entry
                   ORDER BY year DESC";
        $years = $this->data->fetch_all($query);

        return $this->view->render($response, 'archive.html', [
            'avg' => $avg,
            'tags' => $tags,
            'years' => $years,
        ]);
    }

    public function entry(Request $request, Response $response, $year, $month, $day, $slug)
    {
        if (is_numeric($slug)) {
            $entry = $this->blog->getEntryById($slug);
        } else {
            $entry = $this->blog->getEntryBySlug($year, $month, $day, $slug);
        }

        if (!$entry) {
            throw new \Slim\Exception\HttpNotFoundException($request);
        }

        /* Use slug in canonical URL for items with title */
        if (is_numeric($slug) && $entry->title) {
            return $response->withRedirect($entry->canonicalUrl());
        }

        $next =
            $this->blog->getEntries()
                ->where_gt('created_at', $entry->created_at)
                ->order_by_asc('created_at')
                ->find_one();

        $previous =
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

    public function entryRedirect(Request $request, Response $response, $id)
    {
        $entry = $this->blog->getEntryById($id);

        if (!$entry) {
            throw new \Slim\Exception\HttpNotFoundException($request);
        }

        return $response->withRedirect($entry->canonicalUrl());
    }

    public function addComment(
        Request $request,
        Response $response,
        \Talapoin\Service\SpamFilter $spam,
        \Talapoin\Service\Email $sendmail,
        $id
    ) {
        $entry = $this->blog->getEntryById($id);
        if (!$entry) {
            throw new \Slim\Exception\HttpNotFoundException($request);
        }

        // TODO allow admin to comment on old entries
        if ((new \Datetime($entry->created_at)) < (new \Datetime('-7 day'))) {
            throw new \Exception("Sorry, that entry is closed to new comments.");
        }

        $name = $request->getParam('name');
        $email = $request->getParam('email');
        $url = $request->getParam('url');
        $ip = $request->getServerParams()['REMOTE_ADDR'];
        $comment_text = $request->getParam('comment');

        /* Sanitize any HTML in the comment */
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', '/tmp');
        $purifier = new \HTMLPurifier($config);
        $comment_text = $purifier->purify($comment_text);

        // XXX validate $name and $email

        // check for spam
        $entry_url = $entry->fullCanonicalUrl($request);
        $spam = $spam->isSpam([
            'permalink' => $entry_url,
            'comment_type' => 'comment',
            'comment_author' => $name,
            'comment_author_email' => $email,
            'comment_author_url' => $url,
            'comment_comment' => $comment_text,
        ], $request);

        // bad spam just gets dropped
        if ($spam == 2) {
            throw new \Exception("Spam not accepted here.");
        }

        $comment = $entry->comments()->create();
        $comment->entry_id = $entry->id;
        $comment->name = $name;
        $comment->email = $email;
        $comment->url = $url;
        $comment->ip = $ip;
        $comment->spam = $spam;
        $comment->comment = $comment_text;
        $comment->save();

        $data = [
            'comment' => $comment,
            'entry' => $entry,
            'url' => $entry_url,
        ];

        $template = $this->view->getEnvironment()->load('email-comment.html');
        $subject = $template->renderBlock('title', $data);
        $body = $template->render($data);

        $sendmail->send($sendmail->defaultFromAddress(), $subject, $body);

        return $response->withRedirect($entry->canonicalUrl());
    }

    public function atomFeed(Request $request, Response $response, $tag = null)
    {
        $entries =
            $this->blog->getEntries()
                ->order_by_desc('created_at')
                ->limit(15);
        if ($tag) {
            $entries = $entries->where_raw(
                "? IN (SELECT name
                         FROM tag, entry_to_tag ec
                        WHERE entry_id = entry.id AND tag_id = tag.id)",
                $tag
            );
        }

        $hostname = $request->getUri()->getHost();

        return $this->view
            ->render($response, 'index.atom', [
                'entries' => $entries->find_many(),
                'tag' => $tag,
                'hostname' => $hostname,
            ])
            ->withHeader('Content-Type', 'application/atom+xml');
    }

    public function search(
        Request $request,
        Response $response,
        \Talapoin\Service\Meilisearch $search
    ) {
        $q = $request->getParam('q');

        $entries = $search->findEntries($q);

        return $this->view->render($response, 'search.html', [
            'q' => $q,
            'entries' => $entries,
        ]);
    }

    public function reindex(Response $response, \Talapoin\Service\Meilisearch $search)
    {
        $count = $search->reindex();
        $response->getBody()->write("Indexed $count rows.");
        return $response;
    }

    public function handleWebmention(
        Response $response,
        Request $request,
        \Talapoin\Service\Email $sendmail,
        \Slim\App $app
    ) {
        $target = $request->getParam('target');
        $target_url = parse_url($target);
        $source = $request->getParam('source');
        $source_url = parse_url($source);

        // Verify that URLs are in schemes that we recognize
        if ($target_url['scheme'] != 'https') {
            throw new \Slim\Exception\HttpBadRequestException(
                $request,
                "The scheme of the target is obviously wrong."
            );
        }

        if (!in_array($source_url['scheme'], [ 'https', 'http' ])) {
            throw new \Slim\Exception\HttpBadRequestException(
                $request,
                "Unable to handle source with that URL scheme."
            );
        }

        // Verify that $source != $target
        if ($source == $target) {
            throw new \Slim\Exception\HttpBadRequestException(
                $request,
                "The source and target cannot be the same."
            );
        }

        // Verify that $target is us by looking up the route
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $routingResults = $routeContext->getRoutingResults();
        $dispatcher = $routingResults->getDispatcher();

        try {
            $routingResults = $dispatcher->dispatch('HEAD', $target_url['path']);
            $identifier = $routingResults->getRouteIdentifier();
            $routeResolver = $app->getRouteResolver();
            $route = $routeResolver->resolveRoute($identifier);
            $routeName = $route->getName();
            $arguments = $routingResults->getRouteArguments();
        } catch (\Exception $e) {
            throw new \Slim\Exception\HttpBadRequestException(
                $request,
                "Unable to find the specified target"
            );
        }

        if ($routeName != 'entry') {
            throw new \Slim\Exception\HttpBadRequestException(
                $request,
                "That's not a target that we accept a Webmention about"
            );
        }

        $entry = $this->blog->getEntryBySlug(...$arguments);

        if (!$entry) {
            throw new \Slim\Exception\HttpBadRequestException(
                $request,
                "The target does not exist"
            );
        }

        /* XXX if we had a task queue of some sort, we'd bail here and handle it
         * asynchronously */

        $data = [
            'entry' => $entry,
            'source' => $source,
        ];

        $template = $this->view->getEnvironment()->load('email-webmention.html');
        $subject = $template->renderBlock('title', $data);
        $body = $template->render($data);

        $sendmail->send($sendmail->defaultFromAddress(), $subject, $body);

        return $response->withJson([]);
    }
}
