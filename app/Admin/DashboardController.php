<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;

class DashboardController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/dashboard — Show dashboard with stats and recent content.
     */
    public function index(Request $request): Response
    {
        // Content counts by status
        $totalContent   = QueryBuilder::query('content')->select()->count();
        $publishedCount = QueryBuilder::query('content')->select()->where('status', 'published')->count();
        $draftCount     = QueryBuilder::query('content')->select()->where('status', 'draft')->count();

        // Counts by type
        $pageCount = QueryBuilder::query('content')->select()->where('type', 'page')->count();
        $postCount = QueryBuilder::query('content')->select()->where('type', 'post')->count();

        // User and media counts
        $userCount  = QueryBuilder::query('users')->select()->count();
        $mediaCount = QueryBuilder::query('media')->select()->count();

        // Recent content (latest 5 items with author name)
        $recentContent = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id')
            ->orderBy('content.updated_at', 'DESC')
            ->limit(5)
            ->get();

        $html = $this->app->template()->render('admin/dashboard', [
            'title'          => 'Dashboard',
            'activeNav'      => 'dashboard',
            'totalContent'   => $totalContent,
            'publishedCount' => $publishedCount,
            'draftCount'     => $draftCount,
            'pageCount'      => $pageCount,
            'postCount'      => $postCount,
            'userCount'      => $userCount,
            'mediaCount'     => $mediaCount,
            'recentContent'  => $recentContent,
        ]);

        return Response::html($html)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'");
    }
}
