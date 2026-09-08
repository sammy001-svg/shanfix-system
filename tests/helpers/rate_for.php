<?php
/**
 * The rate one partner earns on one thing, printed.
 *
 * The suite asserts that the page a partner reads and the ledger that
 * pays them resolve to the same number, so it has to ask the engine the
 * same question the engine asks itself.
 *
 *   rate_for.php <partnerId> <service|inventory> <refId>
 */
require getenv('SHANFIX_ROOT') . '/app/bootstrap.php';

App\Core\Config::load(CONFIG_PATH . '/config.php');
App\Core\Database::connect(App\Core\Config::get('db'));

[$script, $partnerId, $itemType, $refId] = $argv + [null, null, null, null];

$partnerId = (int) $partnerId;
$refId     = (int) $refId;

$default = (float) App\Core\Database::scalar(
    'SELECT default_rate FROM partners WHERE id = :p',
    ['p' => $partnerId],
    0
);

$serviceRate = $itemType === 'service'
    ? App\Core\Database::scalar(
        'SELECT commission_rate FROM services WHERE id = :s',
        ['s' => $refId]
      )
    : null;

echo (int) round(App\Services\Commission::rateFor(
    App\Services\Commission::overridesFor($partnerId),
    (string) $itemType,
    $refId,
    $serviceRate === null ? null : (float) $serviceRate,
    $default
));
