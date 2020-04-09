<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit77a651ecd931ac73fc4cae1b51bf3c95
{
    public static $prefixLengthsPsr4 = array (
        'V' => 
        array (
            'Vendidero\\Germanized\\DHL\\' => 25,
        ),
        'A' => 
        array (
            'Automattic\\Jetpack\\Autoloader\\' => 30,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Vendidero\\Germanized\\DHL\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
        'Automattic\\Jetpack\\Autoloader\\' => 
        array (
            0 => __DIR__ . '/..' . '/automattic/jetpack-autoloader/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit77a651ecd931ac73fc4cae1b51bf3c95::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit77a651ecd931ac73fc4cae1b51bf3c95::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}