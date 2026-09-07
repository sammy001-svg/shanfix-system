<?php
/**
 * Registered events with nothing to say by text.
 *
 * queueSms refuses rather than inventing wording, so an event with no
 * template silently sends nothing at all on the channel most of our
 * clients actually read.
 */
require getenv('SHANFIX_ROOT') . '/app/bootstrap.php';

App\Core\Config::load(CONFIG_PATH . '/config.php');
App\Core\Database::connect(App\Core\Config::get('db'));

$missing = [];

foreach (array_keys(App\Services\Notifier::EVENTS) as $event) {
    if (trim((string) App\Core\Settings::get('tpl_sms_' . $event, '')) === '') {
        $missing[] = $event;
    }
}

echo implode(',', $missing);
