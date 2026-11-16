<?php
declare(strict_types=1);
// Check-in Locations moved to the Facilities page (forms/facilities), which keeps every location and adds facility
// details. Kept so old links and bookmarks still work.
require_once dirname(__DIR__) . '/includes/layout.php';
require_login();
header('Location: ' . app_url('/forms/facilities/index.php'), true, 302);
exit;
