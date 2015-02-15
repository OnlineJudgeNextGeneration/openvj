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
    use ContainerTrait, LoggerTrait, EventTrait, RouteTrait, MongoTrait, SessionTrait, TranslationTrait;

    public static $instance = null;
    public static $container;

    public static $APP_DIRECTORY;
    public static $CACHE_DIRECTORY;
    public static $CONFIG_DIRECTORY;
    public static $LOGS_DIRECTORY;
    public static $TEMPLATES_DIRECTORY;
    public static $TRANSLATION_DIRECTORY;

    /**
     * @return Application
     */
    public static function Instance()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new Application();
        }
        return $inst;
    }

    private function __construct()
    {
        if (self::$instance === null) {
            self::$instance = $this;
        } else {
            return;
        }

        self::$container = new Container();

        self::$APP_DIRECTORY = dirname(dirname(__DIR__)) . '/app';
        self::$CACHE_DIRECTORY = self::$APP_DIRECTORY . '/cache';
        self::$CONFIG_DIRECTORY = self::$APP_DIRECTORY . '/config';
        self::$LOGS_DIRECTORY = self::$APP_DIRECTORY . '/logs';
        self::$TEMPLATES_DIRECTORY = self::$APP_DIRECTORY . '/templates';
        self::$TRANSLATION_DIRECTORY = self::$APP_DIRECTORY . '/translation';

        self::initEvent();
        self::initConfig();
        self::initLogger();
        self::initErrHandlers();
        self::initMongoDB();
        self::initRedis();
        self::initSession();
        self::initRandomGenerator();
        self::initTranslation();
        self::initHttp();
        self::initRouter();
        self::initTemplating();
        self::initService();
    }

    /**
     * 载入配置文件
     *
     * @param string $filename
     * @return array
     */
    public static function loadConfig($filename)
    {
        $file = self::$CONFIG_DIRECTORY . '/' . $filename;
        return Yaml::parse(file_get_contents($file));
    }

    /**
     * 以 dot notation 路径访问配置
     *
     * @param $path
     * @return mixed|null
     */
    public static function getConfig($path)
    {
        $p = explode('.', $path);
        $context = self::get('config');
        foreach ($p as $piece) {
            if (!is_array($context) || !isset($context[$piece])) {
                return null;
            }
            $context = &$context[$piece];
        }
        return $context;
    }

    private static function initEvent()
    {
        self::set('event', function () {
            return new EventEmitter();
        });
    }

    private static function initConfig()
    {
        $config = array_merge_recursive(
            self::loadConfig('config.yml'),
            self::loadConfig('db.yml')
        );
        self::set('config', $config);
    }

    private static function initLogger()
    {
        self::set('log', function () {
            $logger = new Logger('VJ');
            if (self::get('config')['debug']) {
                $logger->pushHandler(new RotatingFileHandler(
                    self::$LOGS_DIRECTORY . '/debug' . (MODE_TEST ? '-test' : ''),
                    0, Logger::DEBUG
                ));
            }
            $logger->pushHandler(new RotatingFileHandler(
                self::$LOGS_DIRECTORY . '/info' . (MODE_TEST ? '-test' : ''),
                0, Logger::INFO
            ));
            $logger->pushHandler(new RotatingFileHandler(
                self::$LOGS_DIRECTORY . '/error' . (MODE_TEST ? '-test' : ''),
                0, Logger::ERROR
            ));
            return $logger;
        });
    }

    private static function initErrHandlers()
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
                self::log($level, $exception->getMessage(), ['trace' => $exception->getTrace()]);
            }
        });
        $whoops->register();
    }

    private static function initMongoDB()
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

    private static function initRedis()
    {
        self::set('redis', function () {
            $redis = new \Redis();
            $redis->connect(self::get('config')['redis']['host'], self::get('config')['redis']['port']);
            $redis->select(self::get('config')['redis']['db']);
            return $redis;
        });
    }

    private static function initSession()
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

    private static function initRandomGenerator()
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

    private static function initTranslation()
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

    private static function initHttp()
    {
        self::set('request', function () {
            return Request::createFromGlobals();
        });

        self::set('response', function () {
            return new Response();
        });
    }

    private static function initRouter()
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

    private static function initTemplating()
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

    private static function initService()
    {
        $services = Yaml::parse(file_get_contents(self::$CONFIG_DIRECTORY . '/service.yml'));
        foreach ($services['services'] as $service_name => $service_config) {
            self::set($service_name, function () use ($service_config) {
                $argv = [];
                if (isset($service_config['arguments'])) {
                    foreach ($service_config['arguments'] as $a) {
                        if (is_string($a) && substr($a, 0, 1) === '@') {
                            $argv[] = self::get(substr($a, 1));
                        } elseif (is_string($a) && substr($a, 0, 1) === '%') {
                            $argv[] = self::getConfig(substr($a, 1));
                        } else {
                            $argv[] = $a;
                        }
                    }
                }
                return new $service_config['class'](...$argv);
            });

            // register event listeners
            if (isset($service_config['events'])) {
                foreach ($service_config['events'] as $event) {
                    self::on($event, function (...$argv) use ($service_name, $event) {
                        self::get($service_name)->onEvent($event, ...$argv);
                    });
                }
            }
        }
    }

}