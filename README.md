# clue/mq-react [![Build Status](https://travis-ci.org/clue/php-mq-react.svg?branch=master)](https://travis-ci.org/clue/php-mq-react)

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
  Process any number of async operations choose how many should be handled
  concurrently and how many operations can be queued in-memory. Process their
  results as soon as responses come in.
  The Promise-based design provides a *sane* interface to working with out of bound results.
* **Lightweight, SOLID design** -
  Provides a thin abstraction that is [*just good enough*](http://en.wikipedia.org/wiki/Principle_of_good_enough)
  and does not get in your way.
  Builds on top of well-tested components and well-established concepts instead of reinventing the wheel.
* **Good test coverage** -
  Comes with an automated tests suite and is regularly tested in the *real world*

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [Queue](#queue)
    * [Promises](#promises)
    * [Cancellation](#cancellation)
    * [Timeout](#timeout)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

> Note: This project is in an early alpha stage! Feel free to report any issues you encounter.

## Quickstart example

Once [installed](#install), you can use the following code to access an
HTTP webserver and send a large number of HTTP GET requests:

```php
$loop = React\EventLoop\Factory::create();
$client = new Clue\React\Buzz\Browser($loop);

// load a huge array of URLs to fetch
$urls = file('urls.txt');

// each job should use the browser to GET a certain URL
// limit number of concurrent jobs here
$q = new Queue(3, null, function ($url) use ($client) {
    return $client->get($url);
});

foreach ($urls as $url) {
    $q($url)->then(function (ResponseInterface $response) use ($url) {
        echo $url . ': ' . $response->getBody()->getSize() . ' bytes' . PHP_EOL;
    });
}

$loop->run();
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
job from the qeueue and execute this operation.

The `new Queue(int $concurrency, ?int $limit, callable $handler)` call
can be used to create a new queue instance.
You can create any number of queues, for example when you want to apply
different limits to different kind of operations.

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

The demonstration purposes, the examples in this documentation use the async
HTTP client [clue/buzz-react](https://github.com/clue/php-buzz-react), but you
may use any Promise-based API with this project. Its API can be used like this:

```php
$loop = React\EventLoop\Factory::create();
$browser = new Clue\React\Buzz\Browser($loop);

$promise = $browser->get($url);
```

If you wrap this in a `Queue` instance as given above, this code will look
like this:

```php
$loop = React\EventLoop\Factory::create();
$browser = new Clue\React\Buzz\Browser($loop);

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

#### Cancellation

The returned Promise is implemented in such a way that it can be cancelled
when it is still pending.
Cancelling a pending operation will invoke its cancellation handler which is
responsible for rejecting its value with an Exception and cleaning up any
underlying resources.

```php
$promise = $q($url);

$loop->addTimer(2.0, function () use ($promise) {
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

$q = new Queue(10, null, function ($uri) use ($browser, $loop) {
    return Timer\timeout($browser->get($uri), 2.0, $loop);
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
$promise = Timer\timeout($q($url), 2.0, $loop);
```

Please refer to [react/promise-timer](https://github.com/reactphp/promise-timer)
for more details.

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require clue/mq-react:dev-master
```

This project aims to run on any platform and thus does not require any PHP
extensions and supports running on legacy PHP 5.3 through current PHP 7+ and
HHVM.
It's *highly recommended to use PHP 7+* for this project.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

## License

MIT
