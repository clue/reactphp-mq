<?php

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

$browser = new React\Http\Browser();

// each job should use the browser to GET a certain URL
// limit number of concurrent jobs here to avoid using excessive network resources
$queue = new Clue\React\Mq\Queue(3, null, function ($url) use ($browser) {
    return $browser->get($url);
});

foreach ($urls as $url) {
    $queue($url)->then(
        function (Psr\Http\Message\ResponseInterface $response) use ($url) {
            echo $url . ' has ' . $response->getBody()->getSize() . ' bytes' . PHP_EOL;
        },
        function (Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        }
    );
}
