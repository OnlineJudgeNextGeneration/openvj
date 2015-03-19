<?php
/**
 * This file is part of openvj project.
 *
 * Copyright 2013-2015 openvj dev team.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VJ\Test\EventListener;

use Symfony\Component\HttpFoundation\Cookie;
use VJ\Core\Event\GenericEvent;
use VJ\Core\Request;
use VJ\Core\Response;
use VJ\EventListener\HttpsRedirectionService;

class HttpsRedirectionServiceTest extends \PHPUnit_Framework_TestCase
{
    private $request_url = '/hello_world.php';
    private $ua_https = [
        'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.57 Safari/537.17',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    ];
    private $ua_http = [
        'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
        'BaiDuSpider',
        'Sogou web spider/4.0'
    ];

    private function getRequest($ua, $https, $get = [], $cookie = [])
    {
        $server = [
            'PHP_SELF' => '/app.php',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $this->request_url,
            'HTTP_HOST' => 'openvj.org',
            'HTTP_USER_AGENT' => $ua,
        ];

        if ($https) {
            $server['SERVER_PORT'] = 443;
            $server['HTTPS'] = 'on';
        } else {
            $server['SERVER_PORT'] = 80;
        }

        return new Request($get, [], [], $cookie, [], $server);
    }

    public function testEnforceHttpsFalseVisitHttpFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, false);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', false);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));
        }
    }

    public function testEnforceHttpsFalseVisitHttpsFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, true);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', false);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));
        }
    }

    public function testEnforceHttpsFalseVisitHttpFromSpider()
    {
        foreach ($this->ua_http as $ua) {
            $request = $this->getRequest($ua, false);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', false);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));
        }
    }

    public function testEnforceHttpsFalseVisitHttpsFromSpider()
    {
        // TODO: for spider, https => http?
        foreach ($this->ua_http as $ua) {
            $request = $this->getRequest($ua, true);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', false);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));
        }
    }

    public function testEnforceHttpsTrueVisitHttpFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, false);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEquals(
                'https://openvj.org' . $this->request_url,
                $response->headers->get('location'));
        }
    }

    public function testEnforceHttpsTrueVisitHttpsFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, true);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));
        }
    }

    public function testEnforceHttpsTrueVisitHttpFromSpider()
    {
        foreach ($this->ua_http as $ua) {
            $request = $this->getRequest($ua, false);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));
        }
    }

    public function testEnforceHttpsTrueVisitHttpsFromSpider()
    {
        // TODO: for spider, https => http?
        foreach ($this->ua_http as $ua) {
            $request = $this->getRequest($ua, true);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));
        }
    }

    public function testGetNoSSLTrueVisitHttpFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, false, ['nossl' => 'on']);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));

            $cookie = $response->headers->getCookies();
            $this->assertEquals(1, count($cookie));
            /** @var Cookie $cookie */
            $cookie = $cookie[0];

            $this->assertEquals('nossl', $cookie->getName());
            $this->assertEquals('on', $cookie->getValue());
            $this->assertEquals(0, $cookie->getExpiresTime());
        }
    }

    public function testGetNoSSLTrueVisitHttpsFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, true, ['nossl' => 'on']);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));

            $cookie = $response->headers->getCookies();
            $this->assertEquals(1, count($cookie));
            /** @var Cookie $cookie */
            $cookie = $cookie[0];

            $this->assertEquals('nossl', $cookie->getName());
            $this->assertEquals('on', $cookie->getValue());
            $this->assertEquals(0, $cookie->getExpiresTime());
        }
    }

    public function testCookieNoSSLTrueVisitHttpFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, false, [], ['nossl' => 'on']);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));

            $cookie = $response->headers->getCookies();
            $this->assertEquals(0, count($cookie));
        }
    }

    public function testCookieNoSSLTrueVisitHttpsFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, true, [], ['nossl' => 'on']);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));

            $cookie = $response->headers->getCookies();
            $this->assertEquals(0, count($cookie));
        }
    }

    public function testGetNoSSLFalseVisitHttpFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, false, ['nossl' => 'off']);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEquals(
                'https://openvj.org' . $this->request_url,
                $response->headers->get('location'));

            $cookie = $response->headers->getCookies();
            $this->assertEquals(1, count($cookie));

            /** @var Cookie $cookie */
            $cookie = $cookie[0];
            $this->assertEquals(true, $cookie->isCleared());
        }
    }

    public function testGetNoSSLFalseVisitHttpsFromBrowser()
    {
        foreach ($this->ua_https as $ua) {
            $request = $this->getRequest($ua, true, ['nossl' => 'off']);
            $response = new Response();

            $service = new HttpsRedirectionService($request, $response, 'openvj.org', true);
            $service->onEvent(new GenericEvent());
            $this->assertEmpty($response->headers->get('location'));

            $cookie = $response->headers->getCookies();
            $this->assertEquals(1, count($cookie));

            /** @var Cookie $cookie */
            $cookie = $cookie[0];
            $this->assertEquals(true, $cookie->isCleared());
        }
    }
}
 