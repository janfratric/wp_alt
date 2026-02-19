<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;

class ContactSubmissionsController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/contact-submissions — List submissions with pagination.
     */
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = Config::getInt('items_per_page', 10);
        $offset = ($page - 1) * $perPage;

        $total = QueryBuilder::query('contact_submissions')->select()->count();
        $totalPages = (int) ceil($total / $perPage);

        $submissions = QueryBuilder::query('contact_submissions')
            ->select()
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $html = $this->app->template()->render('admin/contact-submissions/index', [
            'title'       => 'Messages',
            'activeNav'   => 'messages',
            'submissions' => $submissions,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'total'       => $total,
        ]);

        return Response::html($html)
            ->withHeader('X-Frame-Options', 'DENY');
    }

    /**
     * GET /admin/contact-submissions/{id} — View a single submission.
     */
    public function view(Request $request, string $id): Response
    {
        $submission = QueryBuilder::query('contact_submissions')
            ->select()
            ->where('id', $id)
            ->first();

        if ($submission === null) {
            $_SESSION['flash_error'] = 'Submission not found.';
            return Response::redirect('/admin/contact-submissions');
        }

        $html = $this->app->template()->render('admin/contact-submissions/view', [
            'title'      => 'View Message',
            'activeNav'  => 'messages',
            'submission' => $submission,
        ]);

        return Response::html($html)
            ->withHeader('X-Frame-Options', 'DENY');
    }

    /**
     * DELETE /admin/contact-submissions/{id} — Delete a submission.
     */
    public function delete(Request $request, string $id): Response
    {
        $submission = QueryBuilder::query('contact_submissions')
            ->select()
            ->where('id', $id)
            ->first();

        if ($submission === null) {
            $_SESSION['flash_error'] = 'Submission not found.';
            return Response::redirect('/admin/contact-submissions');
        }

        QueryBuilder::query('contact_submissions')
            ->where('id', $id)
            ->delete();

        $_SESSION['flash_success'] = 'Message deleted.';
        return Response::redirect('/admin/contact-submissions');
    }
}
