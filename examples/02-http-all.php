<?php

use Clue\React\Mq\Queue;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Factory;
use React\Http\Browser;

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

$loop = Factory::create();
$browser = new Browser($loop);

// each job should use the browser to GET a certain URL
// limit number of concurrent jobs here to avoid using excessive network resources
$promise = Queue::all(3, array_combine($urls, $urls), function ($url) use ($browser) {
    return $browser->get($url);
});

$promise->then(
    function ($responses) {
        /* @var $responses ResponseInterface[] */
        echo 'All URLs succeeded!' . PHP_EOL;
        foreach ($responses as $url => $response) {
            echo $url . ' has ' . $response->getBody()->getSize() . ' bytes' . PHP_EOL;
        }
    },
    function ($e) {
        echo 'An error occured: ' . $e->getMessage() . PHP_EOL;
    }
);

$loop->run();
