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
use Wedeto\HTTP\Response\Error as HTTPError;
use Wedeto\HTTP\Request;
use Wedeto\HTTP\StringResponse;

/**
 * @covers Wedeto\HTML\Template
 */
final class TemplateTest extends TestCase
{
    private $request;
    private $resolver;
    private $testpath;
    private $filename;

    public function setUp()
    {
        $this->request = new MockTemplateRequest;
        $this->resolver = System::resolver();

        $this->testpath = System::path()->var . '/test';
        IO\Dir::mkdir($this->testpath);
        $this->filename = tempnam($this->testpath, "wedetotest") . ".php";
    }

    public function tearDown()
    {
        IO\Dir::rmtree($this->testpath);
    }


    /**
     * @covers Wedeto\HTML\Template::__construct
     * @covers Wedeto\HTML\Template::assign
     * @covers Wedeto\HTML\Template::setTitle
     * @covers Wedeto\HTML\Template::title
     */
    public function testConstruct()
    {
        $tpl = new Template($this->request);
        $tpl->setTemplate('error/HttpError');
        $tpl->setTitle('IO Error');
        $tpl->assign('exception', new IOException('Fail'));

        $this->assertEquals('IO Error', $tpl->title());
    }

    public function testTitle()
    {
        $this->request->vhost = null;
        $this->request->route = '/';
        $tpl = new Template($this->request);

        $this->assertEquals('Default - /', $tpl->title());
    }

    public function testExisting()
    {
        $file = $this->resolver->template('error/HttpError');
        $tpl = new Template($this->request);
        $tpl->setTemplate($file);

        $this->assertEquals($file, $tpl->getTemplate());

        $this->expectException(HttpError::class);
        $this->expectExceptionMessage("Template file could not be found");
        $this->expectExceptionCode(500);

        $tpl->setTemplate('/foo/bar/baz');
    }

    public function testNoTitle()
    {
        $tpl = new Template($this->request);

        $this->assertEquals('foobar - /', $tpl->title());
    }

    public function testNoTitleNoSiteName()
    {
        $tpl = new Template($this->request);

        $s = $this->request->vhost->getSite();
        $s->setName('default');
        $this->assertEquals('/', $tpl->title());
    }

    public function testAssets()
    {
        $tpl = new Template($this->request);
        $tpl->addJS('test');
        $tpl->addCSS('test');

        $js_str = $tpl->insertJS();
        $css_str = $tpl->insertCSS();

        $this->assertEquals('#WEDETO-JAVASCRIPT#', $js_str);
        $this->assertEquals('#WEDETO-CSS#', $css_str);
    }

    public function testSetExceptionTemplate()
    {
        $tpl = new Template($this->request);

        $resolve = System::resolver(); 
        $file = $resolve->template('error/HttpError');

        $tpl->setExceptionTemplate(new HttpError(500, 'Foobarred'));
        $this->assertEquals($file, $tpl->getTemplate());

        $tpl->setExceptionTemplate(new MockTemplateHttpError(500, 'Foobarred'));
        $this->assertEquals($file, $tpl->getTemplate());
    }

    /**
     * @covers Wedeto\HTML\Template::render
     * @covers Wedeto\HTML\Template::renderReturn
     */
    public function testRender()
    {
        $tpl_file = <<<EOT
<html><head><title>Test</title></head><body><p><?=\$foo;?></p></body></html>
EOT;
        file_put_contents($this->filename, $tpl_file);

        $tpl = new Template($this->request);
        $tpl->setTemplate($this->filename);

        $tpl->assign('foo', 'bar');

        try
        {
            $tpl->render();
        }
        catch (StringResponse $e)
        {
            $actual = $e->getOutput('text/html');

            $expected = str_replace('<?=$foo;?>', 'bar', $tpl_file);
            $this->assertEquals($expected, $actual);
        }
    }

    /**
     * @covers Wedeto\HTML\Template::render
     * @covers Wedeto\HTML\Template::renderReturn
     */
    public function testRenderThrowsError()
    {
        $tpl_file = <<<EOT
<?php
throw new RuntimeException("Foo");
?>
EOT;
        file_put_contents($this->filename, $tpl_file);

        $tpl = new Template($this->request);
        $tpl->setTemplate($this->filename);

        $tpl->assign('foo', 'bar');

        $this->expectException(HttpError::class);
        $this->expectExceptionCode(500);
        $this->expectExceptionMessage("Template threw exception");
        $tpl->render();
    }

    /**
     * @covers Wedeto\HTML\Template::render
     * @covers Wedeto\HTML\Template::renderReturn
     */
    public function testRenderThrowsCustomResponse()
    {
        $tpl_file = <<<EOT
<?php
if (\$this->request->wantJSON())
{
    \$data = ['foo', 'bar'];
    throw new Wedeto\Http\DataResponse(\$data);
}
?>
EOT;
        file_put_contents($this->filename, $tpl_file);

        $tpl = new Template($this->request);
        $tpl->setTemplate($this->filename);

        $tpl->assign('foo', 'bar');

        try
        {
            $tpl->render();
        }
        catch (\Wedeto\HTTP\Response\DataResponse $e)
        {
            $actual = $e->getDictionary()->getAll();
            $expected = ['foo', 'bar'];
            $this->assertEquals($expected, $actual);
        }
    }

    /**
     * @covers Wedeto\HTML\Template::render
     * @covers Wedeto\HTML\Template::renderReturn
     */
    public function testRenderThrowsTerminateRequest()
    {
        $tpl_file = <<<EOT
<?php
throw new Wedeto\HTML\TerminateRequest("Foobar!");
?>
EOT;
        file_put_contents($this->filename, $tpl_file);

        $tpl = new Template($this->request);
        $tpl->setTemplate($this->filename);

        $this->expectException(TerminateRequest::class);
        $this->expectExceptionMessage("Foobar!");
        $tpl->render();
    }

    /**
     * @covers \txt
     */
    public function testEscaper()
    {
        $actual = \txt('some <html special& "characters');
        $expected = 'some &lt;html special&amp; &quot;characters'; 

        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers \tpl
     */
    public function testResolveTpl()
    {
        $cur = System::template();

        $this->request->setResolver(new MockTemplateResolver());
        $tpl = new Template($this->request);
        System::getInstance()->template = $tpl;

        $actual = \tpl('baz');
        $expected = '/foobar/baz';
        $this->assertEquals($expected, $actual);

        System::getInstance()->template = $cur;
    }

    public function testSetMime()
    {
        $tpl = System::template();
        $tpl->setMimeType('text/html');
        $this->assertEquals('text/html', $tpl->getMimeType());

        $tpl->setMimeType('text/plain');
        $this->assertEquals('text/plain', $tpl->getMimeType());
    }

    public function testAddStyle()
    {
        $tpl = System::template();
        $mgr = $tpl->getAssetManager();

        $my_style = '.random-foo-class {random-style: property;}';

        $tpl->addStyle($my_style);

        $styles = $mgr->getStyles();
        $found = false;
        foreach ($styles as $style)
            if ($style === $my_style)
                $found = true;

        $this->assertTrue($found);
    }

    public function testAddJSVariable()
    {
        $tpl = System::template();
        $mgr = $tpl->getAssetManager();

        $my_style = '.random-foo-class {random-style: property;}';

        $tpl->addJSVariable('foo', 'bar');
        $variables = $mgr->getVariables();
        $this->assertTrue(isset($variables['foo']));
        $this->assertEquals('bar', $variables['foo']);

        $tpl->addJSVariable('foo', 'baz');
        $variables = $mgr->getVariables();
        $this->assertTrue(isset($variables['foo']));
        $this->assertEquals('baz', $variables['foo']);
    }

    public function testGetRequest()
    {
        $dict = new Dictionary();
        $serv = array('REQUEST_URI' => '/');
        $arr = array();
        $req = new Request($arr, $arr, $arr, $serv, $dict, System::path(), System::resolver());
        
        $tpl = System::template();

        $reqsys = $tpl->getRequest();
        $this->assertInstanceOf(Http\Request::class, $reqsys);
        $this->assertNotEquals($req, $reqsys);

        $tpl = new Template($req);

        $req2 = $tpl->getRequest();
        $this->assertEquals($req, $req2);
    }

    public function testGetAssetManager()
    {
        $dict = new Dictionary();
        $serv = array('REQUEST_URI' => '/');
        $arr = array();
        $req = new Request($arr, $arr, $arr, $serv, $dict, System::path(), System::resolver());
        $tpl = new Template($req);

        $this->assertInstanceOf(AssetManager::class, $tpl->getAssetManager());
        $this->assertEquals($req->getResponseBuilder()->getAssetManager(), $tpl->getAssetManager());
    }
}

class MockTemplateResolver extends \Wedeto\Resolve\Resolver
{
    public function __construct()
    {}

    public function template(string $tpl)
    {
        return '/foobar/' . $tpl;
    }
}

class MockTemplateRequest extends Request
{
    public function __construct()
    {
        $this->resolver = System::resolver(); 
        $this->route = '/';
        $this->vhost = new MockTemplateVhost();
        $this->response_builder = new Http\ResponseBuilder($this);
        $this->config = new Dictionary();
    }
}

class MockTemplateVhost extends VirtualHost
{
    public function __construct()
    {}

    public function getSite()
    {
        if ($this->site === null)
        {
            $this->site = new Site;
            $this->site->setName('foobar');
        }
        return $this->site;
    }

}

class MockTemplateHttpError extends HttpError
{
}
