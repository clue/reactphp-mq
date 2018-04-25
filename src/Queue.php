<?php

namespace Clue\React\Mq;

use React\Promise;
use React\Promise\CancellablePromiseInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * The `Queue` is responsible for managing your operations and ensuring not too
 * many operations are executed at once. It's a very simple and lightweight
 * in-memory implementation of the
 * [leaky bucket](https://en.wikipedia.org/wiki/Leaky_bucket#As_a_queue) algorithm.
 *
 * This means that you control how many operations can be executed concurrently.
 * If you add a job to the queue and it still below the limit, it will be executed
 * immediately. If you keep adding new jobs to the queue and its concurrency limit
 * is reached, it will not start a new operation and instead queue this for future
 * execution. Once one of the pending operations complete, it will pick the next
 * job from the qeueue and execute this operation.
 */
class Queue implements \Countable
{
    private $concurrency;
    private $limit;
    private $handler;

    private $pending = 0;
    private $queue = array();

    /**
     * Instantiates a new queue object.
     *
     * You can create any number of queues, for example when you want to apply
     * different limits to different kind of operations.
     *
     * The `$concurrency` parameter sets a new soft limit for the maximum number
     * of jobs to handle concurrently. Finding a good concurrency limit depends
     * on your particular use case. It's common to limit concurrency to a rather
     * small value, as doing more than a dozen of things at once may easily
     * overwhelm the receiving side.
     *
     * The `$limit` parameter sets a new hard limit on how many jobs may be
     * outstanding (kept in memory) at once. Depending on your particular use
     * case, it's usually safe to keep a few hundreds or thousands of jobs in
     * memory. If you do not want to apply an upper limit, you can pass a `null`
     * value which is semantically more meaningful than passing a big number.
     *
     * ```php
     * // handle up to 10 jobs concurrently, but keep no more than 1000 in memory
     * $q = new Queue(10, 1000, $handler);
     * ```
     *
     * ```php
     * // handle up to 10 jobs concurrently, do not limit queue size
     * $q = new Queue(10, null, $handler);
     * ```
     *
     * ```php
     * // handle up to 10 jobs concurrently, reject all further jobs
     * $q = new Queue(10, 10, $handler);
     * ```
     *
     * The `$handler` parameter must be a valid callable that accepts your job
     * parameters, invokes the appropriate operation and returns a Promise as a
     * placeholder for its future result.
     *
     * ```php
     * // using a Closure as handler is usually recommended
     * $q = new Queue(10, null, function ($url) use ($browser) {
     *     return $browser->get($url);
     * });
     * ```
     *
     * ```php
     * // PHP's array callable as handler is also supported
     * $q = new Queue(10, null, array($browser, 'get'));
     * ```
     *
     * @param int      $concurrency concurrency soft limit
     * @param int|null $limit       queue hard limit or NULL=unlimited
     * @param callable $handler
     * @throws \InvalidArgumentException
     */
    public function __construct($concurrency, $limit, $handler)
    {
        if ($concurrency < 1 || ($limit !== null && ($limit < 1 || $concurrency > $limit))) {
            throw new \InvalidArgumentException('Invalid limit given');
        }
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException('Invalid handler given');
        }

        $this->concurrency = $concurrency;
        $this->limit = $limit;
        $this->handler = $handler;
    }

    /**
     * The Queue instance is invokable, so that invoking `$q(...$args)` will
     * actually be forwarded as `$handler(...$args)` as given in the
     * `$handler` argument when concurrency is still below limits.
     *
     * Each operation may take some time to complete, but due to its async nature you
     * can actually start any number of (queued) operations. Once the concurrency limit
     * is reached, this invocation will simply be queued and this will return a pending
     * promise which will start the actual operation once another operation is
     * completed. This means that this is handled entirely transparently and you do not
     * need to worry about this concurrency limit yourself.
     *
     * @return \React\Promise\PromiseInterface
     */
    public function __invoke()
    {
        // happy path: simply invoke handler if we're below concurrency limit
        if ($this->pending < $this->concurrency) {
            ++$this->pending;

            // invoke handler and await its resolution before invoking next queued job
            return $this->await(
                call_user_func_array($this->handler, func_get_args())
            );
        }

        // we're currently above concurreny limit, make sure we do not exceed maximum queue limit
        if ($this->limit !== null && $this->count() >= $this->limit) {
            return Promise\reject(new \OverflowException('Maximum queue limit of ' . $this->limit . ' exceeded'));
        }

        // if we reach this point, then this job will need to be queued
        // get next queue position
        $queue =& $this->queue;
        $queue[] = null;
        end($queue);
        $id = key($queue);

        $deferred = new Deferred(function ($_, $reject) use (&$queue, $id, &$deferred) {
            // forward cancellation to pending operation if it is currently executing
            if (isset($deferred->pending) && $deferred->pending instanceof CancellablePromiseInterface) {
                $deferred->pending->cancel();
            }
            unset($deferred->pending);

            if (isset($deferred->args)) {
                // queued promise cancelled before its handler is invoked
                // remove from queue and reject explicitly
                unset($queue[$id], $deferred->args);
                $reject(new \RuntimeException('Cancelled queued job before processing started'));
            }
        });

        // queue job to process if number of pending jobs is below concurrency limit again
        $deferred->args = func_get_args();
        $queue[$id] = $deferred;

        return $deferred->promise();
    }

    public function count()
    {
        return $this->pending + count($this->queue);
    }

    /**
     * @internal
     */
    public function await(PromiseInterface $promise)
    {
        $that = $this;

        return $promise->then(function ($result) use ($that) {
            $that->processQueue();

            return $result;
        }, function ($error) use ($that) {
            $that->processQueue();

            return Promise\reject($error);
        });
    }

    /**
     * @internal
     */
    public function processQueue()
    {
        // skip if we're still above concurrency limit or there's no queued job waiting
        if (--$this->pending >= $this->concurrency || !$this->queue) {
            return;
        }

        /* @var $deferred Deferred */
        $deferred = reset($this->queue);
        unset($this->queue[key($this->queue)]);

        // once number of pending jobs is below concurrency limit again:
        // await this situation, invoke handler and await its resolution before invoking next queued job
        ++$this->pending;

        $promise = call_user_func_array($this->handler, $deferred->args);
        $deferred->pending = $promise;
        unset($deferred->args);

        // invoke handler and await its resolution before invoking next queued job
        $this->await($promise)->then(
            function ($result) use ($deferred) {
                unset($deferred->pending);
                $deferred->resolve($result);
            },
            function ($e) use ($deferred) {
                unset($deferred->pending);
                $deferred->reject($e);
            }
        );
    }
}
