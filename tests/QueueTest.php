<?php

namespace Clue\Tests\React\Mq;

use Clue\React\Mq\Queue;
use React\Promise\Promise;
use React\Promise\Deferred;

class QueueTest extends TestCase
{
    /**
     * @doesNotPerformAssertions
     */
    public function testCtorWithNormalLimits()
    {
        $q = new Queue(1, 2, function () { });
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testCtorWithNoHardLimit()
    {
        $q = new Queue(1, null, function () { });
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorThrowsIfNotCallable()
    {
        new Queue(1, 2, null);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorThrowsIfConcurrencyTooLow()
    {
        new Queue(0, 2, function () { });
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorThrowsIfConcurrencyAboveLiit()
    {
        new Queue(3, 2, function () { });
    }

    public function testInvokeOnceWillInvokeHandler()
    {
        $called = 0;
        $q = new Queue(1, 2, function () use (&$called) {
            ++$called;
            return new Promise(function () { });
        });

        $q();

        $this->assertCount(1, $q);
        $this->assertEquals(1, $called);
    }

    public function testInvokeOnceWillInvokeHandlerWithArgument()
    {
        $args = null;
        $q = new Queue(1, 2, function ($param) use (&$args) {
            $args = func_get_args();
            return new Promise(function () { });
        });

        $q(42);

        $this->assertEquals(array(42), $args);
    }

    public function testInvokeOnceWillResolveWhenHandlerResolves()
    {
        $called = 0;
        $q = new Queue(1, 2, function () use (&$called) {
            return new Promise(function ($resolve) use (&$called) { $resolve(++$called); });
        });

        $q()->then($this->expectCallableOnceWith(1));

        $this->assertCount(0, $q);
        $this->assertEquals(1, $called);
    }

    public function testInvokeOnceWillRejectWhenHandlerRejects()
    {
        $called = 0;
        $q = new Queue(1, 2, function () use (&$called) {
            return new Promise(function () use (&$called) {
                ++$called;
                throw new \RuntimeException();
            });
        });

        $q()->then(null, $this->expectCallableOnce());

        $this->assertCount(0, $q);
        $this->assertEquals(1, $called);
    }

    public function testInvokeTwiceWillInvokeHandlerTwiceWhenBelowLimit()
    {
        $called = 0;
        $q = new Queue(2, 2, function () use (&$called) {
            ++$called;
            return new Promise(function () { });
        });

        $q();
        $q();

        $this->assertCount(2, $q);
        $this->assertEquals(2, $called);
    }

    public function testInvokeTwiceWillInvokeHandlerOnceWhenConcurrencyIsReached()
    {
        $called = 0;
        $q = new Queue(1, 2, function () use (&$called) {
            ++$called;
            return new Promise(function () { });
        });

        $q();
        $q();

        $this->assertCount(2, $q);
        $this->assertEquals(1, $called);
    }

    public function testInvokeTwiceWillInvokeSecondHandlerOnlyOnceConcurrencyIsBelowLimit()
    {
        $deferred = new Deferred();
        $called = 0;
        $q = new Queue(1, 2, function () use (&$called, $deferred) {
            ++$called;
            return $deferred->promise();
        });

        $q();
        $this->assertEquals(1, $called);
        $this->assertCount(1, $q);

        $q();
        $this->assertEquals(1, $called);
        $this->assertCount(2, $q);

        $deferred->resolve(1);
        $this->assertEquals(2, $called);
        $this->assertCount(0, $q);
    }

    public function testInvokeTwiceWillResolveWhenHandlerResolves()
    {
        $called = 0;
        $q = new Queue(1, 1, function () use (&$called) {
            return new Promise(function ($resolve) use (&$called) { $resolve(++$called); });
        });

        $q()->then($this->expectCallableOnceWith(1));
        $q()->then($this->expectCallableOnceWith(2));

        $this->assertCount(0, $q);
        $this->assertEquals(2, $called);
    }

    public function testInvokeTwiceWillInvokeHandlerOnceAndRejectWhenLimitIsReached()
    {
        $called = 0;
        $q = new Queue(1, 1, function () use (&$called) {
            ++$called;
            return new Promise(function () { });
        });

        $pending = $q();
        $q()->then(null, $this->expectCallableOnce());

        $this->assertEquals(1, $called);
    }

    public function testCancelOnceWillInvokePendingCancellationHandler()
    {
        $once = $this->expectCallableOnce();
        $q = new Queue(1, 2, function () use ($once) {
            return new Promise(function () { }, $once);
        });

        $q()->cancel();
    }

    public function testCancelPendingWillRejectPromiseAndRemoveJobFromQueue()
    {
        $never = $this->expectCallableNever();
        $q = new Queue(1, 2, function () use ($never) {
            return new Promise(function () { }, $never);
        });

        $pending = $q();
        $this->assertCount(1, $q);

        $second = $q();
        $this->assertCount(2, $q);

        $second->cancel();
        $second->then(null, $this->expectCallableOnce());
        $this->assertCount(1, $q);
    }

    public function testCancelPendingOperationThatWasPreviousQueuedShouldInvokeItsCancellationHandler()
    {
        $q = new Queue(1, null, function ($promise) {
            return $promise;
        });

        $deferred = new Deferred();
        $first = $q($deferred->promise());

        $second = $q(new Promise(function () { }, $this->expectCallableOnce()));

        $deferred->resolve();
        $second->cancel();
    }

    public function testCancelPendingOperationThatWasPreviouslyQueuedShouldRejectWithCancellationResult()
    {
        $q = new Queue(1, null, function ($promise) {
            return $promise;
        });

        $deferred = new Deferred();
        $first = $q($deferred->promise());

        $second = $q(new Promise(function () { }, function () { throw new \BadMethodCallException(); }));

        $deferred->resolve();
        $second->cancel();

        $second->then(null, $this->expectCallableOnceWith($this->isInstanceOf('BadMethodCallException')));
    }

    public function testCancelPendingOperationThatWasPreviouslyQueuedShouldNotRejectIfCancellationHandlerDoesNotReject()
    {
        $q = new Queue(1, null, function ($promise) {
            return $promise;
        });

        $deferred = new Deferred();
        $first = $q($deferred->promise());

        $second = $q(new Promise(function () { }, function () {  }));

        $deferred->resolve();
        $second->cancel();

        $second->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testCancelNextOperationFromFirstOperationShouldInvokeCancellationHandler()
    {
        $q = new Queue(1, null, function () {
            return new Promise(function () { }, function () {
                throw new \RuntimeException();
            });
        });

        $first = $q();
        $second = $q();

        $first->then(null, function () use ($second) {
            $second->cancel();
        });

        $first->cancel();

        $second->then(null, $this->expectCallableOnce());
    }
}
