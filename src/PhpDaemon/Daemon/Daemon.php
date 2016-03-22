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

use PhpDaemon\Job\Job;

class Daemon {
    /**
     * @var int
     */
    private $pid;
    /**
     * @var bool
     */
    private $stopped = false;
    /**
     * @var array
     */
    private $workers = [];
    /**
     * @var string
     */
    private $job;
    /**
     * @var int
     */
    private $jobLimit;
    /**
     *
     * @var string
     */
    private $pidFile;
    /**
     * Daemon constructor.
     * @param $job
     * @param int $jobLimit
     * @param string $pidFile
     */
    public function __construct($job, $jobLimit, $pidFile = null) {
        $this->job = $job;
        $this->jobLimit = $jobLimit;
        if (is_null($pidFile)) {
            $pidFile = str_replace(['.', '/'], '_', __FILE__);
        }
        $this->pidFile = $pidFile;

        pcntl_signal(SIGHUP, [$this, 'signalSighup']);
        pcntl_signal(SIGTERM, [$this, 'signalSigterm']);
        pcntl_signal(SIGINT, [$this, 'signalSigint']);
        pcntl_signal(SIGUSR1, [$this, 'signalSigusr1']);
        pcntl_signal(SIGUSR2, [$this, 'signalSigusr2']);
    }

    protected function isActive() {
        if (is_file($this->pidFile)) {
            $pid = file_get_contents($this->pidFile);

            if (posix_kill($pid, 0)) {
                $this->log('Daemon already running', 'crit');
                return true;
            } else {
                if (!unlink($this->pidFile)) {
                    $this->log('Cannot unlink pid-file', 'crit');
                    exit(-1);
                }
            }
        }
        return false;
    }

    public function start() {
        if ($this->isActive()) {
            exit(0);
        }
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
        file_put_contents($this->pidFile, getmypid());

        $stopCycle = false;

        while (!$stopCycle) {
            echo "\nTotal jobs: " . count($this->workers) . "\n";
            if (!$this->stopped && count($this->workers) < $this->jobLimit) {
                $pid = pcntl_fork();
                if ($pid == -1) {
                    // Cannot create child process
                } elseif ($pid) {
                    $this->workers[$pid] = true;
                } else {
                    $this->pid = getmypid();
                    $this->doJob();
                    exit;
                }
            } else {
                sleep(1);
            }

            while ($signalPid = pcntl_waitpid(-1, $status, WNOHANG)) {
                if ($signalPid == -1) {
                    $this->workers = [];
                    if ($this->stopped) {
                        $stopCycle = true;
                    }
                    break;
                } else {
                    unset ($this->workers[$signalPid]);
                }
            }

            pcntl_signal_dispatch();
        }

        $this->log("Daemon exit");
    }

    public function log($message, $severity) {
        echo "[$severity] $message\n";
    }

    public function run() {
        //
    }

    public function doJob() {
        $job = new $this->job($this);
        if (!$job instanceof Job) {
            throw new Exception("Invalid job");
        }
        $job->run();
    }

    // Signal handlers
    public function signalSighup() {
        $this->log("TODO: Reread config", "info");

    }

    public function signalSigusr1() {
        $this->log("Increasing thread count", "info");
        $this->jobLimit++;
    }

    public function signalSigusr2() {
        $this->log("Decreasing thread count", "info");
        if ($this->jobLimit) {
            $this->jobLimit--;
        }
    }

    public function signalSigterm() {
        $this->log("Catch sigterm, preparing to quit", "info");
        $this->stopped = true;
    }

    public function signalSigint() {
        $this->log("Catch ctrl+c, preparing to quit", "info");
        $this->stopped = true;
    }
}