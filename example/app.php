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
use PhpDaemon\Job\DaemonJob;

class MyLogger extends \Psr\Log\AbstractLogger
{
    public function log($level, $message, array $context = array())
    {
        echo " * " . date('Y-m-d H:i:s') . "\t[{$level}]\t{$message}\n";
        if ($context) {
            var_dump($context);
        }
    }

}

class MyDaemonJob extends DaemonJob {
    public function run() {
        $limit = rand(2, 7);
        for ($i = 0; $i < $limit; $i++) {
            echo "$i ";
            sleep(1);
        }
    }
}

$daemon = new Daemon(
    MyDaemonJob::class,
    [],
    3,
    'example-app',
    '/tmp/example-app/',
    new MyLogger()
);

$daemon->start();