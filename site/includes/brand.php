<?php
/**
 * Who we are, in one place.
 *
 * The phone number was written into eight files, the email into three
 * and the domain into thirty-one. Changing any of them meant finding
 * every copy, and the copies had already drifted apart — the website
 * said one number and the system said another, so a customer reading the
 * site and a customer reading an invoice were given different ones.
 *
 * The system holds the company's details, because that is where somebody
 * editing them would look. This reads them from there, and falls back to
 * what the website has always shown for anything the system leaves
 * blank — so an unset field never publishes an empty phone number, and
 * the site keeps working when the system is unreachable.
 *
 * The domain is separate and does not come from the system. It is a
 * property of this website, not of the company: the company also
 * answers at another address, and a canonical URL that changed with a
 * setting would quietly repoint every page on the site. It comes from
 * SITE_URL in .env instead, with the live domain as the default.
 */

// env_loader on its own account rather than through system.php,
// which does not load it: SITE_URL is read below and the system may
// not be reachable at all.
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/system.php';

/**
 * The company, as the public should see it.
 *
 * @return array{name:string, tagline:string, phone:string, phone_tel:string,
 *               email:string, address:string, street:string, city:string,
 *               country:string}
 */
function site_brand(): array
{
    static $brand = null;

    if ($brand !== null) {
        return $brand;
    }

    // What the website has always shown. These are the fallbacks, not the
    // truth: anything set in the system wins over them.
    $defaults = [
        'name'    => 'Shanfix Technology',
        'tagline' => 'Innovating Your Digital Future',
        'phone'   => '+254 751 869 165',
        'email'   => 'info@shanfixtechnology.com',
        'address' => 'Tana House, Karen - Nairobi',
        'street'  => 'Tana House, Karen',
        'city'    => 'Nairobi',
        'country' => 'KE',
    ];

    $brand = $defaults;

    try {
        if (system_ready() && class_exists(\App\Core\Settings::class)) {
            $company = \App\Core\Settings::company();

            foreach (['name', 'tagline', 'phone', 'email', 'address'] as $key) {
                $value = trim((string) ($company[$key] ?? ''));

                // Only a value that is actually set. An empty setting is
                // not an instruction to publish nothing.
                if ($value !== '') {
                    $brand[$key] = $value;
                }
            }

            // The structured data wants the address in parts and the
            // system keeps it as one free-text line, so it has to be
            // taken apart — but only where the line actually says how.
            //
            // "Tana House, Karen - Nairobi" splits cleanly on the dash.
            // "Nairobi, Kenya" does not: guessing that the part after the
            // comma is the town would publish "Kenya" as the town, which
            // is worse than publishing no town at all, because a wrong
            // locality is what a search engine shows a customer.
            //
            // So anything that does not split becomes the street on its
            // own, and the town is left out.
            if (str_contains($brand['address'], ' - ')) {
                [$street, $city] = array_map('trim', explode(' - ', $brand['address'], 2));
            } else {
                $street = trim($brand['address']);
                $city   = '';
            }

            $brand['street'] = $street !== '' ? $street : $brand['street'];
            $brand['city']   = $city;
        }
    } catch (\Throwable) {
        // The defaults stand. A marketing page is not worth failing over
        // a settings lookup.
    }

    // What a tel: link needs, which is not what a person should read.
    $brand['phone_tel'] = preg_replace('/[^0-9+]/', '', $brand['phone']) ?? '';

    return $brand;
}

/**
 * An absolute URL on this website.
 *
 * Used for canonicals, Open Graph images and the sitemap — every place
 * that has to name the site from the outside. One setting rather than
 * eighty-seven copies of the domain.
 */
function site_url(string $path = '/'): string
{
    static $base = null;

    if ($base === null) {
        // $_ENV is how the rest of the site reads its settings, and it
        // is what env_loader fills in from the file. getenv() as well,
        // because a value set in the real process environment only
        // reaches $_ENV when variables_order says E — and on a host where
        // it does not, the setting would look unset rather than fail.
        $base = trim((string) ($_ENV['SITE_URL'] ?? getenv('SITE_URL') ?: ''));
        $base = rtrim($base, '/');

        if ($base === '') {
            $base = 'https://shanfixtechnology.com';
        }
    }

    if ($path === '' || $path === '/') {
        return $base . '/';
    }

    return $base . '/' . ltrim($path, '/');
}
