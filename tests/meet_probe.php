<?php
require __DIR__ . "/../app/bootstrap.php";
App\Core\Config::load(CONFIG_PATH . '/config.php');
App\Core\Database::connect(App\Core\Config::get('db'));
date_default_timezone_set('Africa/Nairobi');

$r1 = App\Services\Notifier::queueMeetingReminders();
printf("    first run : queued=%d checked=%d\n", $r1['queued'], $r1['checked']);
$r2 = App\Services\Notifier::queueMeetingReminders();
printf("    second run: queued=%d (0 = nobody told twice)\n", $r2['queued']);
