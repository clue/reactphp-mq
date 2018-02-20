<?php

use Clue\React\Block;
use Clue\React\Buzz\Browser;
use Clue\React\Mq\Queue;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Factory;

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

    $queue = new Queue(3, null, function ($url) use ($browser) {
        return $browser->get($url);
    });

    $promises = array();
    foreach ($urls as $url) {
        $promises[$url] = $queue($url)->then(
            function (ResponseInterface $response) use ($url) {
                return $response->getBody();
            },
            function (Exception $e) use ($url) {
                return null;
            }
        );
    }

    return Block\awaitAll($promises, $loop);
}

$responses = download($urls);

foreach ($responses as $url => $response) {
    echo $url . ' is ' . strlen($response) . ' bytes' . PHP_EOL;
}
