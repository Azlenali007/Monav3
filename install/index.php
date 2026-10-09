<?php
/**
 * Mona SMM Panel v2 - Installation Wizard
 */

declare(strict_types=1);

if (file_exists(__DIR__ . '/installed.lock')) {
    header('Location: /index.php');
    exit;
}

$pageTitle = "Installation Wizard | Mona SMM Panel";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl space-y-6">
        <div class="text-center">
            <h1 class="text-2xl font-bold text-white">Mona SMM Panel v2 Setup</h1>
            <p class="text-slate-400 text-sm mt-1">System is locked. To re-install, manually remove <code>install/installed.lock</code>.</p>
        </div>
        <a href="/index.php" class="block w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 rounded-xl text-center transition">
            Go to Application
        </a>
    </div>
</body>
</html>
