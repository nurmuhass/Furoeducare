<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Disclosure;

use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;

final class PrivacyController
{
    public const CONTROLS = '/privacy/controls';
    public const STAFF = '/dashboard/admin/privacy-requests';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
    private const RIGHTS = [
        'access' => 'Access my data', 'correction' => 'Correct my data', 'erasure' => 'Erase my data',
        'restriction' => 'Restrict processing', 'objection' => 'Object to processing',
        'withdraw_consent' => 'Withdraw consent',
    ];
    private const DETAIL_LIMIT = 1500;
    private const RESPONSE_LIMIT = 1000;

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly PrivacyRepository $privacy,
        private readonly DisclosurePolicy $policy,
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        $staff = $request->path === self::STAFF;
        if ($staff && !in_array(Role::Admin, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'An administrator account is required.', 403);
        }
        if ($request->method === 'POST' && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->problem('Form expired', 'Reopen the privacy page and try again.', 403);
        }

        return $staff ? $this->staff($request, $viewer['id']) : $this->controls($request, $viewer);
    }

    private function controls(Request $request, array $viewer): Response
    {
        $owner = $viewer['id'];
        $teacher = in_array(Role::Teacher, $viewer['roles'], true);
        $type = '';
        $details = '';
        $error = '';
        if ($request->method === 'POST') {
            $action = $request->form('action');
            if (in_array($action, ['pause', 'resume'], true)) {
                if (!$teacher) {
                    return $this->problem('Access denied', 'Teacher disclosure controls require a teacher account.', 403);
                }
                $paused = $action === 'pause';
                $this->privacy->setPaused($owner, $paused);
                $this->session->notice($paused ? 'Disclosures paused. Existing grants have ended.' : 'Disclosures resumed. Future sharing requires a new approval.');
                return $this->redirect(self::CONTROLS);
            }
            if ($action !== 'submit') {
                return $this->problem('Action unavailable', 'Choose a privacy action and try again.', 422);
            }
            $type = $request->form('right_type');
            $details = self::text($request->form('details'), self::DETAIL_LIMIT) ?? '';
            if (isset(self::RIGHTS[$type]) && $details !== '') {
                $id = $this->privacy->submit($owner, $type, $details);
                $this->session->notice('Privacy request submitted. Check this page for the response.');
                return $this->redirect(self::CONTROLS . '?id=' . $id);
            }
            $error = 'Choose a request type and enter up to ' . self::DETAIL_LIMIT . ' characters of relevant detail.';
        } elseif ($request->query('id') !== '') {
            return $this->detail($request->query('id'), $owner);
        }

        return $this->page('privacy-controls', 'Privacy controls', [
            'teacher' => $teacher, 'pausedAt' => $teacher ? $this->privacy->pausedAt($owner) : null,
            'grants' => $teacher ? $this->privacy->grants($owner, self::id($request->query('before_grant')) ?? PHP_INT_MAX) : [],
            'requests' => $this->privacy->ownRequests($owner, self::id($request->query('before_request')) ?? PHP_INT_MAX),
            'type' => $type, 'details' => $details, 'error' => $error, 'detailLimit' => self::DETAIL_LIMIT,
            'policy' => $this->policy,
        ], $error === '' ? 200 : 422);
    }

    private function staff(Request $request, int $staff): Response
    {
        if ($request->method === 'POST') {
            $rawId = $request->form('id');
            $id = self::id($rawId);
            if ($id === null) {
                return $this->unavailable();
            }
            $note = self::text($request->form('staff_note'), self::RESPONSE_LIMIT);
            if ($note === null) {
                return $this->detail($rawId, $staff, true,
                    'Summarize the outcome for the requester in up to ' . self::RESPONSE_LIMIT . ' characters.');
            }

            return match ($this->privacy->resolve($staff, $id, $note)) {
                'saved' => $this->resolved($id),
                'missing' => $this->unavailable(),
                'own' => $this->problem('Access denied', 'Another administrator must resolve your privacy request.', 403),
                'resolved' => $this->problem('Request already resolved', 'Reopen the request to see its recorded outcome.', 409),
            };
        }
        if ($request->query('id') !== '') {
            return $this->detail($request->query('id'), $staff, true);
        }

        $filter = $request->query('status') === 'resolved' ? 'resolved' : 'submitted';
        $requests = $this->privacy->queue($filter, self::id($request->query('before')) ?? PHP_INT_MAX);
        return $this->page('privacy-queue', 'Privacy requests', compact('requests', 'filter'));
    }

    private function detail(string $rawId, int $viewer, bool $staff = false, string $error = ''): Response
    {
        $id = self::id($rawId);
        $record = $id === null ? null : $this->privacy->request($id, $staff ? null : $viewer);
        if ($record === null) {
            return $this->unavailable();
        }
        return $this->page('privacy-request', 'Privacy request', compact('record', 'staff', 'error') + [
            'canResolve' => $staff && (int) $record['account_id'] !== $viewer && $record['status'] === 'submitted',
            'responseLimit' => self::RESPONSE_LIMIT,
        ], $error === '' ? 200 : 422);
    }

    private function resolved(int $id): Response
    {
        $this->session->notice('Privacy request resolved. The requester can view the response.');
        return $this->redirect(self::STAFF . '?id=' . $id);
    }

    private function page(string $template, string $title, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/' . $template . '.php', $title, $data + [
            'signedIn' => true, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
            'controls' => self::CONTROLS, 'staffPath' => self::STAFF, 'rights' => self::RIGHTS,
            'pageSize' => PrivacyRepository::PAGE_SIZE,
        ]), $status, self::HEADERS);
    }

    private function unavailable(): Response
    {
        return $this->problem('Request unavailable', 'This privacy request could not be found.', 404);
    }

    private function problem(string $title, string $message, int $status): Response
    {
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
            'message' => $message, 'signedIn' => true, 'csrf' => $this->session->csrf(),
        ]), $status, self::HEADERS);
    }

    private function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }

    private static function id(string $raw): ?int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function text(string $raw, int $max): ?string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (preg_match('/\p{C}/u', str_replace(["\n", "\t"], '', $raw)) !== 0) {
            return null;
        }
        $text = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $raw);
        return $text !== null && preg_match('/\A.{1,' . $max . '}\z/us', $text) === 1 ? $text : null;
    }
}
