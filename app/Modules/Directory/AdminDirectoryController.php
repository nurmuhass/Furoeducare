<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Directory;

use Furoeducare\Core\Request;
use Furoeducare\Core\Response;
use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\AuthAccess;
use Furoeducare\Modules\Auth\AuthSession;
use Furoeducare\Modules\Auth\Role;
use InvalidArgumentException;

final class AdminDirectoryController
{
    public const ROOT = '/dashboard/admin/directory';
    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly View $view,
        private readonly AuthSession $session,
        private readonly AuthAccess $access,
        private readonly DirectoryRepository $directory,
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

        $submit = $request->method === 'POST';
        if ($submit && !$this->session->acceptsCsrf($request->form('_csrf'))) {
            return $this->problem('Form expired', 'Reopen the directory editor and try again.', 403);
        }
        $id = filter_var($submit ? $request->form('id') : $request->query('id'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        $teacher = $id === false ? null : $this->directory->adminTeacher($id);
        if ($teacher === null) {
            return $this->problem('Teacher unavailable', 'This teacher profile could not be found.', 404);
        }

        $listing = $teacher['listing'];
        $selfReview = $viewer['id'] === $id;
        $draft = $listing ?? [];
        $draft['subjects'] = implode(', ', $listing['subjects'] ?? []);
        $draft['levels'] = implode(', ', $listing['levels'] ?? []);
        $draft['availability'] = array_keys(array_filter(DirectoryFilters::MODES,
            static fn (int $bit): bool => ((int) ($listing['availability_mask'] ?? 0) & $bit) !== 0));
        $error = '';

        if ($submit) {
            if ($selfReview) {
                return $this->problem('Access denied', 'Another administrator must manage this listing.', 403);
            }
            $operation = $request->form('operation');
            $revision = filter_var($request->form('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($revision === false || $revision !== (int) ($listing['revision'] ?? 0)) {
                return $this->problem('Listing changed', 'Reopen the directory editor and try again.', 409);
            }
            try {
                if ($operation === 'withdraw') {
                    $result = $this->directory->withdraw($viewer['id'], $id, $revision);
                } elseif ($operation === 'publish') {
                    $draft = ListingInput::draft($request);
                    $fields = ListingInput::validate($draft, $teacher);
                    $jpeg = ListingPhoto::receive($request->uploadedFile('photo'), $fields['photo_authorised']);
                    $result = $this->directory->publish($viewer['id'], $id, $revision, $fields, $jpeg);
                } else {
                    throw new InvalidArgumentException('Choose whether to publish or withdraw this listing.');
                }

                if ($result === 'saved') {
                    $this->session->notice($operation === 'publish' ? 'Directory listing published.' : 'Directory listing withdrawn.');
                    return $this->redirect(self::ROOT . '?id=' . $id);
                }
                if ($result === 'stale') {
                    return $this->problem('Listing changed', 'Reopen the directory editor to see the current listing.', 409);
                }
                if ($result === 'missing') {
                    return $this->problem('Teacher unavailable', 'This teacher profile could not be found.', 404);
                }
                if ($result === 'forbidden') {
                    return $this->problem('Listing action unavailable', 'Publishing requires an active teacher account with a verified email.', 403);
                }
                $error = $result === 'photo' ? 'Upload the authorised photo or clear its authorisation checkbox.'
                    : 'This listing action is unavailable. Reopen the directory editor and try again.';
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        $html = $this->view->render(__DIR__ . '/Views/admin.php', 'Manage public teacher listing', [
            'signedIn' => true, 'csrf' => $this->session->csrf(), 'notice' => $this->session->takeNotice(),
            'teacher' => $teacher, 'draft' => $draft, 'error' => $error, 'selfReview' => $selfReview,
        ]);
        return Response::html($html, $error === '' ? 200 : 422, self::HEADERS);
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
