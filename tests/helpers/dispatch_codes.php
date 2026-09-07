<?php
/**
 * Queue a verification code by both channels, for the suite to read back.
 *
 * A tiny file rather than a php -r one-liner: the escaping needed to get
 * namespaces and quotes through the shell is where these stop being
 * readable and start being guesswork.
 */
require getenv('SHANFIX_ROOT') . '/app/bootstrap.php';

App\Core\Config::load(CONFIG_PATH . '/config.php');
App\Core\Database::connect(App\Core\Config::get('db'));

foreach (['client_otp' => '123456', 'partner_code' => '654321'] as $event => $code) {
    App\Services\Notifier::dispatch($event, [
        'entity_type'  => 'client',
        'entity_id'    => 1,
        'contact_name' => 'Code Probe',
        'email'        => 'codeprobe@example.test',
        'phone'        => '0712345678',
        'code'         => $code,
        'minutes'      => 10,
    ], true);
}
