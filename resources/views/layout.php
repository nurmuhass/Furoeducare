<!doctype html>
<?php $initialAppearance = $data['accountAppearance'] ?? null; $initialAppearance = in_array($initialAppearance, ['light', 'dark', 'system'], true) ? $initialAppearance : 'system'; ?>
<html lang="en-NG" data-bs-theme="<?= $initialAppearance === 'dark' ? 'dark' : 'light' ?>"<?= ($data['signedIn'] ?? false) ? ' data-account-appearance="' . $initialAppearance . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($data['title']) ?> | <?= $escape($data['appName']) ?></title>
    <meta name="description" content="Education recruitment and learning services for teachers, schools and families in Nigeria.">
    <script src="/assets/theme.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.9/dist/css/bootstrap.min.css"
          integrity="sha384-yEPZ7jP9S0QKiUQ0m3jjsO4ProIW7wvR9gXkI7JOGa2XVsw53Yke2bsdkBbjmmiX" crossorigin="anonymous">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&amp;family=DM+Sans:wght@400..700&amp;display=swap">
    <link rel="stylesheet" href="/assets/app.css">
    <?php if ($data['dashboardWorkspace'] ?? false): ?>
        <script src="/assets/dashboard.js" defer></script>
    <?php endif; ?>
    <?php if ($data['adminWorkspace'] ?? false): ?>
        <link rel="stylesheet" href="/assets/admin.css">
        <?php if (!($data['dashboardWorkspace'] ?? false)): ?><script src="/assets/admin.js" defer></script><?php endif; ?>
    <?php endif; ?>
    <?php if ($data['teacherWorkspace'] ?? false): ?>
        <link rel="stylesheet" href="/assets/teacher-dashboard.css">
        <?php if (!($data['dashboardWorkspace'] ?? false)): ?><script src="/assets/teacher-dashboard.js" defer></script><?php endif; ?>
    <?php endif; ?>
    <?php if ($data['dashboardWorkspace'] ?? false): ?><link rel="stylesheet" href="/assets/dashboard.css"><?php endif; ?>
    <script src="/assets/public.js" defer></script>
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <?php if ($data['dashboardWorkspace'] ?? false): ?>
        <?php require $partials . '/dashboard/shell.php'; ?>
    <?php elseif ($data['adminWorkspace'] ?? false): ?>
        <?php require $partials . '/admin-shell.php'; ?>
    <?php elseif ($data['teacherWorkspace'] ?? false): ?>
        <?= $data['content'] ?>
    <?php else: ?>
        <?php require $partials . '/header.php'; ?>
        <main id="main" tabindex="-1">
            <?= $data['content'] ?>
        </main>
        <?php require $partials . '/footer.php'; ?>
    <?php endif; ?>
</body>
</html>
