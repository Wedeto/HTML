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

use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\Util\Dictionary;

/**
 * Provide an interface for searching / replacing specifically formed code
 */
class BBCode
{
    use LoggerAwareStaticTrait;

    private static $default = null;
    private $rules = array();
    private $tokens = array();

    /**
     * Create a BBCode object based on the specified config.
     * Rules will be loaded from the config. If it is a Wedeto\Config
     * instance, rules will be loaded from the [bbcode] section.
     *
     * If it's an array, a key 'patterns' and a key 'replacements' are assumed,
     * containing the regular expressions to replace and their replacements.
     * @see BBCode#addRule
     */
    public function __construct($config = null)
    {
        if ($config instanceof Dictionary && $config->has('bbcode', Dictionary::TYPE_ARRAY))
            $config = $config->get('bbcode');

        self::getLogger();
        if (is_array($config) || $config instanceof Dictionary)
        {
            if (isset($config['patterns']) && isset($config['replacements']))
            {
                $patterns = $config['patterns'];
                $replacements = $config['replacements']);
            }
            else
            {
                $patterns = array_keys($config);
                $replacements = array_values($config);
            }

            if (count($patterns))
                $this->addRule($patterns, $replacements);
        }
    }

    /**
     * Add a rule to the set of rules in this BBCode instance
     * @param $pattern mixed A pattern, an array of patterns or an associative
     *                       array of pattern => replacement pairs. Patterns should start
     *                       with /. Strings starting with something else are considered
     *                       to be strings that should be replaced as-is, without any patterns.
     * @param $replacement mixed A replacement string, an array of replacements
     *                           belonging to the patterns or null, when $pattern specifies all.
     *                           Replacement can also be a callable method or function.
     * @return BBCode Provides fluent interface
     */
    public function addRule($pattern, $replacement = null)
    {
        if (is_object($pattern) && method_exists($pattern, 'toArray'))
            $pattern = $pattern->toArray();
        if (is_object($replacement) && method_exists($replacement, 'toArray'))
            $replacement = $replacement->toArray();

        if (!(is_array($pattern) || is_string($pattern)))
            throw new \RuntimeException("Arguments must be string or array, or implement toArray method");

        if (is_array($pattern))
        {
            if ($replacement !== null)
            {
                if (!is_array($replacement) || count($replacement) !== count($pattern))
                    throw new \RuntimeException("When providing arrays as arguments, the should have the same number of elements");
                $pattern = array_combine($pattern, $replacement);
            }

            foreach ($pattern as $pat => $repl)
                $this->addRule($pat, $repl);

            return $this;
        }

        // Create a pattern from strings that aren't patterns already
        if (substr($pattern, 0, 1) !== "/")
            $pattern = "/" . preg_quote($pattern, "/") . "/";

        $cb = is_callable($replacement);

        try
        {
            if ($cb)
                $ret = preg_replace_callback($pattern, $replacement, "");
            else
                $ret = preg_replace($pattern, $replacement, "");
        }
        catch (\ErrorException $e)
        {
            self::$logger->error("Invalid pattern / replacement: {0} / {1}", [$pattern, $replacement]);
            throw new \RuntimeException("Invalid pattern or replacement: {$pattern} / {$replacement}", 0, $e);
        }

        $this->rules[$pattern] = $replacement;
        return $this;
    }

    /**
     * Apply all the configured rules to the provided text and return the result
     * @param $txt string The text to work on
     * @return string The text with the patterns replaced by their replacements
     */
    public function apply($txt)
    {
        if (!is_string($txt))
            throw new \RuntimeException("Argument should be a string");

        foreach ($this->rules as $pattern => $replacement)
        {
            if (is_callable($replacement))
                $txt = preg_replace_callback($pattern, $replacement, $txt);
            else
                $txt = preg_replace($pattern, $replacement, $txt);
        }

        return $txt;
    }

    public static function setDefault(BBCode $code)
    {
        self::$default = $code;
    }

    public static function getDefault()
    {
        return self::$default;
    }
}
