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
    'http://www.bing.com/invalid',
    'http://www.google.com/',
);

$browser = new Browser();

// each job should use the browser to GET a certain URL
// limit number of concurrent jobs here to avoid using excessive network resources
$queue = new Queue(3, null, function ($url) use ($browser) {
    return $browser->get($url);
});

foreach ($urls as $url) {
    $queue($url)->then(
        function (ResponseInterface $response) use ($url) {
            echo $url . ' has ' . $response->getBody()->getSize() . ' bytes' . PHP_EOL;
        },
        function (Exception $e) use ($url) {
            echo $url . ' failed: ' . $e->getMessage() . PHP_EOL;
        }
    );
}

