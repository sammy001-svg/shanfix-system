<?php
/**
 * Every event name the code dispatches that Notifier::EVENTS has never
 * heard of.
 *
 * An unregistered event is not an error anywhere: it sends an email with
 * the raw event name as the subject and an empty body, and no SMS at all,
 * because queueSms refuses an event with no template. Printing them is
 * the only way anybody finds out.
 */
require getenv('SHANFIX_ROOT') . '/app/bootstrap.php';

$root  = getenv('SHANFIX_ROOT');
$found = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    preg_match_all(
        '/Notifier::dispatch\(\s*[\'"]([a-z0-9_]+)[\'"]/i',
        (string) file_get_contents($file->getPathname()),
        $matches
    );

    foreach ($matches[1] as $event) {
        $found[$event] = true;
    }
}

echo implode(',', array_diff(array_keys($found), array_keys(App\Services\Notifier::EVENTS)));
