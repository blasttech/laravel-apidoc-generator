<?php

namespace Mhkb\ApiDoc\Generators;

use Exception;

class DingoGenerator extends AbstractGenerator
{
    /**
     * @param \Illuminate\Routing\Route $route
     * @param array $bindings
     * @param array $headers
     * @param bool $withResponse
     * @param array $methods
     *
     * @return array
     */
    public function processRoute($route, $bindings = [], $headers = [], $withResponse = true, $methods = [])
    {
        $response = '';

        if ($withResponse) {
            try {
                $response = $this->getRouteResponse($route, $bindings, $headers, $methods);
            } catch (Exception $e) {
            }
        }

        $routeAction = $route->getAction();
        $routeGroup = $this->getRouteGroup($routeAction['uses']);
        $routeDescription = $this->getRouteDescription($routeAction['uses']);

        return $this->getParameters([
            'id' => md5($route->uri().':'.implode($methods)),
            'resource' => $routeGroup,
            'title' => $routeDescription['short'],
            'description' => $routeDescription['long'],
            'methods' => $methods,
            'uri' => $route->uri(),
            'parameters' => [],
            'response' => $response,
        ], $routeAction, $bindings);
    }

    /**
     * {@inheritdoc}
     */
    public function callRoute($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $dispatcher = app('Dingo\Api\Dispatcher')->raw();

        collect($server)->map(function ($key, $value) use ($dispatcher) {
            $dispatcher->header($key, $value);
        });

        return call_user_func_array([$dispatcher, strtolower($method)], [$uri]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getUri($route)
    {
        return $route->uri();
    }
}
