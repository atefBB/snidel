<?php
declare(ticks = 1);

namespace Ackintosh;

use Ackintosh\Snidel\Config;
use Ackintosh\Snidel\Fork\Container;
use Ackintosh\Snidel\Log;
use Ackintosh\Snidel\Pcntl;
use Ackintosh\Snidel\Task\Task;

class Snidel
{
    /** @var \Ackintosh\Snidel\Config */
    private $config;

    /** @var \Ackintosh\Snidel\Fork\Container */
    private $container;

    /** @var \Ackintosh\Snidel\Pcntl */
    private $pcntl;

    /** @var \Ackintosh\Snidel\Log */
    private $log;

    /** @var bool */
    private $joined = false;

    /** @var int */
    private $ownerPid;

    /** @var array */
    private $signals = [
        SIGTERM,
        SIGINT,
    ];

    /** @var int */
    private $receivedSignal;

    /**
     * @param   mixed $parameter
     * @throws  \InvalidArgumentException
     */
    public function __construct($parameter = null)
    {
        if (is_null($parameter)) {
            $this->config = new Config();
        } elseif (is_int($parameter) && $parameter >= 1) {
            $this->config = new Config(
                ['concurrency' => $parameter]
            );
        } elseif (is_array($parameter)) {
            $this->config = new Config($parameter);
        } else {
            throw new \InvalidArgumentException();
        }

        $this->ownerPid         = getmypid();
        $this->log              = new Log($this->ownerPid, $this->config->get('logger'));
        $this->container        = new Container($this->ownerPid, $this->log, $this->config);
        $this->pcntl            = new Pcntl();

        foreach ($this->signals as $sig) {
            $this->pcntl->signal(
                $sig,
                function ($sig)  {
                    $this->log->info('received signal. signo: ' . $sig);
                    $this->setReceivedSignal($sig);

                    $this->log->info('--> sending a signal " to children.');
                    $this->container->sendSignalToMaster($sig);
                    $this->log->info('<-- signal handling has been completed successfully.');
                    exit;
                },
                false
            );
        }

        $this->log->info('parent pid: ' . $this->ownerPid);
    }

    /**
     * this method uses master / worker model.
     *
     * @param   callable    $callable
     * @param   mixed       $args
     * @param   string      $tag
     * @return  void
     * @throws  \RuntimeException
     */
    public function fork($callable, $args = [], $tag = null)
    {
        $this->joined = false;

        if (!$this->container->existsMaster()) {
            $this->container->forkMaster();
        }

        try {
            $this->container->enqueue(new Task($callable, $args, $tag));
        } catch (\RuntimeException $e) {
            throw $e;
        }

        $this->log->info('queued task #' . $this->container->queuedCount());
    }

    /**
     * waits until all tasks that queued by Snidel::fork() are completed
     *
     * @return  void
     */
    public function wait()
    {
        $this->container->wait();
        $this->joined = true;
    }

    /**
     * @return  bool
     */
    public function hasError()
    {
        return $this->container->hasError();
    }

    /**
     * @return  \Ackintosh\Snidel\Error
     */
    public function getError()
    {
        return $this->container->getError();
    }

    /**
     * gets results
     *
     * @param   string  $tag
     * @return  \Ackintosh\Snidel\Result\Collection
     * @throws  \InvalidArgumentException
     */
    public function get($tag = null)
    {
        if (!$this->joined) {
            $this->wait();
        }
        if ($tag !== null && !$this->container->hasTag($tag)) {
            throw new \InvalidArgumentException('unknown tag: ' . $tag);
        }

        return $this->container->getCollection($tag);
    }

    /**
     * returns generator which returns a result
     *
     * @return \Generator
     */
    public function generator()
    {
        foreach($this->container->generator() as $r) {
            yield $r;
        }

        $this->joined = true;
    }

    public function setReceivedSignal($sig)
    {
        $this->receivedSignal = $sig;
    }

    public function __destruct()
    {
        if ($this->ownerPid === getmypid()) {
            if ($this->container->existsMaster()) {
                $this->log->info('shutdown master process.');
                $this->container->sendSignalToMaster();
            }

            unset($this->container);
        }

        if ($this->ownerPid === getmypid() && !$this->joined && $this->receivedSignal === null) {
            $message = 'snidel will have to wait for the child process is completed. please use Snidel::wait()';
            $this->log->error($message);
            throw new \LogicException($message);
        }
    }
}
