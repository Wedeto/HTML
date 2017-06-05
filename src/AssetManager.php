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
use Wedeto\Util\Dictionary;
use Wedeto\Util\Functions as WF;

use Wedeto\HTTP\Response\Response;
use Wedeto\HTTP\Response\StringResponse;

use Wedeto\Resolve\Resolver;

/**
 * AssetManager collects, resolves and injects scripts and style sheets in a
 * HTML document.
 */
class AssetManager
{
    use LoggerAwareStaticTrait;

    protected $scripts = array();
    protected $css = array();
    protected $minified = true;
    protected $tidy = false;
    protected $resolver;

    protected $inline_variables = array();
    protected $inline_style = array();
    
    protected $resolve_prefix;
    protected $url_prefix;

    /**
     * Create the asset manager.
     *
     * @param Resolver $resolver The Resolver to resolve the referenced assets
     * @param string $resolve_prefix The path to be prepended before resolved
     *                               assets. /assets/ by default
     * @param string $url_prefix The path to be prepended before the resolved
     *                           URL.  /assets/ by default.
     */
    public function __construct(
        Resolver $resolver, 
        string $resolve_prefix = '/assets/',
        string $url_prefix = '/assets/'
    )
    {
        self::getLogger();
        $this->url_prefix = $url_prefix;
        $this->resolve_prefix = $resolve_prefix;
        $this->resolver = $resolver;
    }

    /**
     * @return Resolver the resolver used to resolve assets in the file system
     */
    public function getResolver()
    {
        return $this->resolver;
    }

    /**
     * Set the Resolver to be used to resolve referenced assets
     * @param Resolver $resolver The resolver instance
     * @return AssetManager Provides fluent interface
     */
    public function setResolver(Resolver $resolver)
    {
        $this->resolver = $resolver;
        return $this;
    }
    
    /**
     * Enable / disable minification of the included assets.
     * This will determine if the .min.js / .min.css files are either
     * preferred or avoided where possible.
     * @param bool $minified True to prefer minified files, false to avoid them
     * @return AssetManager Provides fluent interface
     */
    public function setMinified(bool $minified)
    {
        $this->minified = $minified;
        return $this;
    }

    /**
     * @return bool The setting for minified. @see AssetManager#setMinified
     */
    public function getMinified()
    {
        return $this->minified;
    }

    /**
     * Enable or disable automatic tidy-fication on the resulting HTML
     * @param bool $tidy Whether to enable or disable tidy-fication
     * @return AssetManager Provides fluent interface
     */
    public function setTidy(bool $tidy)
    {
        $this->tidy = $tidy;
        return $this;
    }

    /**
     * @return bool The setting for tidy. @see AssetManager#setTidy
     */
    public function getTidy()
    {
        return $this->tidy;
    }

    /**
     * Add a javascript file to be loaded. This will strip the .min and .js suffixes
     * and add them to the stack of included scripts.
     * @param string $script The script to be loaded
     * @return AssetManager Provides fluent interface
     **/
    public function addScript(string $script)
    {
        $script = $this->stripSuffix($script, ".min", ".js");
        $this->scripts[$script] = array("path" => $script);
        return $this;
    }

    /**
     * @return array The list of registered javascripts. The entries are stripped from
     * a .min.js and .js suffix.
     */
    public function getScripts()
    {
        return array_values($this->scripts);
    }

    /**
     * Add a CSS stylesheet to be loaded. This will strip the .min and .css suffixes
     * and add them to the stack of included stylesheets.
     * @param string $style The style sheet to be loaded
     * @return AssetManager Provides fluent interface
     */
    public function addCSS(string $stylesheet, $media = "screen")
    {
        $stylesheet = $this->stripSuffix($stylesheet, ".min", ".css");
        $this->css[$stylesheet] = array("path" => $stylesheet, "media" => $media);
        return $this;
    }

    /**
     * @return array The list of registered CSS files. The entries are stripped
     * from a .min.css and .css suffix.
     */
    public function getCSS()
    {
        return array_values($this->css);
    }
    
    /**
     * Add a inline CSS style definition
     * @param string $style The in-line CSS
     * @return AssetManager Provides fluent interface
     */
    public function addStyle(string $style)
    {
        $this->inline_style[] = $style;
        return $this;
    }

    /**
     * @return array the registered inline CSS style definitions
     */
    public function getStyles()
    {
        return array_values($this->inline_style);
    }

    /**
     * Add a javascript variable to be added to the output document. A script will 
     * be generated to definie these variables on page load.
     * @param string $name The name of the variable. Must be a valid javascript
     *                     variable name.
     * @param mixed The value to set. Should be scalar, array or JSONSerializable
     */
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

    /**
     * @return The list of registered javascript variables
     */
    public function getVariables()
    {
        return $this->inline_variables;
    }

    /**
     * Remove the suffix from a file name, such as .min.css or .min.js
     * @param string $path The file to strip
     * @param string $suffix1 One suffix to strip. This one is stripped after
     *                        the second, so it should come second-last. 
     *                        This probably should be .min
     * @param string $suffix2 Another suffix to strip. This one is stripped
     *                        from the right first, so it should come last in
     *                        the file name. This probably should be .js or .css
     * @return string The stripped file name.
     */
    protected function stripSuffix(string $path, string $suffix1, string $suffix2)
    {
        if (substr($path, -strlen($suffix2)) === $suffix2)
            $path = substr($path, 0, -strlen($suffix2));
        if (substr($path, -strlen($suffix1)) === $suffix1)
            $path = substr($path, 0, -strlen($suffix1));
        return $path;
    }

    /**
     * @return string a token that will be replaced by javascripts later
     */
    public function injectScript()
    {
        return "#WEDETO-JAVASCRIPT#";
    }

    /**
     * @return string a token that will be replaced by CSS stylesheets later
     * Should be in the header somewhere.
     */
    public function injectCSS()
    {
        return "#WEDETO-CSS#";
    }

    /**
     * Resolve a list of assets. The assets will be resolved by the Resolver.
     * Depending on the setting of minified, the minified or the unminified version
     * will be preferred. When the preferred type is not available, another one
     * will be used.
     * @return array A list of resolved URLs. Each entry contains a key 'path' to the
     * file in the file system and a key 'url' for the browser.
     */
    public function resolveAssets(array $list, $type)
    {
        $urls = array();
        foreach ($list as $asset)
        {
            $relpath = $this->resolve_prefix . $type . "/" . $asset['path'];
            $url = $this->url_prefix . $type . "/" . $asset['path'];
            $unminified_path = $relpath . "." . $type;
            $unminified_url = $url . "." . $type;
            $minified_path = $relpath . ".min." . $type;
            $minified_url = $url . ".min." . $type;
            $unminified_file = $this->resolver->resolve($unminified_path);
            $minified_file = $this->resolver->resolve($minified_path);

            $asset_path = null;
            if (!$this->minified && $unminified_file)
            {
                $asset['path'] = $unminified_path;
                $asset['url'] = $unminified_url;
            }
            elseif ($minified_file)
            {
                $asset['path'] = $minified_path;
                $asset['url'] = $minified_url;
            }
            elseif ($unminified_file)
            {
                $asset['path'] = $unminified_path;
                $asset['url'] = $unminified_url;
            }
            else
            {
                self::$logger->error("Requested asset {0} could not be resolved", [$asset['path']]);
                continue;
            }

            $urls[] = $asset;
        }

        return $urls;
    }

    /**
     * Replace the tokens injected in the generation of the HTML by the correct values.
     * @param string $HTML The HTML containing tokens to be replaced
     * @return string The HTML with the tokens replaced, and optionally with corrected HTML
     * using PHP's Tidy extension.
     */
    public function replaceTokens(string $HTML)
    {
        $scripts = $this->resolveAssets($this->scripts, "js");
        $css = $this->resolveAssets($this->css, "css");

        $tpl = new Template($this->resolver);
        $tpl->setTemplate('parts/scripts');
        $tpl->assign('scripts', $scripts);
        $tpl->assign('inline_js', $this->inline_variables);
        $script_HTML = $tpl->renderReturn()->getOutput($mime);
        $HTML = str_replace('#WEDETO-JAVASCRIPT#', $script_HTML, $HTML);

        $tpl = new Template($this->resolver);
        $tpl->setTemplate('parts/stylesheets');
        $tpl->assign('stylesheets', $css);
        $tpl->assign('inline_css', $this->inline_style);
        $css_HTML = $tpl->renderReturn()->getOutput($mime);
        $HTML = str_replace('#WEDETO-CSS#', $css_HTML, $HTML);

        // Tidy up HTML when configured and available
        if ($this->tidy)
        {
            if (class_exists("Tidy", false))
            {
                $tidy = new \Tidy();
                $config = array('indent' => true, 'wrap' => 120, 'markup' => true, 'doctype' => 'omit');
                $HTML = "<!DOCTYPE html>\n" . $tidy->repairString($HTML, $config, "utf8");
            }
            else
            {
                // @codeCoverageIgnoreStart
                self::$logger->warning("Tidy output has been requested, but Tidy extension is not available");
                // @codeCoverageIgnoreEnd
            }
        }
        return $HTML;
    }

    /**
     * Execute the hook to replace the Javascript and CSS tokens in the HTTP Responder. This will
     * be called by Wedeto\Util\Hook through Wedeto\HTTP. It can be called directly
     * when you want to replace the content at a different time.
     *
     * @param Dictionary $params The parameters. Should contain a key 'response' and 'mime'. The res
     */
    public function executeHook(Dictionary $params)
    {
        $responder = $params['responder'] ?? null;
        $mime = $params['mime'] ?? null;

        $response = empty($responder) ? null : $responder->getResponse();

        if ($response instanceof StringResponse && $mime === "text/html")
        {
            $output = $response->getOutput($mime);
            $output = $this->replaceTokens($output);
            $response->setOutput($output, $mime);
        }
    }
}
