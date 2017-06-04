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

use JSONSerializable;
use InvalidArgumentException;

use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\Util\Functions as WF;

use Wedeto\HTTP\Request;
use Wedeto\HTTP\Response\Response;
use Wedeto\HTTP\Response\StringResponse;

use Wedeto\Resolve\Resolver;

class AssetManager
{
    use LoggerAwareStaticTrait;

    protected $scripts = array();
    protected $css = array();
    protected $minified = true;
    protected $tidy = false;
    protected $vhost;
    protected $resolver;

    protected $inline_variables = array();
    protected $inline_style = array();

    public function __construct(VirtualHost $vhost, Resolver $resolver)
    {
        self::getLogger();
        $this->vhost = $vhost;
        $this->resolver = $resolver;
    }

    public function getVirtualHost()
    {
        return $this->vhost;
    }

    public function setVirtualHost(VirtualHost $vhost)
    {
        $this->vhost = $vhost;
        return $this;
    }

    public function getResolver()
    {
        return $this->resolver;
    }

    public function setResolver(Resolver $resolver)
    {
        $this->resolver = $resolver;
        return $this;
    }
    
    public function setMinified(bool $minified)
    {
        $this->minified = $minified;
        return $this;
    }

    public function getMinified()
    {
        return $this->minified;
    }

    public function setTidy(bool $tidy)
    {
        $this->tidy = $tidy;
        return $this;
    }

    public function getTidy()
    {
        return $this->tidy;
    }

    public function addScript(string $script)
    {
        $script = $this->stripSuffix($script, ".min", ".js");
        $this->scripts[$script] = array("path" => $script);
        return $this;
    }

    public function getScripts()
    {
        return array_values($this->scripts);
    }

    public function addStyle($style)
    {
        $this->inline_style[] = $style;
    }

    public function getStyles()
    {
        return array_values($this->inline_style);
    }

    public function addVariable(string $name, $value)
    {
        // Convert value to something usable in the output
        if (WF::is_array_like($value))
        {
            $value = WF::to_array($value);
        }
        elseif (is_subclass_of($value, JSONSerializable::class))
        {
            $value = $value->jsonSerialize();
        }
        elseif (!is_scalar($value))
        {
            throw new InvalidArgumentException("Invalid value provided for JS variable $name");
        }

        $this->inline_variables[$name] = $value;
        return $this;
    }

    public function getVariables()
    {
        return $this->inline_variables;
    }

    public function addCSS(string $stylesheet, $media = "screen")
    {
        $stylesheet = $this->stripSuffix($stylesheet, ".min", ".css");
        $this->css[$stylesheet] = array("path" => $stylesheet, "media" => $media);
        return $this;
    }

    public function getCSS()
    {
        return array_values($this->css);
    }
    
    private function stripSuffix($path, $suffix1, $suffix2)
    {
        if (substr($path, -strlen($suffix2)) === $suffix2)
            $path = substr($path, 0, -strlen($suffix2));
        if (substr($path, -strlen($suffix1)) === $suffix1)
            $path = substr($path, 0, -strlen($suffix1));
        return $path;
    }

    public function injectScript()
    {
        return "#WEDETO-JAVASCRIPT#";
    }

    public function injectCSS()
    {
        return "#WEDETO-CSS#";
    }

    public function resolveAssets(array $list, $type)
    {
        $urls = array();
        foreach ($list as $asset)
        {
            $relpath = $type . "/" . $asset['path'];
            $unminified_path = $relpath . "." . $type;
            $minified_path = $relpath . ".min." . $type;
            $unminified_file = $this->resolver->asset($unminified_path);
            $minified_file = $this->resolver->asset($minified_path);

            $asset_path = null;
            if (!$this->minified && $unminified_file)
                $asset_path = "/assets/" .$unminified_path;
            elseif ($minified_file)
                $asset_path = "/assets/" . $minified_path;
            elseif ($unminified_file)
                $asset_path = "/assets/" . $unminified_path;
            else
            {
                self::$logger->error("Requested asset {0} could not be resolved", [$asset['path']]);
                continue;
            }

            $asset['path'] = $asset_path;
            $asset['url'] = $this->vhost->URL($asset_path);
            $urls[] = $asset;
        }

        return $urls;
    }

    public function executeHook(array $params)
    {
        $responder = $params['responder'] ?? null;
        $mime = $params['mime'] ?? null;

        $response = empty($responder) ? null : $responder->getResponse();

        if ($response instanceof StringResponse && $mime === "text/html")
        {
            $output = $response->getOutput($mime);
            $scripts = $this->resolveAssets($this->scripts, "js");
            $css = $this->resolveAssets($this->css, "css");

            $tpl = new Template($this->resolver);
            $tpl->setTemplate('parts/scripts');
            $tpl->assign('scripts', $scripts);
            $tpl->assign('inline_js', $this->inline_variables);
            $script_html = $tpl->renderReturn()->getOutput($mime);
            $output = str_replace('#WEDETO-JAVASCRIPT#', $script_html, $output);

            $tpl = new Template($this->resolver);
            $tpl->setTemplate('parts/stylesheets');
            $tpl->assign('stylesheets', $css);
            $tpl->assign('inline_css', $this->inline_style);
            $css_html = $tpl->renderReturn()->getOutput($mime);
            $output = str_replace('#WEDETO-CSS#', $css_html, $output);

            // Tidy up output when configured and available
            if ($this->tidy)
            {
                if (class_exists("Tidy", false))
                {
                    $tidy = new \Tidy();
                    $config = array('indent' => true, 'wrap' => 120, 'markup' => true, 'doctype' => 'omit');
                    $output = "<!DOCTYPE html>\n" . $tidy->repairString($output, $config, "utf8");
                }
                else
                {
                    // @codeCoverageIgnoreStart
                    self::$logger->warning("Tidy output has been requested, but Tidy extension is not available");
                    // @codeCoverageIgnoreEnd
                }
            }
            $response->setOutput($output, $mime);
        }
    }
}
