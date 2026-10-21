<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('activity.view');
        $rows = Database::pdo()->query('SELECT a.*, u.name AS user_name FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 500')->fetchAll();
        return $this->view('activity/index', ['title' => t('nav.activity'), 'rows' => $rows]);
    }
}
