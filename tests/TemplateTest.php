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
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use org\bovigo\vfs\vfsStreamDirectory;

use Wedeto\Util\Dictionary;
use Wedeto\IO\IOException;
use Wedeto\HTTP\Request;
use Wedeto\HTTP\Response\Error as HTTPError;
use Wedeto\HTTP\Response\StringResponse;
use Wedeto\Resolve\SubResolver;

/**
 * @covers Wedeto\HTML\Template
 */
final class TemplateTest extends TestCase
{
    private $resolver;
    private $testpath;
    private $filename;

    public function setUp()
    {
        $this->resolver = new MockTemplateResolver;

        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('tpldir'));
        $this->testpath = vfsStream::url('tpldir');
        $this->filename = $this->testpath . '/' . sha1(random_int(0, 1000)) . ".php";
        Template::setInstance();
    }

    /**
     * @covers Wedeto\HTML\Template::__construct
     * @covers Wedeto\HTML\Template::assign
     * @covers Wedeto\HTML\Template::setTitle
     * @covers Wedeto\HTML\Template::title
     */
    public function testConstruct()
    {
        $tpl = new Template($this->resolver);
        $this->assertSame($tpl, Template::getInstance());
        $tpl->setTemplate('error/HTTPError');
        $tpl->setTitle('IO Error');
        $tpl->assign('exception', new IOException('Fail'));

        $this->assertEquals('IO Error', $tpl->title());
    }

    public function testGetInstanceThrowsExceptionWhenNoneWasSet()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Accessing Template instance before constructing");
        Template::getInstance();
    }

    public function testGetResolver()
    {
        $tpl = new Template($this->resolver);
        $this->assertSame($this->resolver, $tpl->getResolver());

        $res = new SubResolver('foo');
        $this->assertSame($tpl, $tpl->setResolver($res), "Fluent interface does not work");
        $this->assertSame($res, $tpl->getResolver());
    }

    public function testTitle()
    {
        $tpl = new Template($this->resolver);

        $this->assertEquals('Unnamed Page', $tpl->title());
    }

    public function testExisting()
    {
        $filename = basename($this->filename);
        file_put_contents($this->filename, "<?php\necho 'foo bar';\n");

        $this->resolver = new SubResolver("template");
        $this->resolver->addToSearchPath("template", $this->testpath, 0);

        $file = $this->resolver->resolve($filename);

        $tpl = new Template($this->resolver);
        $tpl->setTemplate($file);

        $this->assertEquals($file, $tpl->getTemplate());

        $this->expectException(HTTPError::class);
        $this->expectExceptionMessage("Template file could not be found");
        $this->expectExceptionCode(500);

        $tpl->setTemplate('/foo/bar/baz');
    }

    public function testNoTitle()
    {
        $tpl = new Template($this->resolver);

        $this->assertEquals('Unnamed Page', $tpl->title());
    }

    public function testAssets()
    {
        $tpl = new Template($this->resolver);
        $tpl->addJS('test');
        $tpl->addCSS('test');

        $js_str = $tpl->insertJS();
        $css_str = $tpl->insertCSS();

        $this->assertEquals('#WEDETO-JAVASCRIPT#', $js_str);
        $this->assertEquals('#WEDETO-CSS#', $css_str);
    }

    public function testSetExceptionTemplate()
    {
        $tpl = new Template($this->resolver);

        $file = $this->resolver->resolve('error/HTTPError500');
        $tpl->setExceptionTemplate(new HTTPError(500, 'Foobarred'));
        $this->assertEquals($file, $tpl->getTemplate(), "HTTP Error uses shortcut URL");

        $file = $this->resolver->resolve('error/Wedeto/HTML/MockTemplateHTTPError500');
        $tpl->setExceptionTemplate(new MockTemplateHTTPError(500, 'Foobarred'));
        $this->assertEquals($file, $tpl->getTemplate(), "Custom exception class uses full name space for resolution");

        $file = $this->resolver->resolve('error/Wedeto/HTML/MockTemplateHTTPError');
        $tpl->setExceptionTemplate(new MockTemplateHTTPError(0, 'Foobarred'));
        $this->assertEquals($file, $tpl->getTemplate(), "Empty error code removes error code from path");

        $file = $this->resolver->resolve('error/Wedeto/HTML/MockTemplateHTTPError');
        $tpl->setExceptionTemplate(new MockTemplateSubHTTPError(0, 'Foobarred'));
        $this->assertEquals($file, $tpl->getTemplate(), "Non-existing exception template delegates to parent class");
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

        $tpl = new Template($this->resolver);
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

        $tpl = new Template($this->resolver);
        $tpl->setTemplate($this->filename);

        $tpl->assign('foo', 'bar');

        $this->expectException(HTTPError::class);
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
\$data = ['foo', 'bar'];
throw new Wedeto\HTTP\Response\DataResponse(\$data);
?>
EOT;
        file_put_contents($this->filename, $tpl_file);

        $tpl = new Template($this->resolver);
        $tpl->setTemplate($this->filename);

        $tpl->assign('foo', 'bar');

        try
        {
            $tpl->render();
        }
        catch (\Wedeto\HTTP\Response\DataResponse $e)
        {
            $actual = $e->getData()->getAll();
            $expected = ['foo', 'bar'];
            $this->assertEquals($expected, $actual);
        }
    }

    /**
     * @covers \txt
     */
    public function testEscaper()
    {
        $actual = \txt('some <html special& "characters');
        $expected = 'some &lt;html special&amp; &quot;characters'; 

        $this->assertEquals($expected, $actual);


        $actual = \txt(function () {
            ?>
            THIS IS MY HTML OUTPUT <>
            <?php
        });

        $expected = 'THIS IS MY HTML OUTPUT &lt;&gt;';

        $this->assertEquals($expected, trim($actual));

        $actual = \txt(['foo' => 'bar']);
        $expected = '[&apos;foo&apos; =&gt; bar]';
        $this->assertEquals($expected, trim($actual));
    }

    /**
     * @covers \tpl
     */
    public function testResolveTpl()
    {
        $tpl = new Template($this->resolver);

        $actual = \tpl('baz');
        $expected = '/foobar/baz';
        $this->assertEquals($expected, $actual);
    }

    public function testSetMime()
    {
        $tpl = new Template($this->resolver);
        $tpl->setMimeType('text/html');
        $this->assertEquals('text/html', $tpl->getMimeType());

        $tpl->setMimeType('text/plain');
        $this->assertEquals('text/plain', $tpl->getMimeType());
    }

    public function testAddStyle()
    {
        $tpl = new Template($this->resolver);
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
        $tpl = new Template($this->resolver);
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

    /**
     * @covers \URL
     */
    public function testURL()
    {
        $tpl = new Template($this->resolver);
        $this->assertSame($tpl, $tpl->setURLPrefix('/foo/bar/'));
        $this->assertEquals('/foo/bar/', $tpl->getURLPrefix());
        $this->assertEquals('/foo/bar/test', $tpl->URL('test'));

        $this->assertEquals('/foo/bar/test', \URL('test'));
    }
}

class MockTemplateResolver extends SubResolver
{
    public function __construct()
    {}

    public function resolve(string $tpl)
    {
        if ($tpl === 'error/Wedeto/HTML/MockTemplateSubHTTPError')
            return null;

        return '/foobar/' . $tpl;
    }
}

class MockTemplateHTTPError extends HTTPError
{}

class MockTemplateSubHTTPError extends MockTemplateHTTPError
{}
