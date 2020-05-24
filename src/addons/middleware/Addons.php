<?php

declare(strict_types=1);

namespace think\addons\middleware;

use think\App;

/**
 * Class Addons
 * @package think\addons\middleware
 */
class Addons
{
    protected $app;

    /**
     * Addons constructor.
     * @param App $app
     */
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 插件中间件
     * @param $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, \Closure $next)
    {
        hook('addon_middleware', $request);

        return $next($request);
    }
}