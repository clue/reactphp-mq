<?php

require __DIR__ . '/../vendor/autoload.php';

// list of all URLs you want to download
// this list may potentially contain hundreds or thousands of entries
$urls = array(
    'http://www.github.com/',
    'http://www.yahoo.com/',
    'http://www.bing.com/',
    'http://www.google.com/',
    //'http://httpbin.org/delay/2',
);

$browser = new React\Http\Browser();

// each job should use the browser to GET a certain URL
// limit number of concurrent jobs here to avoid using excessive network resources
$promise = Clue\React\Mq\Queue::all(3, array_combine($urls, $urls), function ($url) use ($browser) {
    return $browser->get($url);
});

$promise->then(
    function ($responses) {
        /* @var $responses Psr\Http\Message\ResponseInterface[] */
        echo 'All URLs succeeded!' . PHP_EOL;
        foreach ($responses as $url => $response) {
            echo $url . ' has ' . $response->getBody()->getSize() . ' bytes' . PHP_EOL;
        }
    },
    function ($e) {
        echo 'An error occurred: ' . $e->getMessage() . PHP_EOL;
    }
);

