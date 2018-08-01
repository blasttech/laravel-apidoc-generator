<?php

namespace Blasttech\ApiDoc\Generators;

use Exception;

class DingoGenerator extends AbstractGenerator
{
    /**
     * @param \Illuminate\Routing\Route $route
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

        if ($includeTags) {
            $tags = $routeDescription['tags'];
        } else {
            $tags = [];
        }

        return $this->getParameters([
            'id' => md5($route->uri().':'.implode($methods)),
            'resource' => $routeGroup,
            'title' => $routeDescription['short'],
            'description' => $routeDescription['long'],
            'tags' => $tags,
            'methods' => $methods,
            'uri' => $route->uri(),
            'parameters' => [],
            'response' => $response,
        ], $routeAction, $bindings, $locale);
    }

    /**
     * Prepares / Disables route middlewares.
     *
     * @param  bool $disable
     *
     * @return  void
     */
    public function prepareMiddleware($disable = true)
    {
        // Not needed by Dingo
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function callRoute($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $dispatcher = app('Dingo\Api\Dispatcher')->raw();

        collect($server)->map(function ($key, $value) use ($dispatcher) {
            $dispatcher->header($value, $key);
        });

        return call_user_func_array([$dispatcher, strtolower($method)], [$uri]);
    }

    /**
     * {@inheritdoc}
     */
    public function getUri($route)
    {
        return $route->uri();
    }

    /**
     * {@inheritdoc}
     */
    public function getMethods($route)
    {
        return $route->getMethods();
    }
}
