<?php

use Clue\React\Block;
use React\EventLoop\Loop;

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
    $browser = new React\Http\Browser();

    $urls = array_combine($urls, $urls);
    $promise = Clue\React\Mq\Queue::all(3, $urls, function ($url) use ($browser) {
        return $browser->get($url)->then(
            function (Psr\Http\Message\ResponseInterface $response) {
                // return only the body for successful responses
                return $response->getBody();
            },
            function (Exception $e) {
                // return null body for failed requests
                return null;
            }
        );
    });

    return Block\await($promise, Loop::get());
}

$responses = download($urls);

foreach ($responses as $url => $response) {
    echo $url . ' is ' . strlen($response) . ' bytes' . PHP_EOL;
}
