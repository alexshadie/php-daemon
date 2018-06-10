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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class DaemonJob implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $daemon;

    public function __construct(Daemon $daemon, ?LoggerInterface $logger)
    {
        $this->daemon = $daemon;
        $this->setLogger($logger ?? new NullLogger());
    }

    abstract public function run();
}