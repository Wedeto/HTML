<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

namespace Wedeto\HTML;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Wedeto\Util\Dictionary;
use Wedeto\Resolve\SubResolver;

use Wedeto\HTTP\Request;
use Wedeto\HTTP\Result;
use Wedeto\HTTP\Responder;
use Wedeto\HTTP\Response\Error as HTTPError;
use Wedeto\HTTP\Response\StringResponse;

/**
 * @covers Wedeto\HTML\AssetManager
 */
final class AssetManagerTest extends TestCase
{
    private $devlogger;

    public function setUp()
    {
        $this->devlogger = new \Wedeto\Log\Writer\MemLogWriter(LogLevel::DEBUG);
        $logger = \Wedeto\Log\Logger::getLogger(AssetManager::class);
        $logger->addLogWriter($this->devlogger);
        AssetManager::setLogger($logger);
        $this->resolver = new MockAssetResolver;
    }

    public function tearDown()
    {
        $logger = \Wedeto\Log\Logger::getLogger(AssetManager::class);
        $logger->removeLogWriters();
    }

    /**
     * @covers Wedeto\HTML\AssetManager::__construct
     * @covers Wedeto\HTML\AssetManager::addScript
     * @covers Wedeto\HTML\AssetManager::addCSS
     * @covers Wedeto\HTML\AssetManager::injectScript
     * @covers Wedeto\HTML\AssetManager::injectCSS
     */
    public function testAssets()
    {
        $mgr = new AssetManager($this->resolver);

        $mgr->addScript('test1.min.js');
        $mgr->addScript('test1.js');
        $mgr->addScript('test1');

        $mgr->addScript('test2.js');
        $mgr->addScript('test2.min.js');
        $mgr->addScript('test2');

        $mgr->addScript('test3');
        $mgr->addScript('test3.js');
        $mgr->addScript('test3.min.js');

        $mgr->addCSS('test1.min.css');
        $mgr->addCSS('test1.css');
        $mgr->addCSS('test1');

        $mgr->addCSS('test2.css');
        $mgr->addCSS('test2');
        $mgr->addCSS('test2.min.css');

        $mgr->addCSS('test3');
        $mgr->addCSS('test3.css');
        $mgr->addCSS('test3.min.css');

        $this->assertEquals('#WEDETO-JAVASCRIPT#', $mgr->injectScript());
        $this->assertEquals('#WEDETO-CSS#', $mgr->injectCSS());

        $mgr->setMinified(false);
        $this->assertFalse($mgr->getMinified());
        $urls = $mgr->resolveAssets($mgr->getScripts(), 'js');

        $url_list = array();
        foreach ($urls as $u)
            $url_list[] = $u['url'];

        $this->assertEquals(
            array(
                '/assets/js/test1.min.js',
                '/assets/js/test2.js',
                '/assets/js/test3.js'
            ),
            $url_list
        );

        $mgr->setMinified(true);
        $this->assertTrue($mgr->getMinified());
        $urls = $mgr->resolveAssets($mgr->getScripts(), 'js');

        $url_list = array();
        foreach ($urls as $u)
            $url_list[] = $u['url'];

        $this->assertEquals(
            array(
                '/assets/js/test1.min.js',
                '/assets/js/test2.js',
                '/assets/js/test3.min.js'
            ),
            $url_list
        );

        $mgr->setMinified(true);
        $urls = $mgr->resolveAssets($mgr->getCSS(), 'css');

        $url_list = array();
        foreach ($urls as $u)
            $url_list[] = $u['url'];

        $this->assertEquals(
            array(
                '/assets/css/test1.min.css',
                '/assets/css/test2.css',
                '/assets/css/test3.min.css'
            ),
            $url_list
        );

        $mgr->setMinified(false);
        $urls = $mgr->resolveAssets($mgr->getCSS(), 'css');

        $url_list = array();
        foreach ($urls as $u)
            $url_list[] = $u['url'];

        $this->assertEquals(
            array(
                '/assets/css/test1.min.css',
                '/assets/css/test2.css',
                '/assets/css/test3.css'
            ),
            $url_list
        );
    }

    public function testGetAndSetResolver()
    {
        $mgr = new AssetManager($this->resolver);
        $this->assertSame($this->resolver, $mgr->getResolver());

        $res = new SubResolver('foo');
        $this->assertSame($mgr, $mgr->setResolver($res));
        $this->assertSame($res, $mgr->getResolver());
    }

    public function testInlineJSArrayValue()
    {
        $mgr = new AssetManager($this->resolver);

        $val = array('my' => 'json', 'var' => 3);
        $mgr->addVariable('test', $val);
        $rules = $mgr->getVariables();
        $this->assertEquals($val, $rules['test']);
    }

    public function testInlineJSScalarValue()
    {
        $mgr = new AssetManager($this->resolver);

        $expected = 3.5;
        $mgr->addVariable('test', 3.5);
        $rules = $mgr->getVariables();
        $this->assertEquals($expected, $rules['test']);
    }

    public function testInlineJSDictionary()
    {
        $mgr = new AssetManager($this->resolver);

        $dict = new Dictionary(array('a' => 3));
        $mgr->addVariable('test', $dict);
        $rules = $mgr->getVariables();
        $expected = $dict->getAll();
        $this->assertEquals($expected, $rules['test']);
    }

    public function testInlineJsArrayLike()
    {
        $mgr = new AssetManager($this->resolver);

        $dict = new Dictionary(array('a' => 3));
        $mgr->addVariable('test', $dict);
        $rules = $mgr->getVariables();
        $expected = $dict->getAll();
        $this->assertEquals($expected, $rules['test']);
    }

    public function testInlineJsJsonSerializable()
    {
        $mgr = new AssetManager($this->resolver);

        $obj = new MockAssetMgrJsonSerializable();

        $mgr->addVariable('test', $obj);
        $rules = $mgr->getVariables();
        $expected = array('foo' => 'bar', 'bar' => 'baz');
        $this->assertEquals($expected, $rules['test']);
    }

    public function testInlineJsInvalid()
    {
        $mgr = new AssetManager($this->resolver);

        $obj = new \StdClass();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid value provided for JS variable test");
        $mgr->addVariable('test', $obj);
    }

    public function testInlineJSRender()
    {
        $mgr = new AssetManager($this->resolver);

        $mgr->addVariable('var_int', 3);
        $mgr->addVariable('var_float', 3.5);
        $mgr->addVariable('var_string', 'foo');
        $mgr->addVariable('var_arr', [1, 3, 5]);

        $op = $mgr->replaceTokens('#WEDETO-JAVASCRIPT#');
        $this->assertContains('var_int = 3', $op);
        $this->assertContains('var_float = 3.5', $op);
        $this->assertContains('var_string = "foo"', $op);
        $this->assertContains('var_arr = [1,3,5];', $op);
    }

    public function testInlineCSS()
    {
        $mgr = new AssetManager(new MockAssetResolver);

        $val = 'body { bg-color: black;}';
        $val2 = 'body { bg-color: black;}';
        $mgr->addStyle($val2);
        $mgr->addStyle($val);

        $styles = $mgr->getStyles();
        $this->assertEquals(array($val2, $val), $styles);
    }

    public function testInvalidJS()
    {
        $mgr = new AssetManager(new SubResolver('assets'));
        $mgr->addScript('test4');

        $scripts = $mgr->getScripts();
        $urls = $mgr->resolveAssets($scripts, 'js');

        $url_list = array();
        foreach ($urls as $u)
            $url_list[] = $u['url'];

        $this->assertEquals(array(), $url_list);

        $log = $this->devlogger->getLog();
        $error_found = false;
        foreach ($log as $line)
            $error_found = $error_found || strpos($line, "Requested asset test4 could not be resolved");
        $this->assertTrue($error_found);
    }

    public function testExecuteHook()
    {
        $mgr = new AssetManager(new MockAssetResolver);
        $mgr->addScript('test1');
        $mgr->addScript('test2');
        $mgr->addCSS('test3');
        $mgr->setMinified(true);
        $mgr->setTidy(false);
        $this->assertFalse($mgr->getTidy());

        $req = Request::createFromGlobals();
        $responder = new Responder($req);
        $response = new StringResponse("<html><head>" . $mgr->injectCSS() . "</head><body>" . $mgr->injectScript() . "</body></html>", 'text/html');
        $result = new Result;
        $result->setResponse($response);
        $responder->setResult($result);

        $params = new Dictionary(['responder' => $responder, 'mime' => 'text/html']);
        $mgr->executeHook($params);
        $op = $response->getOutput('text/html');

        $urls = $mgr->resolveAssets($mgr->getScripts(), 'js');
        $url_list = array();
        foreach ($urls as $u)
        {
            $idx = strpos($op, $u['url']);
            $this->assertTrue($idx !== false);
        }

        $urls = $mgr->resolveAssets($mgr->getCSS(), 'css');
        $url_list = array();
        foreach ($urls as $u)
        {
            $idx = strpos($op, $u['url']);
            $this->assertTrue($idx !== false);
        }
    }

    public function testExecuteHookTidy()
    {
        // This functionality depends on presence of Tidy extension
        if (!class_exists('Tidy', false))
            return;

        $mgr = new AssetManager(new MockAssetResolver);
        $mgr->setTidy(true);
        $this->assertTrue($mgr->getTidy());

        $req = Request::createFromGlobals();
        $responder = new Responder($req);
        $response = new StringResponse("<html></head><body><h1>Foo</html>", 'text/html');
        $result = new Result;
        $result->setResponse($response);
        $responder->setResult($result);

        $params = new Dictionary(['responder' => $responder, 'mime' => 'text/html']);
        $mgr->executeHook($params);

        $expected = <<<EOT
<!DOCTYPE html>
<html>
    </head>
        <title></title>
    </head>
    <body>
        <h1>
            Foo
        </h1>
    </body>
</html>
EOT;

        $op = $response->getOutput('text/html');

        $this->assertEquals($expected, $op);
    }

    public function testExcecuteHookWithError()
    {
        $mgr = new AssetManager(new MockAssetResolver);
        $req = Request::createFromGlobals();

        $mgr->addScript('test1');

        $response = new HTTPError(404, 'Resource not found');
        $result = new Result;
        $sub = new StringResponse("<html><head>" . $mgr->injectScript() . "</head><body><h1>Not Found</h1></body></html>", 'text/html');
        $response->setResponse($sub);
        $result->setResponse($response);

        $responder = new Responder($req);
        $responder->setResult($result);

        $params = new Dictionary(['responder' => $responder, 'mime' => 'text/html']);
        $mgr->executeHook($params);

        $expected = <<<EOT
<html><head><script src="/assets/js/test1.min.js"></script></head><body><h1>Not Found</h1></body></html>
EOT;

        $op = $sub->getOutput('text/html');

        $this->assertEquals($expected, $op);
    }

    public function testResolveAssetsWithVendorPrefix()
    {
        $mgr = new AssetManager(new MockAssetResolver);
        $cache = new Dictionary;
        $mgr->setCache($cache);

        $urls = [
            ['path' => 'test1'],
            ['path' => 'vendor/test/js/test4']
        ];

        $result = $mgr->resolveAssets($urls, 'js');
        $expected = [
            ['path' => 'js/test1.min.js', 'url' => '/assets/js/test1.min.js', 'mtime' => null, 'basename' => 'test1'],
            ['path' => 'vendor/test/js/test4.min.js', 'url' => '/assets/vendor/test/js/test4.min.js', 'mtime' => null, 'basename' => 'test4'],
        ];
        $this->assertEquals($expected, $result);

        $vars = $cache->getSection('asset-mtime')->getAll();
        $this->assertTrue(array_key_exists('js/test1.min.js', $vars));
        $this->assertNull($vars['js/test1.min.js']);
        $this->assertTrue(array_key_exists('vendor/test/js/test4.min.js', $vars));
        $this->assertNull($vars['vendor/test/js/test4.min.js']);
    }
}

class MockAssetResolver extends SubResolver
{
    public function __construct()
    {}

    public function resolve(string $path)
    {
        $min = strpos($path, '.min.') !== false;
        // test1 is only available minified
        if (strpos($path, 'test1') !== false)
            return $min ? 'resolved/' . $path : null;

        // test2 is only available unminified
        if (strpos($path, 'test2') !== false)
            return $min ? null : 'resolved/' . $path;

        // test3 is available minified and unminified
        if (strpos($path, 'test3') !== false)
            return 'resolved/' . $path;

        if (substr($path, 0, 7) === 'vendor/')
            return 'vendorstorage/' . $path;

        return null;
    }

    public function template(string $path)
    {
        $res = System::resolver();
        return $res->template($path);
    }
}

class MockAssetMgrJsonSerializable implements \JSONSerializable
{
    public function jsonSerialize()
    {
        return array('foo' => 'bar', 'bar' => 'baz');
    }

}
