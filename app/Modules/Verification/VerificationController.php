<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Verification;

use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\School\SchoolStatus;
use Furoeducare\Modules\Teacher\TeacherDocuments;
use InvalidArgumentException;

final class VerificationController
{
    public const ROOT = '/dashboard/admin/verification';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly VerificationRepository $reviews,
        private readonly SchoolRepository $schools,
        private readonly TeacherDocuments $files,
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if (!in_array(Role::Admin, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'An administrator account is required.', 403);
        }
        if ($request->method === 'POST' && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->problem('Form expired', 'Reopen the review and try again.', 403);
        }

        return match ($request->path) {
            self::ROOT => $this->page('queues', 'Verification queues'),
            self::ROOT . '/teachers', self::ROOT . '/teachers/review' => $this->teachers($request, $viewer['id']),
            self::ROOT . '/documents', self::ROOT . '/documents/review', self::ROOT . '/documents/download'
                => $this->documents($request, $viewer['id']),
            self::ROOT . '/corrections' => $this->corrections($request),
        };
    }

    private function teachers(Request $request, int $staff): Response
    {
        $submit = $request->method === 'POST';
        $rawId = $submit ? $request->form('id') : $request->query('id');
        if (!$submit && $rawId === '') {
            $filter = $request->query('status') === 'all' ? 'all' : 'pending';
            $rows = $this->reviews->teachers(self::id($request->query('before')) ?? PHP_INT_MAX,
                $filter === 'pending' ? TeacherStatus::Pending : null);
            return $this->page('list', 'Teacher professional verification', ['groups' => [
                $this->teacherGroup($rows, 'Teacher profiles', self::ROOT . '/teachers?status=' . $filter . '&before='),
            ], 'filters' => true, 'filter' => $filter]);
        }

        $id = self::id($rawId);
        $profile = $id === null ? null : $this->reviews->teacher($id);
        if ($profile === null) {
            return $this->problem('Teacher unavailable', 'This teacher profile could not be found.', 404);
        }
        $decision = $request->form('decision');
        $reason = '';
        $checks = [];
        $error = '';
        if ($submit) {
            if ($staff === $id) {
                return $this->problem('Access denied', 'Administrators cannot verify their own profile.', 403);
            }
            try {
                $reason = ReviewInput::reason($request);
                $checks = ReviewInput::checks($request, ReviewInput::TEACHER_CHECKS);
                $next = TeacherStatus::tryFrom($decision);
                $revision = self::id($request->form('revision'));
                if ($next === null || $revision === null) {
                    throw new InvalidArgumentException('Choose a valid decision and open the current review before submitting.');
                }
                $result = $this->reviews->reviewTeacher($staff, $id, $revision, $next, $reason, $checks);
                if ($result === 'saved') {
                    $this->session->notice('Teacher verification decision recorded.');
                    return $this->redirect(self::ROOT . '/teachers?id=' . $id);
                }
                if ($result === 'stale') {
                    return $this->problem('Teacher profile changed', 'Reopen the review to see the current profile and status.', 409);
                }
                if ($result === 'missing') {
                    return $this->problem('Teacher unavailable', 'This teacher profile could not be found.', 404);
                }
                $error = match ($result) {
                    'checks' => 'Confirm every required check before verifying this teacher.',
                    'cv' => 'Accept the current CV in the document queue before verifying this teacher.',
                    'incomplete' => 'Verification requires a complete profile, a verified email and an active account.',
                    default => 'This decision is unavailable from the current verification status.',
                };
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->page('teacher', 'Review teacher profile', compact('profile', 'decision', 'reason', 'checks', 'error') + [
            'selfReview' => $id === $staff,
            'history' => $this->reviews->history($id),
            'removedDocumentReviews' => $this->reviews->removedDocumentReviews($id),
        ], $error === '' ? 200 : 422);
    }

    private function documents(Request $request, int $staff): Response
    {
        $submit = $request->method === 'POST';
        $rawId = $submit ? $request->form('id') : $request->query('id');
        $download = $request->path === self::ROOT . '/documents/download';
        if (!$submit && !$download && $rawId === '') {
            $rows = $this->reviews->documents(self::id($request->query('before')) ?? PHP_INT_MAX);
            return $this->page('list', 'Documents requiring review', ['groups' => [[
                'title' => 'Pending documents', 'prefix' => self::ROOT . '/documents', 'idKey' => 'id',
                'nextPrefix' => self::ROOT . '/documents?before=',
                'items' => array_map(static fn (array $row): array => $row + [
                    'name' => $row['display_name'], 'context' => $row['first_name'] . ' ' . $row['last_name'],
                ], $rows),
            ]]]);
        }

        $id = self::id($rawId);
        $document = $id === null ? null : $this->reviews->document($id);
        if ($document === null) {
            return $this->problem('Document unavailable', 'This document could not be found.', 404);
        }
        $teacher = (int) $document['teacher_id'];
        if ($download) {
            $response = $this->files->download($teacher, $id);
            if ($response === null) {
                return $this->problem('Document unavailable', 'This document could not be found.', 404);
            }
            $this->reviews->auditDownload($staff, $teacher, $id);
            return $response;
        }

        $decision = $request->form('decision');
        $reason = '';
        $error = '';
        if ($submit) {
            if ($staff === $teacher) {
                return $this->problem('Access denied', 'Administrators cannot review their own documents.', 403);
            }
            try {
                $reason = ReviewInput::reason($request);
                $result = $this->reviews->reviewDocument($staff, $teacher, $id, $decision, $reason);
                if ($result === 'saved') {
                    $this->session->notice('Document review recorded.');
                    return $this->redirect(self::ROOT . '/documents?id=' . $id);
                }
                if ($result === 'missing') {
                    return $this->problem('Document unavailable', 'This document could not be found.', 404);
                }
                $error = 'Choose a valid decision for a document that is awaiting review.';
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->page('document', 'Review teacher document', compact('document', 'reason', 'decision', 'error') + [
            'selfReview' => $staff === $teacher,
        ], $error === '' ? 200 : 422);
    }

    private function corrections(Request $request): Response
    {
        $teachersBefore = self::id($request->query('before_teacher'));
        $schoolsBefore = self::id($request->query('before_school'));
        $teachers = $this->reviews->teachers($teachersBefore ?? PHP_INT_MAX, TeacherStatus::CorrectionsRequired);
        $schools = $this->schools->browse($schoolsBefore ?? PHP_INT_MAX, SchoolStatus::CorrectionsRequired);
        $base = self::ROOT . '/corrections?';
        return $this->page('list', 'Requested profile corrections', ['groups' => [
            $this->teacherGroup($teachers, 'Teacher corrections', $base . ($schoolsBefore === null ? '' : 'before_school=' . $schoolsBefore . '&') . 'before_teacher='),
            [
                'title' => 'School corrections', 'prefix' => '/dashboard/admin/schools', 'idKey' => 'user_id',
                'nextPrefix' => $base . ($teachersBefore === null ? '' : 'before_teacher=' . $teachersBefore . '&') . 'before_school=',
                'items' => array_map(static fn (array $row): array => $row + ['name' => $row['school_name'], 'context' => 'Changes required'], $schools),
            ],
        ]]);
    }

    private function teacherGroup(array $rows, string $title, string $nextPrefix): array
    {
        return [
            'title' => $title, 'prefix' => self::ROOT . '/teachers', 'idKey' => 'user_id', 'nextPrefix' => $nextPrefix,
            'items' => array_map(static fn (array $row): array => $row + [
                'name' => $row['first_name'] . ' ' . $row['last_name'],
                'context' => TeacherStatus::from($row['verification_status'])->label(),
            ], $rows),
        ];
    }

    private static function id(string $raw): ?int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function page(string $template, string $title, array $data = [], int $status = 200): Response
    {
        $data += ['signedIn' => true, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(), 'root' => self::ROOT];
        return Response::html($this->view->render(__DIR__ . '/Views/' . $template . '.php', $title, $data), $status, self::HEADERS);
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
}
