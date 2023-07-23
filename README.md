# clue/reactphp-mq

[![CI status](https://github.com/clue/reactphp-mq/actions/workflows/ci.yml/badge.svg)](https://github.com/clue/reactphp-mq/actions)
[![code coverage](https://img.shields.io/badge/code%20coverage-100%25-success)](#tests)
[![installs on Packagist](https://img.shields.io/packagist/dt/clue/mq-react?color=blue&label=installs%20on%20Packagist)](https://packagist.org/packages/clue/mq-react)

Mini Queue, the lightweight in-memory message queue to concurrently do many (but not too many) things at once,
built on top of [ReactPHP](https://reactphp.org/).

Let's say you crawl a page and find that you need to send 100 HTTP requests to
following pages which each takes `0.2s`. You can either send them all
sequentially (taking around `20s`) or you can use
[ReactPHP](https://reactphp.org) to concurrently request all your pages at the
same time. This works perfectly fine for a small number of operations, but
sending an excessive number of requests can either take up all resources on your
side or may get you banned by the remote side as it sees an unreasonable number
of requests from your side.
Instead, you can use this library to effectively rate limit your operations and
queue excessives ones so that not too many operations are processed at once.
This library provides a simple API that is easy to use in order to manage any
kind of async operation without having to mess with most of the low-level details.
You can use this to throttle multiple HTTP requests, database queries or pretty
much any API that already uses Promises.

* **Async execution of operations** -
  Process any number of async operations and choose how many should be handled
  concurrently and how many operations can be queued in-memory. Process their
  results as soon as responses come in.
  The Promise-based design provides a *sane* interface to working with out of order results.
* **Lightweight, SOLID design** -
  Provides a thin abstraction that is [*just good enough*](https://en.wikipedia.org/wiki/Principle_of_good_enough)
  and does not get in your way.
  Builds on top of well-tested components and well-established concepts instead of reinventing the wheel.
* **Good test coverage** -
  Comes with an automated tests suite and is regularly tested in the *real world*.

**Table of contents**

* [Support us](#support-us)
* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [Queue](#queue)
    * [Promises](#promises)
    * [Cancellation](#cancellation)
    * [Timeout](#timeout)
    * [all()](#all)
    * [any()](#any)
    * [Blocking](#blocking)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Support us

We invest a lot of time developing, maintaining and updating our awesome
open-source projects. You can help us sustain this high-quality of our work by
[becoming a sponsor on GitHub](https://github.com/sponsors/clue). Sponsors get
numerous benefits in return, see our [sponsoring page](https://github.com/sponsors/clue)
for details.

Let's take these projects to the next level together! 🚀

## Quickstart example

Once [installed](#install), you can use the following code to access an
HTTP webserver and send a large number of HTTP GET requests:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$browser = new React\Http\Browser();

// load a huge array of URLs to fetch
$urls = file('urls.txt');

// each job should use the browser to GET a certain URL
// limit number of concurrent jobs here
$q = new Clue\React\Mq\Queue(3, null, function ($url) use ($browser) {
    return $browser->get($url);
});

foreach ($urls as $url) {
    $q($url)->then(function (Psr\Http\Message\ResponseInterface $response) use ($url) {
        echo $url . ': ' . $response->getBody()->getSize() . ' bytes' . PHP_EOL;
    });
}

```

See also the [examples](examples).

## Usage

### Queue

The `Queue` is responsible for managing your operations and ensuring not too
many operations are executed at once. It's a very simple and lightweight
in-memory implementation of the
[leaky bucket](https://en.wikipedia.org/wiki/Leaky_bucket#As_a_queue) algorithm.

This means that you control how many operations can be executed concurrently.
If you add a job to the queue and it still below the limit, it will be executed
immediately. If you keep adding new jobs to the queue and its concurrency limit
is reached, it will not start a new operation and instead queue this for future
execution. Once one of the pending operations complete, it will pick the next
job from the queue and execute this operation.

The `new Queue(int $concurrency, ?int $limit, callable(mixed):PromiseInterface<T> $handler)` call
can be used to create a new queue instance.
You can create any number of queues, for example when you want to apply
different limits to different kinds of operations.

The `$concurrency` parameter sets a new soft limit for the maximum number
of jobs to handle concurrently. Finding a good concurrency limit depends
on your particular use case. It's common to limit concurrency to a rather
small value, as doing more than a dozen of things at once may easily
overwhelm the receiving side.

The `$limit` parameter sets a new hard limit on how many jobs may be
outstanding (kept in memory) at once. Depending on your particular use
case, it's usually safe to keep a few hundreds or thousands of jobs in
memory. If you do not want to apply an upper limit, you can pass a `null`
value which is semantically more meaningful than passing a big number.

```php
// handle up to 10 jobs concurrently, but keep no more than 1000 in memory
$q = new Queue(10, 1000, $handler);
```

```php
// handle up to 10 jobs concurrently, do not limit queue size
$q = new Queue(10, null, $handler);
```

```php
// handle up to 10 jobs concurrently, reject all further jobs
$q = new Queue(10, 10, $handler);
```

The `$handler` parameter must be a valid callable that accepts your job
parameters, invokes the appropriate operation and returns a Promise as a
placeholder for its future result.

```php
// using a Closure as handler is usually recommended
$q = new Queue(10, null, function ($url) use ($browser) {
    return $browser->get($url);
});
```

```php
// accepts any callable, so PHP's array notation is also supported
$q = new Queue(10, null, array($browser, 'get'));
```

#### Promises

This library works under the assumption that you want to concurrently handle
async operations that use a [Promise](https://github.com/reactphp/promise)-based API.

The demonstration purposes, the examples in this documentation use 
[ReactPHP's async HTTP client](https://github.com/reactphp/http#client-usage), but you
may use any Promise-based API with this project. Its API can be used like this:

```php
$browser = new React\Http\Browser();

$promise = $browser->get($url);
```

If you wrap this in a `Queue` instance as given above, this code will look
like this:

```php
$browser = new React\Http\Browser();

$q = new Queue(10, null, function ($url) use ($browser) {
    return $browser->get($url);
});

$promise = $q($url);
```

The `$q` instance is invokable, so that invoking `$q(...$args)` will
actually be forwarded as `$browser->get(...$args)` as given in the
`$handler` argument when concurrency is still below limits.

Each operation is expected to be async (non-blocking), so you may actually
invoke multiple operations concurrently (send multiple requests in parallel).
The `$handler` is responsible for responding to each request with a resolution
value, the order is not guaranteed.
These operations use a [Promise](https://github.com/reactphp/promise)-based
interface that makes it easy to react to when an operation is completed (i.e.
either successfully fulfilled or rejected with an error):

```php
$promise->then(
    function ($result) {
        var_dump('Result received', $result);
    },
    function (Exception $error) {
        var_dump('There was an error', $error->getMessage());
    }
);
```

Each operation may take some time to complete, but due to its async nature you
can actually start any number of (queued) operations. Once the concurrency limit
is reached, this invocation will simply be queued and this will return a pending
promise which will start the actual operation once another operation is
completed. This means that this is handled entirely transparently and you do not
need to worry about this concurrency limit yourself.

If this looks strange to you, you can also use the more traditional
[blocking API](#blocking).

#### Cancellation

The returned Promise is implemented in such a way that it can be cancelled
when it is still pending.
Cancelling a pending operation will invoke its cancellation handler which is
responsible for rejecting its value with an Exception and cleaning up any
underlying resources.

```php
$promise = $q($url);

Loop::addTimer(2.0, function () use ($promise) {
    $promise->cancel();
});
```

Similarly, cancelling an operation that is queued and has not yet been started
will be rejected without ever starting the operation.

#### Timeout

By default, this library does not limit how long a single operation can take,
so that the resulting promise may stay pending for a long time.
Many use cases involve some kind of "timeout" logic so that an operation is
cancelled after a certain threshold is reached.

You can simply use [cancellation](#cancellation) as in the previous chapter or
you may want to look into using [react/promise-timer](https://github.com/reactphp/promise-timer)
which helps taking care of this through a simple API.

The resulting code with timeouts applied look something like this:

```php
use React\Promise\Timer;

$q = new Queue(10, null, function ($uri) use ($browser) {
    return Timer\timeout($browser->get($uri), 2.0);
});

$promise = $q($uri);
```

The resulting promise can be consumed as usual and the above code will ensure
that execution of this operation can not take longer than the given timeout
(i.e. after it is actually started).
In particular, note how this differs from applying a timeout to the resulting
promise. The following code will ensure that the total time for queuing and
executing this operation can not take longer than the given timeout:

```php
// usually not recommended
$promise = Timer\timeout($q($url), 2.0);
```

Please refer to [react/promise-timer](https://github.com/reactphp/promise-timer)
for more details.

#### all()

The static `all(int $concurrency, array<TKey,TIn> $jobs, callable(TIn):PromiseInterface<TOut> $handler): PromiseInterface<array<TKey,TOut>>` method can be used to
concurrently process all given jobs through the given `$handler`.

This is a convenience method which uses the `Queue` internally to
schedule all jobs while limiting concurrency to ensure no more than
`$concurrency` jobs ever run at once. It will return a promise which
resolves with the results of all jobs on success.

```php
$browser = new React\Http\Browser();

$promise = Queue::all(3, $urls, function ($url) use ($browser) {
    return $browser->get($url);
});

$promise->then(function (array $responses) {
    echo 'All ' . count($responses) . ' successful!' . PHP_EOL;
});
```

If either of the jobs fail, it will reject the resulting promise and will
try to cancel all outstanding jobs. Similarly, calling `cancel()` on the
resulting promise will try to cancel all outstanding jobs. See
[promises](#promises) and [cancellation](#cancellation) for details.

The `$concurrency` parameter sets a new soft limit for the maximum number
of jobs to handle concurrently. Finding a good concurrency limit depends
on your particular use case. It's common to limit concurrency to a rather
small value, as doing more than a dozen of things at once may easily
overwhelm the receiving side. Using a `1` value will ensure that all jobs
are processed one after another, effectively creating a "waterfall" of
jobs. Using a value less than 1 will reject with an
`InvalidArgumentException` without processing any jobs.

```php
// handle up to 10 jobs concurrently
$promise = Queue::all(10, $jobs, $handler);
```

```php
// handle each job after another without concurrency (waterfall)
$promise = Queue::all(1, $jobs, $handler);
```

The `$jobs` parameter must be an array with all jobs to process. Each
value in this array will be passed to the `$handler` to start one job.
The array keys will be preserved in the resulting array, while the array
values will be replaced with the job results as returned by the
`$handler`. If this array is empty, this method will resolve with an
empty array without processing any jobs.

The `$handler` parameter must be a valid callable that accepts your job
parameters, invokes the appropriate operation and returns a Promise as a
placeholder for its future result. If the given argument is not a valid
callable, this method will reject with an `InvalidArgumentException`
without processing any jobs.

```php
// using a Closure as handler is usually recommended
$promise = Queue::all(10, $jobs, function ($url) use ($browser) {
    return $browser->get($url);
});
```

```php
// accepts any callable, so PHP's array notation is also supported
$promise = Queue::all(10, $jobs, array($browser, 'get'));
```

> Keep in mind that returning an array of response messages means that
  the whole response body has to be kept in memory.

#### any()

The static `any(int $concurrency, array<TKey,TIn> $jobs, callable(TIn):Promise<TOut> $handler): PromiseInterface<TOut>` method can be used to
concurrently process the given jobs through the given `$handler` and
resolve with first resolution value.

This is a convenience method which uses the `Queue` internally to
schedule all jobs while limiting concurrency to ensure no more than
`$concurrency` jobs ever run at once. It will return a promise which
resolves with the result of the first job on success and will then try
to `cancel()` all outstanding jobs.

```php
$browser = new React\Http\Browser();

$promise = Queue::any(3, $urls, function ($url) use ($browser) {
    return $browser->get($url);
});

$promise->then(function (ResponseInterface $response) {
    echo 'First response: ' . $response->getBody() . PHP_EOL;
});
```

If all of the jobs fail, it will reject the resulting promise. Similarly,
calling `cancel()` on the resulting promise will try to cancel all
outstanding jobs. See [promises](#promises) and
[cancellation](#cancellation) for details.

The `$concurrency` parameter sets a new soft limit for the maximum number
of jobs to handle concurrently. Finding a good concurrency limit depends
on your particular use case. It's common to limit concurrency to a rather
small value, as doing more than a dozen of things at once may easily
overwhelm the receiving side. Using a `1` value will ensure that all jobs
are processed one after another, effectively creating a "waterfall" of
jobs. Using a value less than 1 will reject with an
`InvalidArgumentException` without processing any jobs.

```php
// handle up to 10 jobs concurrently
$promise = Queue::any(10, $jobs, $handler);
```

```php
// handle each job after another without concurrency (waterfall)
$promise = Queue::any(1, $jobs, $handler);
```

The `$jobs` parameter must be an array with all jobs to process. Each
value in this array will be passed to the `$handler` to start one job.
The array keys have no effect, the promise will simply resolve with the
job results of the first successful job as returned by the `$handler`.
If this array is empty, this method will reject without processing any
jobs.

The `$handler` parameter must be a valid callable that accepts your job
parameters, invokes the appropriate operation and returns a Promise as a
placeholder for its future result. If the given argument is not a valid
callable, this method will reject with an `InvalidArgumentExceptionn`
without processing any jobs.

```php
// using a Closure as handler is usually recommended
$promise = Queue::any(10, $jobs, function ($url) use ($browser) {
    return $browser->get($url);
});
```

```php
// accepts any callable, so PHP's array notation is also supported
$promise = Queue::any(10, $jobs, array($browser, 'get'));
```

#### Blocking

As stated above, this library provides you a powerful, async API by default.

You can also integrate this into your traditional, blocking environment by using
[reactphp/async](https://github.com/reactphp/async). This allows you to simply
await async HTTP requests like this:

```php
use function React\Async\await;

$browser = new React\Http\Browser();

$promise = Queue::all(3, $urls, function ($url) use ($browser) {
    return $browser->get($url);
});

try {
    $responses = await($promise);
    // responses successfully received
} catch (Exception $e) {
    // an error occured while performing the requests
}
```

Similarly, you can also wrap this in a function to provide a simple API and hide
all the async details from the outside:

```php
use function React\Async\await; 

/**
 * Concurrently downloads all the given URIs
 *
 * @param string[] $uris       list of URIs to download
 * @return ResponseInterface[] map with a response object for each URI
 * @throws Exception if any of the URIs can not be downloaded
 */
function download(array $uris)
{
    $browser = new React\Http\Browser();

    $promise = Queue::all(3, $uris, function ($uri) use ($browser) {
        return $browser->get($uri);
    });

    return await($promise);
}
```

This is made possible thanks to fibers available in PHP 8.1+ and our
compatibility API that also works on all supported PHP versions.
Please refer to [reactphp/async](https://github.com/reactphp/async#readme) for more details.

> Keep in mind that returning an array of response messages means that the whole
  response body has to be kept in memory.

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org/).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This project follows [SemVer](https://semver.org/).
This will install the latest supported version:

```bash
composer require clue/mq-react:^1.5
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

This project aims to run on any platform and thus does not require any PHP
extensions and supports running on legacy PHP 5.3 through current PHP 8+.
It's *highly recommended to use the latest supported PHP version* for this project.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org/):

```bash
composer install
```

To run the test suite, go to the project root and run:

```bash
vendor/bin/phpunit
```

The test suite is set up to always ensure 100% code coverage across all
supported environments. If you have the Xdebug extension installed, you can also
generate a code coverage report locally like this:

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text
```

## License

This project is released under the permissive [MIT license](LICENSE).

I'd like to thank [Bergfreunde GmbH](https://www.bergfreunde.de/), a German
online retailer for Outdoor Gear & Clothing, for sponsoring the first release! 🎉
Thanks to sponsors like this, who understand the importance of open source
development, I can justify spending time and focus on open source development
instead of traditional paid work.

> Did you know that I offer custom development services and issuing invoices for
  sponsorships of releases and for contributions? Contact me (@clue) for details.
