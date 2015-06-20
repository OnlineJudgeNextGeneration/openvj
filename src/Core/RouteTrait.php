<?php
/**
 * This file is part of openvj project.
 *
 * Copyright 2013-2015 openvj dev team.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VJ\Core;

use FastRoute\Dispatcher;
use Symfony\Component\HttpFoundation\Response;
use VJ\Core\Exception\NotFoundException;

trait RouteTrait
{
    /**
     * @throws NotFoundException
     */
    public static function route()
    {
        $dispatcher = Application::get('dispatcher');
        $request = Application::get('request');
        $response = Application::get('response');

        Application::emit('route.dispatch.before');

        //判断Event是否已经发送过响应
        if (headers_sent()) {
            return;
        }

        $urlParts = parse_url($request->getRequestUri());
        $route = $dispatcher->dispatch($request->getMethod(), $urlParts['path']);
        //[0]: Dispatch Status, [1]: handler, [2]: vars

        Application::emit('route.dispatch', $route);

        //同上
        if (headers_sent()) {
            return;
        }

        switch ($route[0]) {
            case Dispatcher::NOT_FOUND:
            case Dispatcher::METHOD_NOT_ALLOWED:

                throw new NotFoundException();
                break;

            case Dispatcher::FOUND:

                list(, $handler, $vars) = $route;
                $controller = new $handler['className']($request, $response);

                $ret = call_user_func([$controller, $handler['actionName']], $vars);
                if (headers_sent() || $ret === null) {
                    return;
                }

                if ($ret instanceof Response && $ret !== $response) {
                    // overwrite response
                    $response = $ret;
                } else {
                    if (is_string($ret)) {
                        $response->setContent($ret);
                    }
                    if ($response->headers->get('content-type') === null) {
                        $response->headers->set('content-type', 'text/html');
                    }
                    $response->setCharset('UTF-8');
                }

                $response->prepare($request);
                $response->send();
                break;
        }
    }
} 