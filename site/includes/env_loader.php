<?php
/**
 * Reading the .env file beside this site.
 *
 * It is loaded once, at the bottom of this file, so anything that
 * requires it has the settings already. db_connect.php relies on that.
 *
 * The parsing is deliberately forgiving, because the file is written by
 * hand on a live server and the ways it goes wrong are not obvious from
 * the outside. Every one of these produced the same blank "System
 * Maintenance" page, with nothing to say which:
 *
 *   DB_USER="root"     quoted, the way almost everybody writes a .env.
 *                      The quotes became part of the value, so MySQL was
 *                      asked for a user called "root" — with the quote
 *                      marks in the name — and refused it.
 *
 *   export DB_NAME=x   pasted from shell notes. The key became
 *                      "export DB_NAME" and nothing read it.
 *
 *   DB_PASS=           an empty value written as "" rather than left
 *                      blank, which is a two-character password.
 *
 *   a line with no =   raised a warning and left a null in the settings.
 *
 * None of that is the person's fault. A .env parser that only accepts
 * one exact spelling is a parser that will be fed another one.
 */

if (!function_exists('loadEnv')) {
    /**
     * @param  string $path the .env file
     * @return bool   whether there was one to read
     */
    function loadEnv($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Comments and anything that is not a setting at all.
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            // "export FOO=bar", as copied out of shell notes.
            $name = trim(preg_replace('/^\s*export\s+/i', '', $name));
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            // Strip one matching pair of surrounding quotes. Only a
            // matching pair, and only the outermost, so a password that
            // genuinely contains a quote survives intact.
            $length = strlen($value);

            if ($length >= 2) {
                $first = $value[0];
                $last  = $value[$length - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Anything already set in the real environment wins: on a
            // server that sets these properly, the file is a fallback.
            if (array_key_exists($name, $_SERVER) || array_key_exists($name, $_ENV)) {
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }

        return true;
    }
}

// Load from the folder above this one, which is where .env belongs.
loadEnv(__DIR__ . '/../.env');
