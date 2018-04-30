<?php

namespace Clue\Tests\React\Mq;

use Clue\React\Mq\Queue;
use React\Promise\Promise;
use React\Promise\Deferred;

class QueueAllTest extends TestCase
{
    public function testAllRejectsIfConcurrencyIsInvalid()
    {
        Queue::all(0, array(), function ($arg) {
            return \React\Promise\resolve($arg);
        })->then(null, $this->expectCallableOnce());
    }

    public function testAllRejectsIfHandlerIsInvalid()
    {
        Queue::all(1, array(), 'foobar')->then(null, $this->expectCallableOnce());
    }

    public function testWillResolveWithtEmptyArrayWithoutInvokingHandlerWhenJobsAreEmpty()
    {
        $promise = Queue::all(1, array(), $this->expectCallableNever());

        $promise->then($this->expectCallableOnceWith(array()));
    }

    public function testWillResolveWithSingleValueIfHandlerResolves()
    {
        $promise = Queue::all(1, array(1), function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $promise->then($this->expectCallableOnceWith(array(1)));
    }

    public function testWillResolveWithAllValuesIfHandlerResolves()
    {
        $promise = Queue::all(1, array(1, 2), function ($arg) {
            return \React\Promise\resolve($arg);
        });

        $promise->then($this->expectCallableOnceWith(array(1, 2)));
    }

    public function testWillRejectIfSingleRejects()
    {
        $promise = Queue::all(1, array(1), function () {
            return \React\Promise\reject(new \RuntimeException());
        });

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testWillRejectIfMoreHandlersReject()
    {
        $promise = Queue::all(1, array(1, 2), function () {
            return \React\Promise\reject(new \RuntimeException());
        });

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testCancelResultingPromiseWillCancelPendingOperation()
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Queue::all(1, array(1), function () use ($pending) {
            return $pending;
        });

        $promise->cancel();
    }

    public function testPendingOperationWillBeCancelledIfOneOperationRejects22222222222()
    {
        $first = new Deferred();
        $second = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Queue::all(1, array($first->promise(), $second), function ($promise) {
            return $promise;
        });

        $first->reject(new \RuntimeException());

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testPendingOperationWillBeCancelledIfOneOperationRejects()
    {
        $first = new Deferred();
        $second = new Promise(function () { }, $this->expectCallableOnce());

        $promise = Queue::all(2, array($first->promise(), $second), function ($promise) {
            return $promise;
        });

        $first->reject(new \RuntimeException());

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueuedOperationsWillStartAndCancelOneIfOneOperationRejects()
    {
        $first = new Deferred();
        $second = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });
        $third = new Promise(function () { }, $this->expectCallableOnce());
        $fourth = new Promise(function () { }, $this->expectCallableNever());

        $started = 0;
        $promise = Queue::all(2, array($first->promise(), $second, $third, $fourth), function ($promise) use (&$started) {
            ++$started;
            return $promise;
        });

        $first->reject(new \RuntimeException());

        $this->assertEquals(3, $started);
    }
}
