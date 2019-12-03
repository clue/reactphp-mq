<?php

namespace Clue\Tests\React\Mq;

use Clue\React\Mq\Queue;
use React\Promise\Promise;
use React\Promise\Deferred;

class QueueAnyTest extends TestCase
{
    public function testAnyRejectsIfConcurrencyIsInvalid()
    {
        Queue::any(0, array(1), function ($arg) {
            return \React\Promise\resolve($arg);
        })->then(null, $this->expectCallableOnce());
    }

    public function testAnyRejectsIfHandlerIsInvalid()
    {
        Queue::any(1, array(1), 'foobar')->then(null, $this->expectCallableOnce());
    }

    public function testAnyWillRejectWithoutInvokingHandlerWhenJobsAreEmpty()
    {
        $promise = Queue::any(1, array(), $this->expectCallableNever());

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testWillResolveWithSingleValueIfHandlerResolves()
    {
        $promise = Queue::any(1, array(1), function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $promise->then($this->expectCallableOnceWith(1));
    }

    public function testWillResolveWithFirstValueIfAllHandlersResolve()
    {
        $promise = Queue::any(1, array(1, 2, 3), function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $promise->then($this->expectCallableOnceWith(1));
    }

    public function testWillRejectIfSingleReject()
    {
        $promise = Queue::any(1, array(1), function () {
            return \React\Promise\reject(new \RuntimeException());
        });

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testWillRejectIfMoreHandlersReject()
    {
        $promise = Queue::any(1, array(1, 2), function () {
            return \React\Promise\reject(new \RuntimeException());
        });

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testCancelResultingPromiseWillCancelPendingOperation()
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Queue::any(1, array(1), function () use ($pending) {
            return $pending;
        });

        $promise->cancel();
    }

    public function testPendingOperationWillBeStartedAndCancelledIfFirstOperationResolves()
    {
        // second operation will only be started to be cancelled immediately
        $first = new Deferred();
        $second = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Queue::any(1, array($first->promise(), $second), function ($promise) {
            return $promise;
        });

        $first->resolve(1);

        $promise->then($this->expectCallableOnceWith(1));
    }

    public function testPendingOperationWillBeCancelledIfFirstOperationResolves()
    {
        $first = new Deferred();
        $second = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Queue::any(2, array($first->promise(), $second), function ($promise) {
            return $promise;
        });

        $first->resolve(1);

        $promise->then($this->expectCallableOnceWith(1));
    }

    public function testQueuedOperationsWillStartAndCancelOneIfOneOperationResolves()
    {
        $first = new Deferred();
        $second = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });
        $third = new Promise(function () { }, $this->expectCallableOnce());
        $fourth = new Promise(function () { }, $this->expectCallableNever());

        $started = 0;
        $promise = Queue::any(2, array($first->promise(), $second, $third, $fourth), function ($promise) use (&$started) {
            ++$started;
            return $promise;
        });

        $first->resolve(1);

        $this->assertEquals(3, $started);
    }
}
