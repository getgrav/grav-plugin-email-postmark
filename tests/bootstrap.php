<?php

declare(strict_types=1);

/**
 * The plugin ships its own vendor directory, and that directory holds nothing
 * but Composer's autoloader and the Symfony Postmark bridge. Installing PHPUnit
 * into it would put development packages into the released plugin, so the suite
 * keeps its own composer.json and its own vendor directory here under tests/.
 *
 * Run `composer install -d tests` once, then `phpunit` from the repository root.
 */
$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "The test dependencies are not installed. Run: composer install -d tests\n");
    exit(1);
}

require $autoload;

/**
 * The provider contract lives in the Email plugin, which is a sibling checkout
 * rather than a Composer package — Grav plugins are installed side by side and
 * none of them requires another through Composer.
 *
 * `EMAIL_PLUGIN_ROOT` says where it is; the sibling folder is the default,
 * which is what it is in a normal checkout and in a Grav install alike. A
 * worktree of the Email plugin is pointed at by setting the variable:
 *
 *     EMAIL_PLUGIN_ROOT=/path/to/grav-plugin-email tests/vendor/bin/phpunit
 */
$emailRoot = getenv('EMAIL_PLUGIN_ROOT');
$candidates = \is_string($emailRoot) && trim($emailRoot) !== ''
    ? [rtrim(trim($emailRoot), '/')]
    : [
        // A checkout beside this one, which is where it is in a normal
        // workspace.
        \dirname(__DIR__, 2) . '/grav-plugin-email',
        // A Grav install, where plugins are named without the prefix.
        \dirname(__DIR__, 2) . '/email',
        // A git worktree of this plugin, kept in a folder of its own beside the
        // checkouts rather than among them.
        \dirname(__DIR__, 3) . '/grav-plugin-email',
    ];

$contract = null;
foreach ($candidates as $candidate) {
    if (is_dir($candidate . '/classes/Providers')) {
        $emailRoot = $candidate;
        $contract = $candidate . '/classes/Providers';
        break;
    }
}

if ($contract === null) {
    fwrite(STDERR, sprintf(
        "The Email plugin's provider contract was not found. Looked in:\n  %s\n"
        . "Point EMAIL_PLUGIN_ROOT at a checkout of grav-plugin-email whose develop carries classes/Providers/.\n",
        implode("\n  ", $candidates)
    ));
    exit(1);
}

spl_autoload_register(static function (string $class) use ($contract): void {
    $prefix = 'Grav\\Plugin\\Email\\Providers\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $contract . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

if (!interface_exists(\Grav\Plugin\Email\Providers\Provider::class)) {
    fwrite(STDERR, "The Email plugin at {$emailRoot} has no provider contract on it. Update it to 5.0.9 or later.\n");
    exit(1);
}
