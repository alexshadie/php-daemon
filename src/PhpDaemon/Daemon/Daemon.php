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

use PhpDaemon\Job\DaemonJob;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Daemon implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const MODE_DAEMON = 'daemon';
    const MODE_WORKER = 'worker';
    /** @var int */
    private $pid;
    /** @var bool */
    private $stopped = false;
    /** @var array */
    private $workers = [];
    /** @var string */
    private $jobClass;
    /** @var int */
    private $jobLimit;
    /** @var array */
    private $jobInitParams = [];
    /**@var string */
    private $daemonId;
    /** @var string */
    private $daemonWorkDir;
    /** @var int */
    private $sleepTime = 1;
    /** @var bool */
    private $debug = false;
    /** @var string */
    private $mode = 'daemon';

    /**
     * Daemon constructor.
     * @param string $jobClass
     * @param array $jobInitParams
     * @param int $jobLimit
     * @param string $daemonId
     * @param string $daemonWorkDir
     * @param null|LoggerInterface $logger
     * @throws DaemonException
     */
    public function __construct(
        string $jobClass,
        array $jobInitParams,
        int $jobLimit,
        string $daemonId,
        string $daemonWorkDir,
        ?LoggerInterface $logger
    )
    {
        global $STDIN, $STDOUT, $STDERR;

        $this->setLogger($logger ?? new NullLogger());

        if (!is_subclass_of($jobClass, DaemonJob::class)) {
            $this->logger->critical("Passed job class '{$jobClass}' is not subclass of job");
            throw new DaemonException("Invalid Job class");
        }
        if (!preg_match('!^[a-z0-9_\-]+$!i', $daemonId)) {
            $this->logger->emergency("Invalid daemon_id, must contain only alphanumeric, hyphen or underscore");
            throw new DaemonException("Invalid daemon id");
        }

        $this->jobClass = $jobClass;
        $this->jobInitParams = $jobInitParams;
        $this->jobLimit = $jobLimit;
        $this->daemonId = $daemonId;
        $this->daemonWorkDir = rtrim($daemonWorkDir, '/') . '/';

        if (!is_dir($this->daemonWorkDir)) {
            mkdir($this->daemonWorkDir, 0755, true);
        }

        $options = getopt('', ['debug', 'mode:']);
        $this->debug = isset($options['debug']) ? true : false;
        if (isset($options['mode']) && $options['mode'] == 'worker') {
            $this->mode = self::MODE_WORKER;
        } else {
            $this->mode = self::MODE_DAEMON;
        }

        pcntl_signal(SIGHUP, [$this, 'signalSighup']);
        pcntl_signal(SIGTERM, [$this, 'signalSigterm']);
        pcntl_signal(SIGINT, [$this, 'signalSigint']);
        pcntl_signal(SIGUSR1, [$this, 'signalSigusr1']);
        pcntl_signal(SIGUSR2, [$this, 'signalSigusr2']);

        ini_set('error_log', $this->daemonWorkDir . 'error.log');
        if (!$this->debug) {
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            $STDIN = fopen('/dev/null', 'r');
            $STDOUT = fopen($this->daemonWorkDir . 'stdout.log', 'ab');
            $STDERR = fopen($this->daemonWorkDir . 'stderr.log', 'ab');
        }
    }

    protected function getPidFilename()
    {
        return $this->daemonWorkDir . $this->daemonId . '.pid';
    }

    protected function isActive()
    {
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
                    $this->logger->emergency('Daemon already running');
                    return true;
                }
            }
            if (!unlink($pidFile)) {
                $this->logger->emergency('Cannot unlink pid-file');
                exit(-1);
            }
        }
        return false;
    }

    /**
     * @throws DaemonException
     */
    public function start()
    {
        if ($this->isActive()) {
            $this->logger->error("Daemon is already running");
            exit(0);
        }

        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->logger->emergency("Fork failed");
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

        if ($this->mode == self::MODE_WORKER) {
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
                    try {
                        $this->doJob();
                    } catch (\Exception $e) {
                        $this->logger->alert("Job failed");
                    }
                    exit;
                }
            } else {
                sleep($this->sleepTime);
            }

            if ($this->mode == self::MODE_WORKER) {
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

        $this->logger->info("Daemon control thread exit");
    }

    /**
     * @throws \Exception
     */
    public function doJob()
    {
        $job = new $this->jobClass($this, $this->logger, $this->jobInitParams);
        if (!$job instanceof DaemonJob) {
            throw new \Exception("Invalid job");
        }
        $job->run();
    }

    // Signal handlers
    public function signalSighup()
    {
        $this->logger->info("TODO: Reread config");
    }

    public function signalSigusr1()
    {
        $this->logger->info("Increasing thread count");
        $this->jobLimit++;
    }

    public function signalSigusr2()
    {
        $this->logger->info("Decreasing thread count");
        if ($this->jobLimit) {
            $this->jobLimit--;
        }
    }

    public function signalSigterm()
    {
        $this->logger->info("Catch sigterm, preparing to quit");
        $this->stopped = true;
    }

    public function signalSigint()
    {
        $this->logger->info("Catch ctrl+c, preparing to quit");
        $this->stopped = true;
    }
}