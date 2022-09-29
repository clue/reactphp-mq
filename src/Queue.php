<?php

namespace Clue\React\Mq;

use React\Promise;
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
 * job from the queue and execute this operation.
 */
class Queue implements \Countable
{
    private $concurrency;
    private $limit;
    private $handler;

    private $pending = 0;
    private $queue = array();

    /**
     * Concurrently process all given jobs through the given `$handler`.
     *
     * This is a convenience method which uses the `Queue` internally to
     * schedule all jobs while limiting concurrency to ensure no more than
     * `$concurrency` jobs ever run at once. It will return a promise which
     * resolves with the results of all jobs on success.
     *
     * ```php
     * $browser = new React\Http\Browser();
     *
     * $promise = Queue::all(3, $urls, function ($url) use ($browser) {
     *     return $browser->get($url);
     * });
     *
     * $promise->then(function (array $responses) {
     *     echo 'All ' . count($responses) . ' successful!' . PHP_EOL;
     * });
     * ```
     *
     * If either of the jobs fail, it will reject the resulting promise and will
     * try to cancel all outstanding jobs. Similarly, calling `cancel()` on the
     * resulting promise will try to cancel all outstanding jobs. See
     * [promises](#promises) and [cancellation](#cancellation) for details.
     *
     * The `$concurrency` parameter sets a new soft limit for the maximum number
     * of jobs to handle concurrently. Finding a good concurrency limit depends
     * on your particular use case. It's common to limit concurrency to a rather
     * small value, as doing more than a dozen of things at once may easily
     * overwhelm the receiving side. Using a `1` value will ensure that all jobs
     * are processed one after another, effectively creating a "waterfall" of
     * jobs. Using a value less than 1 will reject with an
     * `InvalidArgumentException` without processing any jobs.
     *
     * ```php
     * // handle up to 10 jobs concurrently
     * $promise = Queue::all(10, $jobs, $handler);
     * ```
     *
     * ```php
     * // handle each job after another without concurrency (waterfall)
     * $promise = Queue::all(1, $jobs, $handler);
     * ```
     *
     * The `$jobs` parameter must be an array with all jobs to process. Each
     * value in this array will be passed to the `$handler` to start one job.
     * The array keys will be preserved in the resulting array, while the array
     * values will be replaced with the job results as returned by the
     * `$handler`. If this array is empty, this method will resolve with an
     * empty array without processing any jobs.
     *
     * The `$handler` parameter must be a valid callable that accepts your job
     * parameters, invokes the appropriate operation and returns a Promise as a
     * placeholder for its future result. If the given argument is not a valid
     * callable, this method will reject with an `InvalidArgumentException`
     * without processing any jobs.
     *
     * ```php
     * // using a Closure as handler is usually recommended
     * $promise = Queue::all(10, $jobs, function ($url) use ($browser) {
     *     return $browser->get($url);
     * });
     * ```
     *
     * ```php
     * // accepts any callable, so PHP's array notation is also supported
     * $promise = Queue::all(10, $jobs, array($browser, 'get'));
     * ```
     *
     * > Keep in mind that returning an array of response messages means that
     *   the whole response body has to be kept in memory.
     *
     * @param int      $concurrency concurrency soft limit
     * @param array    $jobs
     * @param callable $handler
     * @return PromiseInterface Returns a Promise<mixed[]> which resolves with an array of all resolution values
     *     or rejects when any of the operations reject.
     */
    public static function all($concurrency, array $jobs, $handler)
    {
        try {
            // limit number of concurrent operations
            $q = new self($concurrency, null, $handler);
        } catch (\InvalidArgumentException $e) {
            // reject if $concurrency or $handler is invalid
            return Promise\reject($e);
        }

        // try invoking all operations and automatically queue excessive ones
        $promises = array_map($q, $jobs);

        return new Promise\Promise(function ($resolve, $reject) use ($promises) {
            Promise\all($promises)->then($resolve, function ($e) use ($promises, $reject) {
                // cancel all pending promises if a single promise fails
                foreach (array_reverse($promises) as $promise) {
                    if ($promise instanceof PromiseInterface && \method_exists($promise, 'cancel')) {
                        $promise->cancel();
                    }
                }

                // reject with original rejection message
                $reject($e);
            });
        }, function () use ($promises) {
            // cancel all pending promises on cancellation
            foreach (array_reverse($promises) as $promise) {
                if ($promise instanceof PromiseInterface && \method_exists($promise, 'cancel')) {
                    $promise->cancel();
                }
            }
        });
    }

    /**
     * Concurrently process the given jobs through the given `$handler` and
     * resolve with first resolution value.
     *
     * This is a convenience method which uses the `Queue` internally to
     * schedule all jobs while limiting concurrency to ensure no more than
     * `$concurrency` jobs ever run at once. It will return a promise which
     * resolves with the result of the first job on success and will then try
     * to `cancel()` all outstanding jobs.
     *
     * ```php
     * $browser = new React\Http\Browser();
     *
     * $promise = Queue::any(3, $urls, function ($url) use ($browser) {
     *     return $browser->get($url);
     * });
     *
     * $promise->then(function (ResponseInterface $response) {
     *     echo 'First response: ' . $response->getBody() . PHP_EOL;
     * });
     * ```
     *
     * If all of the jobs fail, it will reject the resulting promise. Similarly,
     * calling `cancel()` on the resulting promise will try to cancel all
     * outstanding jobs. See [promises](#promises) and
     * [cancellation](#cancellation) for details.
     *
     * The `$concurrency` parameter sets a new soft limit for the maximum number
     * of jobs to handle concurrently. Finding a good concurrency limit depends
     * on your particular use case. It's common to limit concurrency to a rather
     * small value, as doing more than a dozen of things at once may easily
     * overwhelm the receiving side. Using a `1` value will ensure that all jobs
     * are processed one after another, effectively creating a "waterfall" of
     * jobs. Using a value less than 1 will reject with an
     * `InvalidArgumentException` without processing any jobs.
     *
     * ```php
     * // handle up to 10 jobs concurrently
     * $promise = Queue::any(10, $jobs, $handler);
     * ```
     *
     * ```php
     * // handle each job after another without concurrency (waterfall)
     * $promise = Queue::any(1, $jobs, $handler);
     * ```
     *
     * The `$jobs` parameter must be an array with all jobs to process. Each
     * value in this array will be passed to the `$handler` to start one job.
     * The array keys have no effect, the promise will simply resolve with the
     * job results of the first successful job as returned by the `$handler`.
     * If this array is empty, this method will reject without processing any
     * jobs.
     *
     * The `$handler` parameter must be a valid callable that accepts your job
     * parameters, invokes the appropriate operation and returns a Promise as a
     * placeholder for its future result. If the given argument is not a valid
     * callable, this method will reject with an `InvalidArgumentExceptionn`
     * without processing any jobs.
     *
     * ```php
     * // using a Closure as handler is usually recommended
     * $promise = Queue::any(10, $jobs, function ($url) use ($browser) {
     *     return $browser->get($url);
     * });
     * ```
     *
     * ```php
     * // accepts any callable, so PHP's array notation is also supported
     * $promise = Queue::any(10, $jobs, array($browser, 'get'));
     * ```
     *
     * @param int      $concurrency concurrency soft limit
     * @param array    $jobs
     * @param callable $handler
     * @return PromiseInterface Returns a Promise<mixed> which resolves with a single resolution value
     *     or rejects when all of the operations reject.
     */
    public static function any($concurrency, array $jobs, $handler)
    {
        // explicitly reject with empty jobs (https://github.com/reactphp/promise/pull/34)
        if (!$jobs) {
            return Promise\reject(new \UnderflowException('No jobs given'));
        }

        try {
            // limit number of concurrent operations
            $q = new self($concurrency, null, $handler);
        } catch (\InvalidArgumentException $e) {
            // reject if $concurrency or $handler is invalid
            return Promise\reject($e);
        }

        // try invoking all operations and automatically queue excessive ones
        $promises = array_map($q, $jobs);

        return new Promise\Promise(function ($resolve, $reject) use ($promises) {
            Promise\any($promises)->then(function ($result) use ($promises, $resolve) {
                // cancel all pending promises if a single result is ready
                foreach (array_reverse($promises) as $promise) {
                    if ($promise instanceof PromiseInterface && \method_exists($promise, 'cancel')) {
                        $promise->cancel();
                    }
                }

                // resolve with original resolution value
                $resolve($result);
            }, $reject);
        }, function () use ($promises) {
            // cancel all pending promises on cancellation
            foreach (array_reverse($promises) as $promise) {
                if ($promise instanceof PromiseInterface && \method_exists($promise, 'cancel')) {
                    $promise->cancel();
                }
            }
        });
    }

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

        // we're currently above concurrency limit, make sure we do not exceed maximum queue limit
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
            if (isset($deferred->pending) && $deferred->pending instanceof PromiseInterface && \method_exists($deferred->pending, 'cancel')) {
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

    #[\ReturnTypeWillChange]
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
