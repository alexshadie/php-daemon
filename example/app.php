<?php
/*
 * ----------------------------------------------------------------------------
 * "THE BEER-WARE LICENSE" (Revision 42):
 * <alex@astra.ws> wrote this file.  As long as you retain this notice you
 * can do whatever you want with this stuff. If we meet some day, and you think
 * this stuff is worth it, you can buy me a beer in return.     Alex Tolmachyov
 * ----------------------------------------------------------------------------
 */
include __DIR__ . "/../vendor/autoload.php";
use PhpDaemon\Daemon\Daemon;

$daemon = new Daemon(function() {
    $limit = rand(2, 7);
    for ($i = 0; $i < $limit; $i++) {
        echo "$i ";
        sleep(1);
    }
}, 3);

$daemon->start();