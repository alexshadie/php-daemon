<?php
/*
 * ----------------------------------------------------------------------------
 * "THE BEER-WARE LICENSE" (Revision 42):
 * <alex@astra.ws> wrote this file.  As long as you retain this notice you
 * can do whatever you want with this stuff. If we meet some day, and you think
 * this stuff is worth it, you can buy me a beer in return.     Alex Tolmachyov
 * ----------------------------------------------------------------------------
 */

namespace PhpDaemon\Daemon;


class Daemon {
    private $pid;
    private $stopped = false;
    private $workers = [];
    private $job;
    private $jobLimit;

    /**
     * Daemon constructor.
     * @param callable $job
     */
    public function __construct($job, $jobLimit) {
        $this->job = $job;
        $this->jobLimit = $jobLimit;
    }

    public function start() {
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new DaemonException("Fork failed");
            exit(1);
        }
        if ($pid) {
            // Master process
            exit(0);
        }
        posix_setsid();

        while (!$this->stopped) {
            if (!$this->stopped && count($this->workers) < $this->jobLimit) {
                $pid = pcntl_fork();
                if ($pid == -1) {
                    // Cannot create child process
                } elseif ($pid) {
                    $this->workers[$pid] = true;
                } else {
                    $this->pid = getmypid();
                    $this->doJob();
                    echo "Exiting";
                    exit;
                }
            } else {
                sleep(1);
            }

            while ($signalPid = pcntl_waitpid(-1, $status, WNOHANG)) {
                if ($signalPid == -1) {
                    $this->workers = [];
                    break;
                } else {
                    unset ($this->workers[$signalPid]);
                }
            }
        }
    }

    public function log($message, $severity) {
        echo "[$severity] $message";
    }

    public function run() {
        //
    }

    public function doJob() {
        call_user_func($this->job);
    }
}