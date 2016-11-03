<?php

namespace Mhkb\ApiDoc\Generators;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;

class LaravelGenerator extends AbstractGenerator
{
    /**
     * @param Route $route
     *
     * @return mixed
     */
    protected function getUri($route)
    {
        return $route->getUri();
    }

    /**
     * @param  \Illuminate\Routing\Route $route
     * @param array $bindings
     * @param array $headers
     * @param bool $withResponse
     * @param array $methods
     * @param string $locale
     * @param boolean $includeTags
     *
     * @return array
     */
    public function processRoute($route, $bindings = [], $headers = [], $withResponse = true, $methods = [], $locale = null, $includeTags = null)
    {
        $content = '';

        $routeAction = $route->getAction();
        $routeGroup = $this->getRouteGroup($routeAction['uses']);
        $routeDescription = $this->getRouteDescription($routeAction['uses']);


        if ($withResponse) {
            $response = $this->getRouteResponse($route, $bindings, $headers, $methods);
            if ($response->headers->get('Content-Type') === 'application/json') {
                $content = json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT);
            } else {
                $content = $response->getContent();
            }
        }
        
        if ($includeTags) {
            $tags = $routeDescription['tags'];
        } else {
            $tags = [];
        }

        return $this->getParameters([
            'id' => md5($route->getUri().':'.implode($methods)),
            'resource' => $routeGroup,
            'title' => $routeDescription['short'],
            'description' => $routeDescription['long'],
            'tags' => $tags,
            'methods' => $methods,
            'uri' => $route->getUri(),
            'parameters' => [],
            'response' => $content,
        ], $routeAction, $bindings, $locale);
    }

    /**
     * Call the given URI and return the Response.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  array  $parameters
     * @param  array  $cookies
     * @param  array  $files
     * @param  array  $server
     * @param  string  $content
     *
     * @return \Illuminate\Http\Response
     */
    public function callRoute($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $kernel = App::make('Illuminate\Contracts\Http\Kernel');
        App::instance('middleware.disable', true);

        $server = collect([
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ])->merge($server)->toArray();

        $request = Request::create(
            $uri, $method, $parameters,
            $cookies, $files, $this->transformHeadersToServerVars($server), $content
        );

        $response = $kernel->handle($request);

        $kernel->terminate($request, $response);

        return $response;
    }
}
