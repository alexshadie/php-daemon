<?php
/*
 * ----------------------------------------------------------------------------
 * "THE BEER-WARE LICENSE" (Revision 42):
 * <alex@astra.ws> wrote this file.  As long as you retain this notice you
 * can do whatever you want with this stuff. If we meet some day, and you think
 * this stuff is worth it, you can buy me a beer in return.     Alex Tolmachyov
 * ----------------------------------------------------------------------------
 */

namespace PhpDaemon\Job;

use PhpDaemon\Daemon\Daemon;

abstract class Job {
    protected $daemon;

    public function __construct(Daemon $daemon) {
        $this->daemon = $daemon;
    }

    abstract public function run();
}