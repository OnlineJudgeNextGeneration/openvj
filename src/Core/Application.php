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

use Evenement\EventEmitter;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Pimple\Container;
use RandomLib\Factory;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\MessageSelector;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;
use VJ\Core\Exception\UserException;
use VJ\Core\Session\MongoDBSessionHandler;
use Whoops\Handler\JsonResponseHandler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

class Application
{
    use ContainerTrait, LoggerTrait, EventTrait, RouteTrait, MongoTrait, SessionTrait;

    public static $container;

    public static $APP_DIRECTORY;
    public static $CACHE_DIRECTORY;
    public static $CONFIG_DIRECTORY;
    public static $LOGS_DIRECTORY;
    public static $TEMPLATES_DIRECTORY;
    public static $TRANSLATION_DIRECTORY;

    public function __construct()
    {
        self::$container = new Container();

        self::$APP_DIRECTORY = dirname(dirname(__DIR__)) . '/app';
        self::$CACHE_DIRECTORY = self::$APP_DIRECTORY . '/cache';
        self::$CONFIG_DIRECTORY = self::$APP_DIRECTORY . '/config';
        self::$LOGS_DIRECTORY = self::$APP_DIRECTORY . '/logs';
        self::$TEMPLATES_DIRECTORY = self::$APP_DIRECTORY . '/templates';
        self::$TRANSLATION_DIRECTORY = self::$APP_DIRECTORY . '/translation';

        $this->initEvent();
        $this->initConfig();
        $this->initLogger();
        $this->initErrHandlers();
        $this->initMongoDB();
        $this->initRedis();
        $this->initSession();
        $this->initRandomGenerator();
        $this->initTranslation();
        $this->initHttp();
        $this->initRouter();
        $this->initTemplating();
        $this->initService();
    }

    private function initEvent()
    {
        self::set('event', function () {
            return new EventEmitter();
        });
    }

    private function initConfig()
    {
        $config = array_merge_recursive(
            self::loadConfig('config.yml'),
            self::loadConfig('db.yml')
        );
        self::set('config', $config);
    }

    public static function loadConfig($filename)
    {
        $file = self::$CONFIG_DIRECTORY . '/' . $filename;
        return Yaml::parse(file_get_contents($file));
    }

    private function initLogger()
    {
        self::set('log', function () {
            $logger = new Logger('VJ');
            if (self::get('config')['debug']) {
                $logger->pushHandler(new RotatingFileHandler(self::$LOGS_DIRECTORY . '/vj-debug', 0, Logger::DEBUG));
            }
            $logger->pushHandler(new RotatingFileHandler(self::$LOGS_DIRECTORY . '/vj-info', 0, Logger::INFO));
            $logger->pushHandler(new RotatingFileHandler(self::$LOGS_DIRECTORY . '/vj-error', 0, Logger::ERROR));
            return $logger;
        });
    }

    private function initErrHandlers()
    {
        $whoops = new Run();
        $whoops->pushHandler(new PlainTextHandler());
        $whoops->pushHandler(new JsonResponseHandler());
        $whoops->pushHandler(new PrettyPageHandler());
        $whoops->pushHandler(function (\Exception $exception) {
            if (!$exception instanceof UserException) {
                $level = Logger::ERROR;
                if ($exception instanceof \MongoConnectionException) {
                    $level = Logger::ALERT;
                }
                self::log($level, $exception->getMessage(), $exception->getTrace());
            }
        });
        $whoops->register();
    }

    private function initMongoDB()
    {
        self::set('mongo_client', function () {
            $options = [
                'connect' => true,
                'connectTimeoutMS' => self::get('config')['mongodb']['connectTimeoutMS'],
            ];
            if (isset(self::get('config')['mongodb']['username'])) {
                $options['db'] = self::get('config')['mongodb']['db'] . (MODE_TEST ? '-test' : '');
                $options['username'] = self::get('config')['mongodb']['username'];
                $options['password'] = self::get('config')['mongodb']['password'];
            }
            if (isset(self::get('config')['mongodb']['replicaSet'])) {
                $options['replicaSet'] = self::get('config')['mongodb']['replicaSet'];
            }
            $mongoClient = new \MongoClient(self::get('config')['mongodb']['server'], $options);
            return $mongoClient;
        });

        self::set('mongodb', function () {
            $mongoClient = self::get('mongo_client');
            return $mongoClient->selectDB(self::get('config')['mongodb']['db'] . (MODE_TEST ? '-test' : ''));
        });
    }

    private function initRedis()
    {
        self::set('redis', function () {
            $redis = new \Redis();
            $redis->connect(self::get('config')['redis']['host'], self::get('config')['redis']['port']);
            $redis->select(self::get('config')['redis']['db']);
            return $redis;
        });
    }

    private function initSession()
    {
        self::set('session_storage', function () {
            return new NativeSessionStorage([
                'name' => self::get('config')['session']['name'],
                'cookie_httponly' => true,
            ], new MongoDBSessionHandler(Application::coll('Session'), (int)self::get('config')['session']['ttl']));
        });

        self::set('session', function () {
            return new Session(self::get('session_storage'));
        });
    }

    private function initRandomGenerator()
    {
        self::set('random_factory', function () {
            return new Factory();
        });
        self::set('random', function () {
            return self::get('random_factory')->getLowStrengthGenerator();
        });
        self::set('random_secure', function () {
            return self::get('random_factory')->getMediumStrengthGenerator();
        });
    }

    private function initTranslation()
    {
        self::set('i18n', function () {
            $translator = new Translator(self::get('config')['translation']['default'], new MessageSelector());
            $translator->addLoader('yaml', new YamlFileLoader());
            foreach (self::get('config')['translation']['availables'] as $name) {
                $translator->addResource('yaml', self::$TRANSLATION_DIRECTORY . '/' . $name . '.yml', $name);
            }
            return $translator;
        });
    }

    private function initHttp()
    {
        self::set('request', function () {
            return Request::createFromGlobals();
        });

        self::set('response', function () {
            return new Response();
        });
    }

    private function initRouter()
    {
        self::set('dispatcher', function () {
            return \FastRoute\cachedDispatcher(function (RouteCollector $r) {
                $router = self::loadConfig('routing.yml')['routing'];
                foreach ($router as $rule) {
                    list($controller, $action) = explode(':', $rule['controller']);
                    foreach ($rule['methods'] as $method) {
                        $r->addRoute($method, $rule['path'], [
                            'controller' => strtolower($controller),
                            'action' => $action,
                            'className' => '\\VJ\\Controller\\' . ucfirst(strtolower($controller)) . 'Controller',
                            'actionName' => $action . 'Action'
                        ]);
                    }
                }
            }, [
                'cacheFile' => self::$CACHE_DIRECTORY . '/route.cache',
                'cacheDisabled' => self::get('config')['debug'],
            ]);
        });
    }

    private function initTemplating()
    {
        self::set('templating', function () {
            $loader = new \Twig_Loader_Filesystem(self::$TEMPLATES_DIRECTORY);
            $twig = new \Twig_Environment($loader, [
                'cache' => self::$CACHE_DIRECTORY,
                'debug' => self::get('config')['debug'],
            ]);
            return $twig;
        });
    }

    private function initService()
    {
        $services = Yaml::parse(file_get_contents(self::$CONFIG_DIRECTORY . '/service.yml'));
        foreach ($services['services'] as $service_name => $service_config) {
            self::set($service_name, function () use ($service_config) {
                return new $service_config['class']();
            });

            // register event listeners
            foreach ($service_config['event'] as $event) {
                self::on($event, function (...$argv) use ($service_name, $event) {
                    self::get($service_name)->onEvent($event, ...$argv);
                });
            }
        }
    }
}