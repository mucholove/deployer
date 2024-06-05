<?php

function findRootLevel()
{
    $dir = __DIR__;
    while (!file_exists($dir . '/vendor/autoload.php')) {
        $dir = dirname($dir);
        if ($dir === '/') {
            throw new Exception('Failed to find autoload.php. Run Composer install.');
        }
    }
    return $dir;
}

function findAutoloadFile() {
    $rootLevel = findRootLevel();
    return $rootLevel.'/vendor/autoload.php';
}
