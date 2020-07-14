<?php

use Clue\React\Block;
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

function download(array $urls)
{
    $loop = Factory::create();
    $browser = new Browser($loop);

    $urls = array_combine($urls, $urls);
    $promise = Queue::all(3, $urls, function ($url) use ($browser) {
        return $browser->get($url)->then(
            function (ResponseInterface $response) {
                // return only the body for successful responses
                return $response->getBody();
            },
            function (Exception $e) {
                // return null body for failed requests
                return null;
            }
        );
    });

    return Block\await($promise, $loop);
}

$responses = download($urls);

foreach ($responses as $url => $response) {
    echo $url . ' is ' . strlen($response) . ' bytes' . PHP_EOL;
}
