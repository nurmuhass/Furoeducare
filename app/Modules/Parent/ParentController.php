<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Parent;

use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Directory\DirectoryFilters;

final class ParentController
{
    private const DASHBOARD = '/dashboard/parent';
    public const REQUESTS = self::DASHBOARD . '?tab=requests';
    public const EDITOR = self::DASHBOARD . '?tab=request-teacher';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
    private const TABS = [
        'overview' => 'Overview',
        'find-teachers' => 'Find Teachers',
        'request-teacher' => 'Request Lesson Teacher',
        'requests' => 'My Requests',
        'recommended' => 'Recommended Teachers',
        'saved' => 'Saved Teachers',
        'arrangements' => 'Lesson Arrangements',
        'library' => 'Digital Library',
        'messages' => 'Messages',
        'notifications' => 'Notifications',
        'settings' => 'Settings',
    ];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly ParentRepository $parents,
        private readonly TutoringRepository $tutoring,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) {
            return $this->redirect('/login');
        }
        if (!in_array(Role::Parent, $viewer['roles'], true)) {
            return $this->problem('Access denied', 'A parent or guardian account is required.', 403);
        }
        if ($request->method === 'POST' && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->problem('Form expired', 'Reopen the form and try again.', 403);
        }

        if ($request->path === self::DASHBOARD && $request->query('tab') === 'notifications') {
            return $this->redirect('/notifications');
        }
        $profile = $this->parents->profile($viewer['id']);
        if ($request->path === self::DASHBOARD && $request->query('tab') === 'messages') {
            return $this->redirect('/messages');
        }
        if ($request->path === self::DASHBOARD . '/profile') {
            return $this->saveProfile($request, $viewer, $profile);
        }
        if ($profile === null) {
            return $this->profileRequired($request, $viewer);
        }

        return match ($request->path) {
            self::DASHBOARD => $this->show($request, $viewer, $profile),
            self::DASHBOARD . '/requests' => $this->saveDraft($request, $viewer, $profile),
            self::DASHBOARD . '/requests/submit' => $this->submitDraft($request, $viewer),
        };
    }

    private function profileRequired(Request $request, array $viewer): Response
    {
        if ($request->path !== self::DASHBOARD) {
            return $this->problem('Parent profile required', 'Complete your parent profile before managing tutoring requests.', 403);
        }
        return $this->page('setup', 'Complete your parent profile', [
            'values' => ['email' => $viewer['email']], 'errors' => [], 'reference' => DirectoryFilters::reference($request->query('ref')),
        ]);
    }

    private function saveProfile(Request $request, array $viewer, ?array $profile): Response
    {
        $setup = $profile === null;
        $reference = $setup ? DirectoryFilters::reference($request->form('ref')) : null;
        [$values, $errors] = ParentInput::profile($request, acceptance: $setup);
        if ($errors !== []) {
            $values['email'] = $viewer['email'];
            return $setup
                ? $this->page('setup', 'Complete your parent profile', compact('values', 'errors', 'reference'), 422)
                : $this->screen('settings', $viewer, $profile, compact('values', 'errors'), 422);
        }
        if ($setup) {
            $this->parents->create($viewer['id'], $values);
        } else {
            $this->parents->updateProfile($viewer['id'], $values);
        }
        $this->session->notice('Parent profile saved.');
        if ($setup) {
            return $this->redirect($reference === null ? self::DASHBOARD : self::EDITOR . '&ref=' . $reference);
        }
        return $this->redirect(self::DASHBOARD . '?tab=settings');
    }

    private function show(Request $request, array $viewer, array $profile): Response
    {
        $tab = $request->query('tab');
        if (!isset(self::TABS[$tab])) {
            $tab = 'overview';
        }
        $data = [];
        if ($tab === 'overview') {
            $data['counts'] = $this->tutoring->counts($viewer['id']);
        } elseif ($tab === 'notifications') {
            $data['notifications'] = $this->tutoring->notifications($viewer['id']);
        } elseif ($tab === 'recommended') {
            $rows = $this->tutoring->recommendations($viewer['id'], $this->positive($request->query('before')) ?? PHP_INT_MAX);
            $data['recommendations'] = array_map(fn (array $record): array => $record + [
                'candidates' => $this->tutoring->visibleCandidates($record),
            ], array_slice($rows, 0, 8));
            $data['next'] = count($rows) > 8 ? (int) $rows[7]['id'] : null;
        } elseif ($tab === 'arrangements') {
            $rows = $this->tutoring->requests($viewer['id'], $this->positive($request->query('before')) ?? PHP_INT_MAX, TutoringStatus::Confirmed);
            $data['requests'] = array_slice($rows, 0, 30);
            $data['next'] = count($rows) > 30 ? (int) $rows[29]['id'] : null;
        } elseif ($tab === 'requests' || $tab === 'request-teacher') {
            $raw = $request->query('id');
            $record = $raw === '' ? null : $this->tutoring->owned($viewer['id'], $this->positive($raw) ?? 0);
            if ($raw !== '' && $record === null) {
                return $this->requestUnavailable();
            }
            if ($tab === 'request-teacher' && $record !== null && $record['status'] !== TutoringStatus::Draft->value) {
                return $this->redirect(self::REQUESTS . '&id=' . $record['id']);
            }
            if ($tab === 'request-teacher') {
                $requested = $request->query('ref');
                if ($record === null && $requested !== '') {
                    $reference = DirectoryFilters::reference($requested);
                    if ($reference === null || $this->tutoring->tutor($reference) === null) {
                        return $this->problem('Teacher unavailable', 'Choose an available tutor in Find Teachers.', 404);
                    }
                    $record = ['selected_teacher_reference' => $reference];
                }
            }
            $data['record'] = $record;
            if ($tab === 'requests' && $record === null) {
                $rows = $this->tutoring->requests($viewer['id'], $this->positive($request->query('before')) ?? PHP_INT_MAX);
                $data['requests'] = array_slice($rows, 0, 30);
                $data['next'] = count($rows) > 30 ? (int) $rows[29]['id'] : null;
            } elseif ($tab === 'requests') {
                $data['history'] = $this->tutoring->history((int) $record['id']);
                $data['candidates'] = $this->tutoring->visibleCandidates($record);
                $data['missing'] = TutoringInput::missing($record);
            }
        }

        return $this->screen($tab, $viewer, $profile, $data);
    }

    private function saveDraft(Request $request, array $viewer, array $profile): Response
    {
        $owner = $viewer['id'];
        $raw = $request->form('id');
        $id = $this->positive($raw);
        $version = $request->form('version');
        $reference = null;
        if ($raw !== '') {
            $record = $id === null ? null : $this->tutoring->owned($owner, $id);
            if ($record === null) {
                return $this->requestUnavailable();
            }
            if ($record['status'] !== TutoringStatus::Draft->value) {
                return $this->problem('Request already submitted', 'Open My Requests to view the submitted details.', 409);
            }
            $reference = $record['selected_teacher_reference'];
            if (!TutoringInput::validVersion($version)) {
                return $this->problem('Reopen the draft', 'Open the saved draft before making changes.', 422);
            }
        } elseif ($request->form('ref') !== '') {
            $reference = DirectoryFilters::reference($request->form('ref'));
            if ($reference === null || $this->tutoring->tutor($reference) === null) {
                return $this->problem('Teacher unavailable', 'Choose an available tutor in Find Teachers.', 404);
            }
        }

        [$values, $errors] = TutoringInput::draft($request);
        $clearReference = $id !== null && $request->form('general_request') === '1';
        if ($errors !== []) {
            return $this->screen('request-teacher', $viewer, $profile, [
                'record' => ['id' => $id, 'updated_at' => $version, 'selected_teacher_reference' => $reference] + $values,
                'errors' => $errors,
                'clearReference' => $clearReference,
            ], 422);
        }
        try {
            $id = $this->tutoring->saveDraft($owner, $id, $version, $reference, $values, $clearReference);
        } catch (DomainException $e) {
            return $this->problem('Draft unavailable', $e->getMessage(), $e->getCode());
        }
        $this->session->notice('Private tutoring draft saved.');
        return $this->redirect(self::REQUESTS . '&id=' . $id);
    }

    private function submitDraft(Request $request, array $viewer): Response
    {
        $id = $this->positive($request->form('id'));
        if ($id === null) {
            return $this->requestUnavailable();
        }
        $version = $request->form('version');
        if (!TutoringInput::validVersion($version)) {
            return $this->problem('Review the draft', 'Open the saved draft to review its current details before submitting.', 422);
        }
        try {
            $this->tutoring->submit($viewer['id'], $id, $version);
            return $this->submitted($id);
        } catch (DomainException $e) {
            return $this->problem('Request could not be submitted', $e->getMessage(), $e->getCode());
        }
    }

    private function submitted(int $id): Response
    {
        $this->session->notice('Your tutoring request has been submitted.');
        return $this->redirect(self::REQUESTS . '&id=' . $id);
    }

    private function requestUnavailable(): Response
    {
        return $this->problem('Request unavailable', 'This tutoring request could not be found.', 404);
    }

    private function positive(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function screen(string $tab, array $viewer, array $profile, array $data = [], int $status = 200): Response
    {
        $data += ['tab' => $tab, 'tabs' => self::TABS, 'profile' => $profile, 'email' => $viewer['email'], 'roles' => $viewer['roles']];
        return $this->page('dashboard', $tab === 'overview' ? 'Parent dashboard' : self::TABS[$tab], $data, $status);
    }

    private function page(string $name, string $title, array $data, int $status = 200): Response
    {
        $data += ['csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(), 'signedIn' => true];
        return Response::html($this->view->render(__DIR__ . '/Views/' . $name . '.php', $title, $data), $status, self::HEADERS);
    }

    private function problem(string $title, string $message, int $status): Response
    {
        return Response::html($this->view->render(dirname(__DIR__) . '/Auth/Views/problem.php', $title, [
            'message' => $message, 'csrf' => $this->session->csrf(), 'signedIn' => true,
        ]), $status, self::HEADERS);
    }

    private function redirect(string $target): Response
    {
        return new Response('', 303, ['Location' => $target] + self::HEADERS);
    }
}
