<?php

require __DIR__ . '/../vendor/autoload.php';

// list of all URLs you want to try
// this list may potentially contain hundreds or thousands of entries
$urls = array(
    'http://www.github.com/invalid',
    'http://www.yahoo.com/invalid',
    'http://www.bing.com/invalid',
    'http://www.bing.com/',
    'http://www.google.com/',
    'http://www.google.com/invalid',
);

$browser = new React\Http\Browser();

// each job should use the browser to GET a certain URL
// limit number of concurrent jobs here to avoid using excessive network resources
$promise = Clue\React\Mq\Queue::any(2, $urls, function ($url) use ($browser) {
    return $browser->get($url)->then(
        function (Psr\Http\Message\ResponseInterface $response) use ($url) {
            // return only the URL for the first successful response
            return $url;
        }
    );
});

$promise->then(
    function ($url) {
        echo 'First successful URL is ' . $url . PHP_EOL;
    },
    function ($e) {
        echo 'An error occurred: ' . $e->getMessage() . PHP_EOL;
    }
);

