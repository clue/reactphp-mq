# Changelog

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
