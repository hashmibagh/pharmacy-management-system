<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Notification;

class NotificationController extends BaseController
{
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $uid = $this->uid($request);
        $where = '(user_id = :uid OR user_id IS NULL)';
        $params = [':uid' => $uid];
        if (isset($q['unread']) && $q['unread'] !== '') {
            $where .= ' AND is_read = :rd';
            $params[':rd'] = ((int) $q['unread'] === 1) ? 0 : 1;
        }
        $result = Notification::paginate($page, $perPage, $where, $params, 'id DESC');
        $result['unread_count'] = (int) (Notification::rawOne(
            'SELECT COUNT(*) AS c FROM notifications WHERE (user_id = :uid OR user_id IS NULL) AND is_read = 0',
            [':uid' => $uid]
        )['c'] ?? 0);
        Response::success($result);
    }

    public function markRead(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $uid = $this->uid($request);
        $n = Notification::find($id);
        if (!$n || !($n['user_id'] === null || (int) $n['user_id'] === $uid)) {
            throw new ApiException('Notification not found.', 404);
        }
        Notification::update($id, ['is_read' => 1]);
        Response::success(null, 'Marked as read.');
    }

    public function markAllRead(array $request): void
    {
        $uid = $this->uid($request);
        Notification::rawExec(
            'UPDATE notifications SET is_read = 1
             WHERE (user_id = :uid OR user_id IS NULL) AND is_read = 0',
            [':uid' => $uid]
        );
        Response::success(null, 'All notifications marked as read.');
    }
}
