<?php

// Fix Vercel request path for Laravel
$uri = $_SERVER['REQUEST_URI'] ?? '/';

// Ensure proper server variables for Laravel
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/index.php';

require __DIR__ . '/../public/index.php';
