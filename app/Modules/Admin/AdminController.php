<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Admin;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Furoeducare\Core\Request;
use Furoeducare\Core\HttpException;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;

final class AdminController
{
    public const ROOT = '/dashboard/admin';
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly StaffAccess $staffAccess,
        private readonly AdminReadModel $read,
        private readonly AdminManagement $management,
        private readonly OperationalReports $operationalReports,
    ) {
    }

    public function overview(Request $request): Response
    {
        return $this->authorized('overview.view', fn (array $viewer, array $permissions): Response => $this->page('overview', 'Administration overview', $viewer, $permissions, [
            'summary' => $this->read->overview($permissions), 'description' => 'Your permitted workflows, current counts and recent operational activity.',
        ]));
    }

    public function search(Request $request): Response
    {
        return $this->authorized('overview.view', function (array $viewer, array $permissions) use ($request): Response {
            $filters = self::filters($request, ['q']);
            return $this->listing('search', 'Search administration', $viewer, $permissions, $filters, ['q' => 'Search records'], $this->read->search($permissions, $filters['q'], self::before($request)), 'Results include only records within your current permissions.');
        });
    }

    public function accounts(Request $request): Response
    {
        $type = self::accountType($request->query('type'));
        return $this->authorized($type . 's.view', function (array $viewer, array $permissions) use ($request, $type): Response {
            $filters = self::filters($request, ['q', 'status']);
            return $this->listing('accounts', ucfirst($type) . ' accounts', $viewer, $permissions, $filters, ['q' => 'Name, email or phone', 'status' => 'Account access'], $this->read->accounts($type, $filters, self::before($request)), 'Review account details and access status.', ['type' => $type]);
        });
    }

    public function account(Request $request): Response
    {
        $type = self::accountType($request->query('type'));
        return $this->authorized($type . 's.view', function (array $viewer, array $permissions) use ($request, $type): Response {
            $account = $this->read->account(self::number($request->query('id')), $type) ?? throw new DomainException('This account is unavailable.', 404);
            self::assertAccountType($account, $type);
            return $this->page('account', 'Account details', $viewer, $permissions, ['account' => $account, 'type' => $type, 'activity' => in_array('audit.view', $permissions, true) ? $this->read->activity((int) $account['id']) : []]);
        });
    }

    public function accountAction(Request $request): Response
    {
        return $this->authorized('accounts.manage', function (array $viewer, array $permissions) use ($request): Response {
            $this->csrf($request);
            $id = self::number($request->form('id'));
            $type = self::accountType($request->form('type'));
            $this->staffAccess->require((int) $viewer['id'], $type . 's.view');
            $account = $this->read->account($id, $type) ?? throw new DomainException('This account is unavailable.', 404);
            self::assertAccountType($account, $type);
            $this->management->suspend((int) $viewer['id'], $id, self::revision($request, 'version'), $request->form('action'), $request->form('reason'), $request->form('confirmed') === '1');
            $this->session->notice('Account access updated.');
            return self::redirect(self::ROOT . '/accounts/view?type=' . $type . '&id=' . $id);
        });
    }

    public function cvRequests(Request $request): Response
    {
        return $this->authorized('cv_requests.view', function (array $viewer, array $permissions) use ($request): Response {
            $filters = self::filters($request, ['q', 'status']);
            return $this->listing('cv-requests', 'CV access requests', $viewer, $permissions, $filters, ['q' => 'Case reference', 'status' => 'Request status'], $this->read->cvRequests($filters, self::before($request)), 'Review access requests in the candidate workflow. Existing assignment and disclosure safeguards apply.');
        });
    }

    public function listings(Request $request): Response
    {
        return $this->authorized('directory.view', function (array $viewer, array $permissions) use ($request): Response {
            $filters = self::filters($request, ['q', 'status']);
            return $this->listing('listings', 'Public teacher listings', $viewer, $permissions, $filters, ['q' => 'Name or reference', 'status' => 'Listing status'], $this->read->listings($filters, self::before($request)), 'Listing decisions remain part of the directory and verification workflows.');
        });
    }

    public function email(Request $request): Response
    {
        return $this->authorized('email.view', function (array $viewer, array $permissions) use ($request): Response {
            $filters = self::filters($request, ['status', 'template']);
            return $this->listing('email', 'Email delivery monitoring', $viewer, $permissions, $filters, ['status' => 'Delivery status', 'template' => 'Template'], $this->read->email($filters, self::before($request)), 'Safe delivery metadata only. Accepted means the provider accepted the message; it does not establish delivery.');
        });
    }

    public function audit(Request $request): Response
    {
        return $this->authorized('audit.view', function (array $viewer, array $permissions) use ($request): Response {
            $filters = self::filters($request, ['actor', 'action']);
            return $this->listing('audit', 'Audit activity', $viewer, $permissions, $filters, ['actor' => 'Actor account ID', 'action' => 'Action'], $this->read->audit($filters, self::before($request)), 'Attributed operational events. Private audit context is excluded.');
        });
    }

    public function reports(Request $request): Response
    {
        return $this->authorized('reports.view', function (array $viewer, array $permissions) use ($request): Response {
            $period = self::reportPeriod($request);
            return $this->page('reports', 'Operational reports', $viewer, $permissions, [
                'from' => $period->from ?? '', 'to' => $period->to ?? '', 'reportQuery' => $period->query(),
                'snapshot' => $this->operationalReports->snapshot($viewer['id'], $period),
                'description' => 'Real operational counts for your permitted modules. All services offered at launch remain free.',
            ]);
        });
    }

    public function exportReports(Request $request): Response
    {
        return $this->authorized('reports.view', function (array $viewer) use ($request): Response {
            $period = self::reportPeriod($request);
            $snapshot = $this->operationalReports->snapshot($viewer['id'], $period, $request->method === 'GET');
            $filename = 'furoeducare-report-' . ($period->from ?? 'all-time') . ($period->to === null ? '' : '-to-' . $period->to) . '.csv';
            return new Response(ReportCsv::encode($snapshot), 200, self::HEADERS + [
                'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        });
    }

    private static function reportPeriod(Request $request): ReportPeriod
    {
        foreach (['period', 'from', 'to'] as $key) {
            if (!$request->queryIsString($key)) throw new HttpException(422, 'Report date filters must be single values.');
        }
        $selection = $request->query('period');
        $from = $request->query('from'); $to = $request->query('to');
        if ($selection === 'all' && $from === '' && $to === '') return ReportPeriod::all();
        if ($selection !== '') throw new HttpException(422, 'Choose either all time or a date range.');
        if ($from === '' && $to === '') {
            $today = new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
            $from = $today->modify('first day of this month')->format('Y-m-d'); $to = $today->format('Y-m-d');
        }
        return ReportPeriod::between($from, $to);
    }

    public function staff(Request $request): Response
    {
        return $this->authorized($request->method === 'POST' ? 'staff.manage' : 'staff.view', function (array $viewer, array $permissions) use ($request): Response {
            if ($request->method === 'POST') {
                $this->csrf($request);
                $action = $request->form('action') ?: 'assign';
                $actor = (int) $viewer['id'];
                $target = self::number($request->form('target_id'));
                $version = self::revision($request, 'version');
                $confirmed = $request->form('confirmed') === '1';
                if ($action === 'assign') {
                    $this->management->assign($actor, $target, $version, $request->formArray('role_ids'), $request->form('reason'), $confirmed);
                    $this->session->notice('Staff assignments updated.');
                } elseif (in_array($action, ['suspend', 'restore'], true)) {
                    $this->staffAccess->require($actor, 'accounts.manage');
                    $this->management->suspend($actor, $target, $version, $action, $request->form('reason'), $confirmed);
                    $this->session->notice('Staff account access updated.');
                } else {
                    throw new DomainException('Choose an available staff action.', 422);
                }
                return self::redirect(self::ROOT . '/staff');
            }
            $filters = self::filters($request, ['q']);
            return $this->listing('staff', 'Staff access', $viewer, $permissions, $filters, ['q' => 'Account email'], $this->management->staff(self::before($request), $filters['q'], in_array('staff.manage', $permissions, true)), 'Assign explicit staff roles. Changes are checked for delegation and protected administrator access.', ['roles' => $this->management->roles()]);
        });
    }

    public function roles(Request $request): Response
    {
        return $this->authorized($request->method === 'POST' ? 'staff.manage' : 'staff.view', function (array $viewer, array $permissions) use ($request): Response {
            if ($request->method === 'POST') {
                $this->csrf($request);
                self::action($request, 'save');
                $id = $request->form('id') === '' ? null : self::number($request->form('id'));
                $this->management->saveRole((int) $viewer['id'], $id, $id === null ? null : self::revision($request), $request->form('name'), $request->formArray('permissions'), $request->form('reason'), $request->form('confirmed') === '1');
                $this->session->notice('Staff role saved.');
                return self::redirect(self::ROOT . '/roles');
            }
            return $this->page('roles', 'Staff roles and permissions', $viewer, $permissions, ['roles' => $this->management->roles(), 'permissionChoices' => array_values(array_intersect(array_keys(StaffPermissions::ALL), $permissions))]);
        });
    }

    public function settings(Request $request): Response
    {
        return $this->authorized($request->method === 'POST' ? 'settings.manage' : 'settings.view', function (array $viewer, array $permissions) use ($request): Response {
            if ($request->method === 'POST') {
                $this->csrf($request);
                $unsupported = array_diff($request->formKeys(), ['_csrf', 'revision', 'admin_notice', 'support_email', 'reason', 'confirmed', 'action']);
                if ($unsupported !== []) {
                    throw new DomainException('Only the staff notice and support email can be changed here.', 422);
                }
                $this->management->saveSettings((int) $viewer['id'], ['admin_notice' => $request->form('admin_notice'), 'support_email' => $request->form('support_email')], self::revision($request), $request->form('reason'), $request->form('confirmed') === '1');
                $this->session->notice('Operational settings saved.');
                return self::redirect(self::ROOT . '/settings');
            }
            return $this->page('settings', 'Operational settings', $viewer, $permissions, ['settings' => $this->management->settings()]);
        });
    }

    private function authorized(string $permission, Closure $operation): Response
    {
        $viewer = $this->access->viewer();
        if ($viewer === null) return self::redirect('/login');
        $permissions = [];
        try {
            $this->staffAccess->require((int) $viewer['id'], $permission);
            $permissions = $this->staffAccess->permissions((int) $viewer['id']);
            return $operation($viewer, $permissions);
        } catch (DomainException | HttpException $error) {
            $status = $error instanceof HttpException ? $error->status : $error->getCode();
            if (!in_array($status, [403, 404, 409, 422], true)) throw $error;
            return $this->page('problem', match ($status) { 403 => 'Access denied', 404 => 'Record unavailable', 409 => 'Update conflict', default => 'Check the submitted details' }, $viewer, $permissions, ['message' => $error->getMessage()], $status);
        }
    }

    private function listing(string $page, string $title, array $viewer, array $permissions, array $filters, array $labels, array $rows, string $description, array $extra = []): Response
    {
        return $this->page($page, $title, $viewer, $permissions, $extra + ['rows' => $rows, 'filters' => $filters, 'filterLabels' => $labels, 'listPath' => self::ROOT . '/' . $page, 'description' => $description]);
    }

    private function page(string $page, string $title, array $viewer, array $permissions, array $extra = [], int $status = 200): Response
    {
        return Response::html($this->view->render(__DIR__ . '/Views/workspace.php', $title, $extra + [
            'page' => $page, 'viewer' => $viewer, 'permissions' => $permissions, 'signedIn' => true,
            'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
            'wat' => static fn (string $timestamp): string => (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Lagos'))->format('d M Y, H:i'),
        ]), $status, self::HEADERS);
    }

    private function csrf(Request $request): void
    {
        if (!$this->session->acceptsCsrf($request->form('_csrf'))) throw new DomainException('Reopen the page and try again.', 403);
    }

    private static function filters(Request $request, array $names): array
    {
        $filters = [];
        foreach ($names as $name) $filters[$name] = trim($request->query($name));
        return $filters;
    }

    private static function accountType(string $type): string
    {
        return in_array($type, ['teacher', 'school', 'parent'], true) ? $type : 'teacher';
    }

    private static function assertAccountType(array $account, string $type): void
    {
        if (!in_array($type, $account['roles'] ?? [], true)) throw new DomainException('This account is unavailable in this directory.', 404);
    }

    private static function number(string $raw): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? throw new DomainException('Choose an available record.', 404) : $value;
    }

    private static function revision(Request $request, string $name = 'revision'): int
    {
        $value = filter_var($request->form($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $value === false ? throw new DomainException('Reopen the record to get its current revision.', 409) : $value;
    }

    private static function before(Request $request): int
    {
        return $request->query('before') === '' ? PHP_INT_MAX : self::number($request->query('before'));
    }

    private static function action(Request $request, string $expected): void
    {
        if ($request->form('action') !== $expected) throw new DomainException('Choose an available management action.', 422);
    }

    private static function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path] + self::HEADERS);
    }
}
