<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\InstallGate;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/logout.php', PHP_URL_PATH) ?: '/admin/logout.php';
InstallGate::enforce($path);

Auth::logout();
header('Location: /admin/login.php');
exit;
