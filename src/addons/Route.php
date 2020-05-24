<?php

declare(strict_types=1);

namespace think\addons;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use think\Exception;
use think\exception\ClassNotFoundException;
use think\helper\Str;
use think\facade\Event;
use think\facade\Config;
use think\exception\HttpException;
use think\Response;

class Route
{
    /**
     * 插件路由请求
     * @param null $addon
     * @param null $controller
     * @param null $action
     * @return mixed
     */
    public static function execute($addon = null, $controller = null, $action = null)
    {
        $app = app();
        $request = $app->request;

        // 是否自动转换控制器和操作名
        $convert = Config::get('route.url_convert');
        $filter = $convert ? 'strtolower' : 'trim';
        $addon = $addon ? trim(call_user_func($filter, $addon)) : '';
        $controller = $controller ? trim(call_user_func($filter, $controller)) : 'index';
        $action = $action ? trim(call_user_func($filter, $action)) : 'index';
        Event::trigger('addons_begin', $request);
        if (empty($addon) || empty($controller) || empty($action)) {
            throw new HttpException(500, lang('addon can not be empty'));
        }

        // 设置当前请求的插件名称、控制器、操作
        $request->addon = $addon;
        $request->setController($controller)->setAction($action);

        // 获取插件基础信息
        $info = get_addons_info($addon);
        if (!$info) {
            throw new HttpException(404, lang('addon %s not found', [$addon]));
        }
        if (!$info['status']) {
            throw new HttpException(500, lang('addon %s is disabled', [$addon]));
        }

        // 监听addon_module_init
        Event::trigger('addon_module_init', $request);

        $class = get_addons_class($addon, 'controller', $controller);
        if (!$class) {
            throw new HttpException(404, lang('addon controller %s not found', [Str::studly($controller)]));
        }

        // 重写视图基础路径
        $config = Config::get('view');
        $config['view_path'] = $app->addons->getAddonsPath() . $addon . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        Config::set($config, 'view');

        // 生成控制器对象
        $instance = new $class($app);
        $vars = [];
        if (is_callable([$instance, $action])) {
            // 执行操作方法
            $call = [$instance, $action];
        } elseif (is_callable([$instance, '_empty'])) {
            // 空操作
            $call = [$instance, '_empty'];
            $action = '_empty';
            $vars = [$action];
        } else {
            // 操作不存在
            throw new HttpException(404, lang('addon action %s not found', [get_class($instance) . '->' . $action . '()']));
        }

        Event::trigger('addons_action_begin', $call);

        // 注册控制器中间件
        self::registerControllerMiddleware($instance);

        // 执行控制器中间件
        return $app->middleware->pipeline('controller')
            ->send($request)
            ->then(function () use ($instance, $action, $app, $request) {
                // 获取当前操作名
                if (is_callable([$instance, $action])) {
                    $vars = $request->param();
                    try {
                        $reflect = new ReflectionMethod($instance, $action);
                    } catch (ReflectionException $e) {
                        $reflect = new ReflectionMethod($instance, '__call');
                        $vars = [$action, $vars];
                    }
                } else {
                    // 操作不存在
                    throw new HttpException(404, 'method not exists:' . get_class($instance) . '->' . $action . '()');
                }

                $data = $app->invokeReflectMethod($instance, $reflect, $vars);

                return self::autoResponse($data);
            });
    }

    /**
     * 使用反射机制注册控制器中间件
     * @access public
     * @param object $controller 控制器实例
     * @return void
     */
    protected static function registerControllerMiddleware($controller): void
    {
        $app = app();
        $request = $app->request;

        $class = new ReflectionClass($controller);

        if ($class->hasProperty('middleware')) {
            $reflectionProperty = $class->getProperty('middleware');
            $reflectionProperty->setAccessible(true);

            $middlewares = $reflectionProperty->getValue($controller);

            foreach ($middlewares as $key => $val) {
                if (!is_int($key)) {
                    if (isset($val['only']) && !in_array($request->action(true), array_map(function ($item) {
                            return strtolower($item);
                        }, is_string($val['only']) ? explode(",", $val['only']) : $val['only']))) {
                        continue;
                    } elseif (isset($val['except']) && in_array($request->action(true), array_map(function ($item) {
                            return strtolower($item);
                        }, is_string($val['except']) ? explode(',', $val['except']) : $val['except']))) {
                        continue;
                    } else {
                        $val = $key;
                    }
                }

                if (is_string($val) && strpos($val, ':')) {
                    $val = explode(':', $val, 2);
                }

                if (!class_exists($val)) throw new ClassNotFoundException('class not exists:' . $val, $val);

                $app->middleware->controller($val);
            }
        }
    }

    /**
     * 自动响应数据输出
     * @param $data
     * @return Response
     */
    protected static function autoResponse($data): Response
    {
        $app = app();
        $request = $app->request;

        if ($data instanceof Response) {
            $response = $data;
        } elseif (!is_null($data)) {
            // 默认自动识别响应输出类型
            $type = $request->isJson() ? 'json' : 'html';
            $response = Response::create($data, $type);
        } else {
            $data = ob_get_clean();

            $content = false === $data ? '' : $data;
            $status = '' === $content && $request->isJson() ? 204 : 200;
            $response = Response::create($content, 'html', $status);
        }

        return $response;
    }
}