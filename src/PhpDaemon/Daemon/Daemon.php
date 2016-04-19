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
     * @var bool
     */
    private $debug = false;
    /**
     * @var string
     */
    private $mode = 'daemon';

    /**
     * Daemon constructor.
     * @param string $job
     * @param array $jobInitParams
     * @param int $jobLimit
     * @param int $daemonId
     */
    public function __construct($job, $jobInitParams, $jobLimit, $daemonId) {
        global $STDIN, $STDOUT, $STDERR;
        $this->job = $job;
        $this->jobInitParams = $jobInitParams;
        $this->jobLimit = $jobLimit;
        $this->daemonId = $daemonId;

        $options = getopt('', ['debug', 'mode:']);
        $this->debug = isset($options['debug']) ? true : false;
        if (isset($options['mode']) && $options['mode'] == 'worker') {
            $this->mode = 'worker';
        } else {
            $this->mode = 'daemon';
        }
        pcntl_signal(SIGHUP, [$this, 'signalSighup']);
        pcntl_signal(SIGTERM, [$this, 'signalSigterm']);
        pcntl_signal(SIGINT, [$this, 'signalSigint']);
        pcntl_signal(SIGUSR1, [$this, 'signalSigusr1']);
        pcntl_signal(SIGUSR2, [$this, 'signalSigusr2']);

        ini_set('error_log', $this->getWorkDir() . 'error.log');
        if (!$this->debug) {
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            $STDIN = fopen('/dev/null', 'r');
            $STDOUT = fopen($this->getWorkDir() . 'stdout.log', 'ab');
            $STDERR = fopen($this->getWorkDir() . 'stderr.log', 'ab');
        }
    }

    protected function getPidFilename() {
        return $this->getWorkDir() . $this->daemonId . '.pid';
    }

    protected function getWorkDir() {
        $dir = '/tmp/' . $this->daemonId . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    protected function isActive() {
        $pidFile = $this->getPidFilename();
        if (is_file($pidFile)) {
            $file = fopen($pidFile, 'c');
            if (!$file) {
                return false;
            }
            $res = flock($file, LOCK_EX | LOCK_NB);
            if ($res) {
                flock($file, LOCK_UN);
                fclose($file);
            } else {
                fclose($file);

                $pid = file_get_contents($pidFile);

                if (posix_kill($pid, 0)) {
                    $this->log('Daemon already running', 'crit');
                    return true;
                }
            }
            if (!unlink($pidFile)) {
                $this->log('Cannot unlink pid-file', 'crit');
                exit(-1);
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

        $file = fopen($this->getPidFilename(), 'w');
        flock($file, LOCK_SH);
        fputs($file, getmypid());

        $stopCycle = false;

        if ($this->mode == 'worker') {
            $this->jobLimit = 1;
        }

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

            if ($this->mode == 'worker') {
                $this->stopped = true;
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

        flock($file, LOCK_UN);
        fclose($file);
        unlink($this->getPidFilename());

        $this->log("Daemon control thread exit", 'info');
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