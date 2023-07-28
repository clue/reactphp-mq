# Changelog

## 1.6.0 (2023-07-28)

*   Feature: Improve Promise v3 support and use template types.
    (#41 and #42 by @clue)

*   Feature: Improve PHP 8.2+ support by refactoring queuing logic.
    (#43 by @clue)

*   Improve test suite, ensure 100% code coverage and report failed assertions.
    (#37 and #39 by @clue)

## 1.5.0 (2022-09-30)

*   Feature: Forward compatibility with upcoming Promise v3.
    (#33 by @clue)

*   Update to use new reactphp/async package instead of clue/reactphp-block.
    (#34 by @SimonFrings)

## 1.4.0 (2021-11-15)

*   Feature: Support PHP 8.1, avoid deprecation warning concerning `\Countable::count(...)` return type.
    (#32 by @bartvanhoutte)

*   Improve documentation and simplify examples by updating to new [default loop](https://reactphp.org/event-loop/#loop).
    (#27 and #29 by @PaulRotmann and #30 by @SimonFrings)

*   Improve test suite to use GitHub actions for continuous integration (CI).
    (#28 by @SimonFrings)

## 1.3.0 (2020-10-16)

*   Enhanced documentation for ReactPHP's new HTTP client and
    add support / sponsorship info.
    (#21 and #24 by @clue)

*   Improve test suite and add `.gitattributes` to exclude dev files from exports.
    Prepare PHP 8 support, update to PHPUnit 9 and simplify test matrix.
    (#22, #23 and #25 by @SimonFrings)

## 1.2.0 (2019-12-05)

*   Feature: Add `any()` helper to await first successful fulfillment of operations.
    (#18 by @clue)

    ```php
    // new: limit concurrency while awaiting any operation to complete
    $promise = Queue::any(3, $urls, function ($url) use ($browser) {
        return $browser->get($url);
    });

    $promise->then(function (ResponseInterface $response) {
        echo 'First successful: ' . $response->getStatusCode() . PHP_EOL;
    });
    ```

*   Minor documentation improvements (fix syntax issues and typos) and update examples.
    (#9 and #11 by @clue and #15 by @holtkamp)

*   Improve test suite to test against PHP 7.4 and PHP 7.3, drop legacy HHVM support,
    update distro on Travis and update project homepage.
    (#10 and #19 by @clue)

## 1.1.0 (2018-04-30)

*   Feature: Add `all()` helper to await successful fulfillment of all operations
    (#8 by @clue)

    ```php
    // new: limit concurrency while awaiting all operations to complete
    $promise = Queue::all(3, $urls, function ($url) use ($browser) {
        return $browser->get($url);
    });

    $promise->then(function (array $responses) {
        echo 'All ' . count($responses) . ' successful!' . PHP_EOL;
    });
    ```

*   Fix: Implement cancellation forwarding for previously queued operations
    (#7 by @clue)

## 1.0.0 (2018-02-26)

*   First stable release, following SemVer

    I'd like to thank [Bergfreunde GmbH](https://www.bergfreunde.de/), a German
    online retailer for Outdoor Gear & Clothing, for sponsoring the first release! 🎉
    Thanks to sponsors like this, who understand the importance of open source
    development, I can justify spending time and focus on open source development
    instead of traditional paid work.

    > Did you know that I offer custom development services and issuing invoices for
      sponsorships of releases and for contributions? Contact me (@clue) for details.
