<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
$ipMiddleware->enforce();
$auth->logout();
header('Location: /admin/login.php');
exit;
