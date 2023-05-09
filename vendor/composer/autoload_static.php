<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitc8f6c778af94d0e16a98284ad23d7aed
{
    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Symfony\\Component\\Mailer\\Bridge\\Postmark\\' => 41,
        ),
        'G' => 
        array (
            'Grav\\Plugin\\EmailPostmark\\' => 26,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Symfony\\Component\\Mailer\\Bridge\\Postmark\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/postmark-mailer',
        ),
        'Grav\\Plugin\\EmailPostmark\\' => 
        array (
            0 => __DIR__ . '/../..' . '/classes',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'Grav\\Plugin\\EmailPostmarkPlugin' => __DIR__ . '/../..' . '/email-postmark.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitc8f6c778af94d0e16a98284ad23d7aed::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitc8f6c778af94d0e16a98284ad23d7aed::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitc8f6c778af94d0e16a98284ad23d7aed::$classMap;

        }, null, ClassLoader::class);
    }
}
