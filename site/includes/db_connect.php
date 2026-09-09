<?php
/**
 * The website's own database.
 *
 * Separate from the system's: this one holds the blog, the portfolio,
 * the testimonials and the enquiries the site takes for itself.
 *
 * WHAT HAPPENS WHEN IT IS NOT THERE
 *
 * It used to call die() and print "System Maintenance", which took the
 * whole website down — every page, including the ones that barely touch
 * the database. The home page is the clearest case: it asks for hero
 * slides and banners, and it already falls back to a written-in set when
 * the query returns nothing. It was perfectly capable of rendering. The
 * connection killed it before it got the chance.
 *
 * So a failed connection no longer stops the site. $pdo becomes a stand-
 * in that throws a PDOException the moment anything asks it for data,
 * and every page here already wraps its queries in catch (Exception) —
 * so they take the same path they take for an empty table, and the site
 * stays up with its static content.
 *
 * The failure is not swallowed: it goes to the error log every time,
 * with which .env was read and which settings came out of it. A site
 * that is quietly running on fallbacks needs to say so somewhere.
 */

// env_loader.php reads .env as it is included, so the settings below are
// already in place by this line.
require_once __DIR__ . '/env_loader.php';

$host    = $_ENV['DB_HOST'] ?? 'localhost';
$db      = $_ENV['DB_NAME'] ?? 'shanfix_tech';
$user    = $_ENV['DB_USER'] ?? 'root';
$pass    = $_ENV['DB_PASS'] ?? '';
$port    = $_ENV['DB_PORT'] ?? '3306';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset;port=$port";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

if (!class_exists('UnavailableDatabase')) {
    /**
     * What $pdo is when there is no database.
     *
     * Anything asked of it throws a PDOException — deliberately that and
     * not an Error, because the pages catch Exception and an Error would
     * sail straight past them and take the page down again.
     */
    final class UnavailableDatabase
    {
        public function __construct(private readonly string $reason)
        {
        }

        public function __call(string $method, array $arguments): never
        {
            throw new PDOException('The website database is unavailable: ' . $this->reason);
        }

        public function reason(): string
        {
            return $this->reason;
        }
    }
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Say what actually happened, somewhere.
    //
    // Four quite different faults — no .env, a wrong password, a database
    // that does not exist, a user without rights — used to produce one
    // identical ninety-byte page and write nothing anywhere, which is why
    // telling them apart took days. The visitor sees the site; whoever is
    // fixing it reads the reason here. The password is never written,
    // only whether there is one.
    $envPath = __DIR__ . '/../.env';

    error_log(sprintf(
        'shanfix site: database unavailable — %s | .env: %s | host=%s port=%s db=%s user=%s password=%s',
        $e->getMessage(),
        is_file($envPath) ? 'read from ' . $envPath : 'NOT FOUND at ' . $envPath,
        $host,
        $port,
        $db,
        $user,
        $pass === '' ? 'none' : 'set'
    ));

    // Asked for the detail, give the detail — this is how somebody
    // diagnoses it in one page load rather than by guessing.
    if (($_ENV['DEBUG'] ?? 'false') === 'true') {
        die('Database Connection Failed: ' . $e->getMessage()
            . "\n\n.env: " . (is_file($envPath) ? 'found at ' . $envPath : 'NOT FOUND at ' . $envPath)
            . "\nhost=$host port=$port db=$db user=$user password="
            . ($pass === '' ? 'none' : 'set'));
    }

    // Otherwise the site carries on without it.
    $pdo = new UnavailableDatabase($e->getMessage());
}
