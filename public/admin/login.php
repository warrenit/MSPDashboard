<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
$ipMiddleware->enforce();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($auth->attempt($username, $password)) {
        header('Location: /admin/settings.php');
        exit;
    }

    $error = 'Invalid credentials';
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login</title>
<style>body{font-family:Arial,sans-serif;max-width:420px;margin:40px auto}label{display:block;margin:10px 0 5px}input{width:100%;padding:8px}button{margin-top:15px;padding:10px 14px}</style>
</head>
<body>
<h1>Admin Login</h1>
<?php if ($error): ?><p style="color:#b91c1c"><?= htmlspecialchars($error, ENT_QUOTES) ?></p><?php endif; ?>
<form method="post">
    <label>Username<input name="username" required></label>
    <label>Password<input type="password" name="password" required></label>
    <button type="submit">Sign in</button>
</form>
</body>
</html>
