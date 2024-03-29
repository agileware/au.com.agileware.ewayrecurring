<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInite3e0b2b852364e5aafd9a67d9b0c9c63
{
    public static $prefixLengthsPsr4 = array (
        'E' => 
        array (
            'Eway\\' => 5,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Eway\\' => 
        array (
            0 => __DIR__ . '/..' . '/eway/eway-rapid-php/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInite3e0b2b852364e5aafd9a67d9b0c9c63::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInite3e0b2b852364e5aafd9a67d9b0c9c63::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInite3e0b2b852364e5aafd9a67d9b0c9c63::$classMap;

        }, null, ClassLoader::class);
    }
}
