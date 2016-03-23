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
    private $daemonId;
    /**
     * @var array
     */
    private $jobInitParams = [];
    /**
     * @var int
     */
    private $sleepTime = 1;

    /**
     * Daemon constructor.
     * @param $job
     * @param int $jobLimit
     * @param string $daemonId
     */
    public function __construct($job, $jobInitParams, $jobLimit, $daemonId) {
        global $STDIN, $STDOUT, $STDERR;
        $this->job = $job;
        $this->jobInitParams = $jobInitParams;
        $this->jobLimit = $jobLimit;
        $this->daemonId = $daemonId;

        pcntl_signal(SIGHUP, [$this, 'signalSighup']);
        pcntl_signal(SIGTERM, [$this, 'signalSigterm']);
        pcntl_signal(SIGINT, [$this, 'signalSigint']);
        pcntl_signal(SIGUSR1, [$this, 'signalSigusr1']);
        pcntl_signal(SIGUSR2, [$this, 'signalSigusr2']);

        ini_set('error_log', $this->getWorkDir() .'/' . $this->daemonId . '.error.log');
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen($this->getWorkDir() .'/' . $this->daemonId . '.log', 'ab');
        $STDERR = fopen($this->getWorkDir() .'/' . $this->daemonId . '.stderr.log', 'ab');
    }

    protected function getPidFilename() {
        return '/tmp/' . $this->daemonId . '.pid';
    }

    protected function getWorkDir() {
        return '/tmp/';
    }

    protected function isActive() {
        $pidFile = $this->getPidFilename();
        if (is_file($pidFile)) {
            $pid = file_get_contents($pidFile);

            if (posix_kill($pid, 0)) {
                $this->log('Daemon already running', 'crit');
                return true;
            } else {
                if (!unlink($pidFile)) {
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
        }
        if ($pid) {
            // Master process
            exit(0);
        }
        posix_setsid();

        file_put_contents($this->getPidFilename(), getmypid());

        $stopCycle = false;

        while (!$stopCycle) {
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
                sleep($this->sleepTime);
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

        $this->log("Daemon exit", 'info');
    }

    public function log($message, $severity) {
        echo "[$severity] $message\n";
    }

    public function run() {
        //
    }

    public function doJob() {
        $job = new $this->job($this, $this->jobInitParams);
        if (!$job instanceof Job) {
            throw new \Exception("Invalid job");
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