<?php

use App\Http\Router;
use App\Http\Request;
use App\Http\Response;
use App\Auth\Auth;

/**
 * Tests pour le Router HTTP
 */

beforeEach(function () {
    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_GET = [];
    $_POST = [];
});

describe('Request - URI Parsing', function () {

    it('parses simple URI', function () {
        $_SERVER['REQUEST_URI'] = '/api/test';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $request = new Request();
        
        expect($request->uri())->toBe('/test');
        expect($request->method())->toBe('GET');
    });

    it('extracts route parameters', function () {
        $_SERVER['REQUEST_URI'] = '/api/users/123';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $request = new Request();
        $request->setRouteParams(['id' => '123']);
        
        expect($request->param('id'))->toBe('123');
    });

    it('parses query string parameters', function () {
        $_SERVER['REQUEST_URI'] = '/api/test?foo=bar&limit=10';
        $_GET = ['foo' => 'bar', 'limit' => '10'];
        
        $request = new Request();
        
        expect($request->query('foo'))->toBe('bar');
        expect($request->query('limit'))->toBe('10');
        expect($request->get('foo'))->toBe('bar');
    });

    it('parses POST data', function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['name' => 'Test', 'value' => '42'];
        
        $request = new Request();
        
        expect($request->post('name'))->toBe('Test');
        expect($request->get('value'))->toBe('42');
    });

    it('removes /api prefix from URI', function () {
        $_SERVER['REQUEST_URI'] = '/api/categories';
        
        $request = new Request();
        
        expect($request->uri())->toBe('/categories');
    });

    it('handles URI with query string', function () {
        $_SERVER['REQUEST_URI'] = '/api/projects?page=1&limit=10';
        
        $request = new Request();
        
        expect($request->uri())->toBe('/projects');
    });

    it('handles root URI', function () {
        $_SERVER['REQUEST_URI'] = '/api/';
        
        $request = new Request();
        
        expect($request->uri())->toBe('/');
    });

});

describe('Router - Method Detection', function () {

    it('detects GET method', function () {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $request = new Request();
        
        expect($request->method())->toBe('GET');
        expect($request->isMethod('GET'))->toBeTrue();
        expect($request->isMethod('POST'))->toBeFalse();
    });

    it('detects POST method', function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $request = new Request();
        
        expect($request->method())->toBe('POST');
        expect($request->isMethod('POST'))->toBeTrue();
    });

    it('detects PUT method', function () {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        
        $request = new Request();
        
        expect($request->method())->toBe('PUT');
        expect($request->isMethod('PUT'))->toBeTrue();
    });

    it('detects DELETE method', function () {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        
        $request = new Request();
        
        expect($request->method())->toBe('DELETE');
        expect($request->isMethod('DELETE'))->toBeTrue();
    });

});

describe('Router - Request Data', function () {

    it('has() returns true for existing parameter', function () {
        $_GET = ['filter' => 'active'];
        
        $request = new Request();
        
        expect($request->has('filter'))->toBeTrue();
        expect($request->has('missing'))->toBeFalse();
    });

    it('get() returns default value for missing parameter', function () {
        $_GET = [];
        
        $request = new Request();
        
        expect($request->get('missing', 'default'))->toBe('default');
        expect($request->get('missing'))->toBeNull();
    });

    it('all() returns all parameters', function () {
        $_GET = ['a' => '1'];
        $_POST = ['b' => '2'];
        
        $request = new Request();
        
        $all = $request->all();
        expect($all)->toHaveKey('a');
        expect($all)->toHaveKey('b');
    });

});

describe('Auth Integration', function () {

    it('Auth can be created with skipDb', function () {
        $auth = new Auth(null, null, null, skipDb: true);
        
        expect($auth)->toBeInstanceOf(Auth::class);
        expect($auth->isLoggedIn())->toBeFalse();
    });

});
