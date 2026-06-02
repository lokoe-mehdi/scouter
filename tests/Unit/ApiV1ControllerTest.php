<?php

use App\Http\Controllers\ApiV1Controller;
use App\Http\Request;
use App\Auth\Auth;

/**
 * Guards the request-shaping helpers of the public API v1 controller: the
 * limit/offset clamping (so a client can never ask for an unbounded page) and
 * the crawl payload projection (stable public shape, ints coerced). These are
 * private helpers, exercised via reflection on a real controller instance.
 */

function makeApiController(): ApiV1Controller
{
    return new ApiV1Controller(new Auth(null, null, null, skipDb: true));
}

function makeGetRequest(array $query): Request
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/v1/projects';
    $_GET = $query;
    $_POST = [];
    return new Request();
}

function callPageParams(ApiV1Controller $c, Request $r, int $default, int $max): array
{
    $m = new ReflectionMethod($c, 'pageParams');
    $m->setAccessible(true);
    return $m->invoke($c, $r, $default, $max);
}

it('applies the default limit when none is given', function () {
    $c = makeApiController();
    [$limit, $offset] = callPageParams($c, makeGetRequest([]), 50, 200);
    expect($limit)->toBe(50);
    expect($offset)->toBe(0);
});

it('caps the limit at the hard maximum', function () {
    $c = makeApiController();
    [$limit] = callPageParams($c, makeGetRequest(['limit' => '100000']), 50, 200);
    expect($limit)->toBe(200);
});

it('floors the limit at 1 (rejects zero/negative)', function () {
    $c = makeApiController();
    expect(callPageParams($c, makeGetRequest(['limit' => '0']), 50, 200)[0])->toBe(1);
    expect(callPageParams($c, makeGetRequest(['limit' => '-5']), 50, 200)[0])->toBe(1);
});

it('never returns a negative offset', function () {
    $c = makeApiController();
    [, $offset] = callPageParams($c, makeGetRequest(['offset' => '-10']), 50, 200);
    expect($offset)->toBe(0);
});

it('passes through a valid limit/offset', function () {
    $c = makeApiController();
    [$limit, $offset] = callPageParams($c, makeGetRequest(['limit' => '25', 'offset' => '75']), 50, 200);
    expect($limit)->toBe(25);
    expect($offset)->toBe(75);
});

it('projects a crawl into the stable public payload (ints coerced, nulls preserved)', function () {
    $c = makeApiController();
    $m = new ReflectionMethod($c, 'crawlPayload');
    $m->setAccessible(true);

    $crawl = (object) [
        'id' => '542', 'project_id' => '32', 'domain' => 'example.com',
        'status' => 'finished', 'crawl_type' => 'spider',
        'urls' => '12847', 'crawled' => '12412', 'compliant' => '9981',
        'started_at' => '2026-05-20 10:00:00', 'finished_at' => null,
    ];
    $out = $m->invoke($c, $crawl);

    expect($out['id'])->toBe(542);
    expect($out['project_id'])->toBe(32);
    expect($out['urls'])->toBe(12847);
    expect($out['domain'])->toBe('example.com');
    expect($out['finished_at'])->toBeNull();
    expect($out)->toHaveKeys(['id', 'project_id', 'domain', 'status', 'crawl_type', 'urls', 'crawled', 'compliant', 'started_at', 'finished_at']);
});
