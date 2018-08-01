<?php

namespace Blasttech\ApiDoc\Commands;

use ReflectionClass;
use Illuminate\Console\Command;
use Mpociot\Reflection\DocBlock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Mpociot\Documentarian\Documentarian;
use Blasttech\ApiDoc\Postman\CollectionWriter;
use Blasttech\ApiDoc\Generators\DingoGenerator;
use Blasttech\ApiDoc\Generators\LaravelGenerator;
use Blasttech\ApiDoc\Generators\AbstractGenerator;

class GenerateDocumentation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:generate 
                            {--output=public/docs : The output path for the generated documentation}
                            {--routePrefix= : The route prefix to use for generation}
                            {--routes=* : The route names to use for generation}
                            {--middleware= : The middleware to use for generation}
                            {--noResponseCalls : Disable API response calls}
                            {--noPostmanCollection : Disable Postman collection creation}
                            {--useMiddlewares : Use all configured route middlewares}
                            {--actAsUserId= : The user ID to use for API response calls}
                            {--router=laravel : The router to be used (Laravel or Dingo)}
                            {--force : Force rewriting of existing routes}
                            {--bindings= : Route Model Bindings}
                            {--methods= : Allowed methods}
                            {--locale= : Faker locale }
                            {--tags : include API URL tags descriptions from phpdocs}  
                            {--header=* : Custom HTTP headers to add to the example requests. Separate the header name and value with ":"}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate your API documentation from existing Laravel routes.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return false|null
     */
    public function handle()
    {
        if ($this->option('router') === 'laravel') {
            $generator = new LaravelGenerator();
        } else {
            $generator = new DingoGenerator();
        }

        $allowedRoutes = $this->option('routes');
        $routePrefix = $this->option('routePrefix');
        $middleware = $this->option('middleware');
        $includeTags = ($this->option('tags') !== null);
        if ($this->option('methods') === null) {
            $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'HEAD'];
        } else {
            $allowedMethods = explode(',', $this->option('methods'));
        }

        $this->setUserToBeImpersonated($this->option('actAsUserId'));

        if ($routePrefix === null && ! count($allowedRoutes) && $middleware === null) {
            $this->error('You must provide either a route prefix or a route or a middleware to generate the documentation.');

            return false;
        }

        $generator->prepareMiddleware($this->option('useMiddlewares'));

        if ($this->option('router') === 'laravel') {
            $parsedRoutes = $this->processLaravelRoutes($generator, $allowedRoutes, $routePrefix, $middleware, $allowedMethods, $includeTags);
        } else {
            $parsedRoutes = $this->processDingoRoutes($generator, $allowedRoutes, $routePrefix, $middleware, $allowedMethods, $includeTags);
        }
        $parsedRoutes = collect($parsedRoutes)->groupBy('resource')->sort(function ($a, $b) {
            return strcmp($a->first()['resource'], $b->first()['resource']);
        });

        $this->writeMarkdown($parsedRoutes);
    }

    /**
     * @param  Collection $parsedRoutes
     *
     * @return void
     */
    private function writeMarkdown($parsedRoutes)
    {
        $outputPath = $this->option('output');
        $targetFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'index.md';
        $compareFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'.compare.md';

        $infoText = view('apidoc::partials.info')
            ->with('outputPath', ltrim($outputPath, 'public/'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'));

        $parsedRouteOutput = $parsedRoutes->map(function ($routeGroup) {
            return $routeGroup->map(function ($route) {
                $route['output'] = (string) view('apidoc::partials.route')->with('parsedRoute', $route)->render();

                return $route;
            });
        });

        $frontmatter = view('apidoc::partials.frontmatter');
        /*
         * In case the target file already exists, we should check if the documentation was modified
         * and skip the modified parts of the routes.
         */
        if (file_exists($targetFile) && file_exists($compareFile)) {
            $generatedDocumentation = file_get_contents($targetFile);
            $compareDocumentation = file_get_contents($compareFile);

            if (preg_match('/<!-- START_INFO -->(.*)<!-- END_INFO -->/is', $generatedDocumentation, $generatedInfoText)) {
                $infoText = trim($generatedInfoText[1], "\n");
            }

            if (preg_match('/---(.*)---\\s<!-- START_INFO -->/is', $generatedDocumentation, $generatedFrontmatter)) {
                $frontmatter = trim($generatedFrontmatter[1], "\n");
            }

            $parsedRouteOutput->transform(function ($routeGroup) use ($generatedDocumentation, $compareDocumentation) {
                return $routeGroup->transform(function ($route) use ($generatedDocumentation, $compareDocumentation) {
                    if (preg_match('/<!-- START_'.$route['id'].' -->(.*)<!-- END_'.$route['id'].' -->/is', $generatedDocumentation, $routeMatch)) {
                        $routeDocumentationChanged = (preg_match('/<!-- START_'.$route['id'].' -->(.*)<!-- END_'.$route['id'].' -->/is', $compareDocumentation, $compareMatch) && $compareMatch[1] !== $routeMatch[1]);
                        if ($routeDocumentationChanged === false || $this->option('force')) {
                            if ($routeDocumentationChanged) {
                                $this->warn('Discarded manual changes for route ['.implode(',', $route['methods']).'] '.$route['uri']);
                            }
                        } else {
                            $this->warn('Skipping modified route ['.implode(',', $route['methods']).'] '.$route['uri']);
                            $route['modified_output'] = $routeMatch[0];
                        }
                    }

                    return $route;
                });
            });
        }

        $documentarian = new Documentarian();

        $markdown = view('apidoc::documentarian')
            ->with('writeCompareFile', false)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('outputPath', $this->option('output'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'))
            ->with('parsedRoutes', $parsedRouteOutput);

        if (! is_dir($outputPath)) {
            $documentarian->create($outputPath);
        }

        // Write output file
        file_put_contents($targetFile, $markdown);

        // Write comparable markdown file
        $compareMarkdown = view('apidoc::documentarian')
            ->with('writeCompareFile', true)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('outputPath', $this->option('output'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'))
            ->with('parsedRoutes', $parsedRouteOutput);

        file_put_contents($compareFile, $compareMarkdown);

        $this->info('Wrote index.md to: '.$outputPath);

        $this->info('Generating API HTML code');

        $documentarian->generate($outputPath);

        $this->info('Wrote HTML documentation to: '.$outputPath.'/public/index.html');

        if ($this->option('noPostmanCollection') !== true) {
            $this->info('Generating Postman collection');

            file_put_contents($outputPath.DIRECTORY_SEPARATOR.'collection.json', $this->generatePostmanCollection($parsedRoutes));
        }
    }

    /**
     * @return array
     */
    private function getBindings()
    {
        $bindings = $this->option('bindings');
        if (empty($bindings)) {
            return [];
        }
        $bindings = explode('|', $bindings);
        $resultBindings = [];
        foreach ($bindings as $binding) {
            list($name, $id) = explode(',', $binding);
            $resultBindings[$name] = $id;
        }

        return $resultBindings;
    }

    /**
     * @param $actAs
     */
    private function setUserToBeImpersonated($actAs)
    {
        if (! empty($actAs)) {
            if (version_compare($this->laravel->version(), '5.2.0', '<')) {
                $userModel = config('auth.model');
                $user = $userModel::find((int) $actAs);
                $this->laravel['auth']->setUser($user);
            } else {
                $userModel = config('auth.providers.users.model');
                $user = $userModel::find((int) $actAs);
                $this->laravel['auth']->guard()->setUser($user);
            }
        }
    }

    /**
     * @return mixed
     */
    private function getRoutes()
    {
        if ($this->option('router') === 'laravel') {
            return Route::getRoutes();
        } else {
            return app('Dingo\Api\Routing\Router')->getRoutes()[$this->option('routePrefix')];
        }
    }

    /**
     * @param $route
     * @param array $allowedMethods
     * @return mixed
     */
    public function getMethods($route, $allowedMethods = [])
    {
        $methods = $route->methods();

        foreach ($methods as $key => $method) {
            if (!in_array($method, $allowedMethods)) {
                unset($methods[$key]);
            }
        }

        return $methods;
    }

    /**
     * @param AbstractGenerator $generator
     * @param $allowedRoutes
     * @param $routePrefix
     * @param $middleware
     * @param $allowedMethods
     * @param $includeTags
     *
     * @return array
     */
    private function processLaravelRoutes(AbstractGenerator $generator, $allowedRoutes, $routePrefix, $middleware, $allowedMethods, $includeTags)
    {
        $withResponse = $this->option('noResponseCalls') === false;
        $routes = $this->getRoutes();
        $bindings = $this->getBindings();
        //Per route binding
        $routeBindings = config('api.bindings');
        $routeResponses = [];

        $parsedRoutes = [];
        foreach ($routes as $route) {
            $routeName = $route->getName();

            if (in_array($routeName, $allowedRoutes) || str_is($routePrefix, $generator->getUri($route)) || in_array($middleware, $route->middleware())) {
                $methods = $this->getMethods($route, $allowedMethods);
                if ($this->isValidRoute($route) && $this->isRouteVisibleForDocumentation($route->getAction()['uses'])) {
                    if ($routeBindings && !empty($routeBindings[$routeName])) {
                        $currentBindings = $routeBindings[$routeName];
                        foreach ($currentBindings as $key => $currentBinding) {//Search for values like: RouteName@data.somevalue and replace for values of previous responses
                            $currentBindings[$key] = preg_replace_callback("/{(\w+)@(.+)}/", function($m) use ($routeResponses) {
                                return $routeResponses[$m[1]][$m[2]] ?? '';
                            }, $currentBinding);
                        }
                    } else {
                        $currentBindings = $bindings;
                    }
                    $currentHeader = $currentBindings['@header'] ?? $this->option('header');
                    $parsedRoute = $generator->processRoute($route, $currentBindings, $currentHeader, $withResponse, $methods, $this->option('locale'), $includeTags);
                    //Store the responses, might be used later
                    $routeResponses[$routeName] = array_dot(@json_decode($parsedRoute['response'], true) ?: []);
                    $parsedRoutes[] = $parsedRoute;
                    $this->info('Processed route: ['.implode(',', $methods) .'] '.$generator->getUri($route));
                } else {
                    $this->warn('Skipping route: ['.implode(',', $methods).'] '.$generator->getUri($route));
                }
            }
        }

        return $parsedRoutes;
    }

    /**
     * @param AbstractGenerator $generator
     * @param $allowedRoutes
     * @param $routePrefix
     * @param $middleware
     * @param $allowedMethods
     * @param $includeTags
     *
     * @return array
     */
    private function processDingoRoutes(AbstractGenerator $generator, $allowedRoutes, $routePrefix, $middleware, $allowedMethods, $includeTags)
    {
        $withResponse = $this->option('noResponseCalls') === false;
        $routes = $this->getRoutes();
        $bindings = $this->getBindings();
        $parsedRoutes = [];
        foreach ($routes as $route) {
            if (empty($allowedRoutes) || in_array($route->getName(), $allowedRoutes) || str_is($routePrefix, $route->uri()) || in_array($middleware, $route->middleware())) {
                if ($this->isValidRoute($route) && $this->isRouteVisibleForDocumentation($route->getAction()['uses'])) {
                    $methods = $this->getMethods($route, $allowedMethods);
                    $parsedRoutes[] = $generator->processRoute($route, $bindings, $this->option('header'), $withResponse, $methods, $this->option('locale'), $includeTags);
                    $this->info('Processed route: ['.implode(',', $this->getMethods($route, $allowedMethods)).'] '.$route->uri());
                } else {
                    $this->warn('Skipping route: ['.implode(',', $this->getMethods($route, $allowedMethods)).'] '.$route->uri());
                }
            }
        }

        return $parsedRoutes;
    }

    /**
     * @param $route
     *
     * @return bool
     */
    private function isValidRoute($route)
    {
        return ! is_callable($route->getAction()['uses']) && ! is_null($route->getAction()['uses']);
    }

    /**
     * @param $route
     *
     * @return bool
     */
    private function isRouteVisibleForDocumentation($route)
    {
        list($class, $method) = explode('@', $route);
        $reflection = new ReflectionClass($class);
        $comment = $reflection->getMethod($method)->getDocComment();
        if ($comment) {
            $phpdoc = new DocBlock($comment);

            return collect($phpdoc->getTags())
                ->filter(function ($tag) use ($route) {
                    return $tag->getName() === 'hideFromAPIDocumentation';
                })
                ->isEmpty();
        }

        return true;
    }

    /**
     * Generate Postman collection JSON file.
     *
     * @param Collection $routes
     *
     * @return string
     */
    private function generatePostmanCollection(Collection $routes)
    {
        $writer = new CollectionWriter($routes);

        return $writer->getCollection();
    }
}
