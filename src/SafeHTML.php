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

use DOMDocument;
use DOMNode;
use DOMException;

use Wedeto\Util\ErrorInterceptor;

/**
 * SafeHTML prepares a piece of HTML code for inclusion in a web app.  It
 * parses the provided HTML, corrects errors and only allows whitelisted tags.
 *
 * For each whitelisted tag, the attributes are scanned, and only whitelisted
 * attributes are kept intact. Additionally, the href tag is scanned more
 * closely, to check which protocol is being used. Only whitelisted protocols are
 * used.
 *
 * Finally, specific tags can be assigned that are completely removed from the output.
 * From other tags, the tags itself are removed but the child nodes remain. 
 *
 * The default configuration allows basic markup tags to be used:
 * <p>, <span>, <ul>, <ol>, <li>, <b>, <i>, <u>, <em>, <strong>
 *
 * By default, the following attributes are allowed:
 * title, id, class, alt
 *
 * The following protocols are by default allowed for links:
 * http, https, ftp and mailt
 *
 * Keep in mind that links are not allowed by default, so to enable this,
 * you need to allow the <a> tag and the 'href' attribute. As a convencience,
 * you can call SafeHTML#allowLinks()
 *
 * Finally, you also have the option to add callbacks for specific tags. For this
 * callback to be used, the tag itself needs to be whitelisted. It will then be
 * processed, including its attributes and child nodes, and finally, the callback
 * will be invoked with the DOMNode as argument.
 */
class SafeHTML
{
    /** The list of allowed tags */
    private $allowed_tags = array();

    /** The list of tags to remove including the child nodes */
    private $remove_tags = array();

    /** The attributes that are allowed on allowed tags */
    private $allowed_attributes = array();

    /** The protocols that are acceptable in href attributes */
    private $allowed_protocols = array();
    
    /** The tags for which a callback is configured */
    private $tag_callback = array();

    /** The input HTML */
    private $html;

    /**
     * Create the SafeHTML object wrapping input HTML
     *
     * @param $html string The input HTML
     * @param $set_default boolean Whether to add the default safe tags and attributes
     */
    public function __construct($html, $set_default = true)
    {
        $this->html = $html;
        if ($set_default)
            $this->setDefault();
    }

    /**
     * Add a tag to the whitelist. 
     *
     * @param $tag string|array The name of the tag to add to the whitelist.
     *                          Can also be an array to add multiple tags.
     * @return SafeHTML Provides fluent interface
     */
    public function allowTag($tag)
    {
        if (is_array($tag))
        {
            foreach ($tag as $t)
            {
                if (isset($this->remove_tags[$t]))
                    unset($this->remove_tags[$t]);
                $this->allowed_tags[$t] = true;
            }
        }
        else
        {
            if (isset($this->remove_tags[$tag]))
                unset($this->remove_tags[$tag]);
            $this->allowed_tags[$tag] = true;
        }
        return $this;
    }

    /**
     * Add an attribute to the whitelist
     *
     * @param $attribute string|Array The name of the attribute to add to the whitelist.
     *                                Can also be an array to add multiple attributes.
     * @return SafeHTML Provides fluent interface
     */
    public function allowAttribute($attribute)
    {
        if (is_array($attribute))
        {
            foreach ($attribute as $attrib)
                $this->allowed_attributes[$attrib] = true;
        }
        else
            $this->allowed_attributes[$attribute] = true;
        return $this;
    }

    /**
     * Add a callback function for a tag
     *
     * @param $tag string The name of the tag for which to add a callback
     * @param $callback callable The function / method to invoke. The matching
     *                           DOMNodes are passed as the argument.
     * @return SafeHTML Provides fluent interface
     */
    public function addCallback(string $tag, callable $callback)
    {
        $this->tag_callback[$tag] = $callback;
        return $this;
    }

    /** 
     * Add a tag to the list of tags to remove including all child nodes
     * @param $tag string|array The name of the tag to add to the remove list
     *                          Can also be an array to add multiple tags.
     * @return SafeHTML Provides fluent interface
     */
    public function removeTag($tag)
    {
        if (is_array($tag))
        {
            foreach ($tag as $t)
            {
                if (isset($this->allowed_tags[$t]))
                    unset($this->allowed_tags[$t]);
                $this->remove_tags[$t] = true;
            }
        }
        else
        {
            if (isset($this->allowed_tags[$tag]))
                unset($this->allowed_tags[$tag]);
            $this->remove_tags[$tag] = true;
        }
        return $this;
    }

    /**
     * Add a protocol to the list of protocols to allow linking to.
     * @param $protocl string|array The protocol to add to the whitelist.
     *                              Can also be an array to add multiple protocols.
     * @return SafeHTML Provides fluent interface
     */
    public function allowProtocol($protocol)
    {
        if (is_array($protocol))
        {
            foreach ($protocol as $proto)
                $this->allowed_protocols[$proto] = true;
        }
        else
            $this->allowed_protocols[$protocol] = true;
        return $this;
    }

    
    /**
     * Convenience method to add <a> tag to the whitelist and allow the href attribute.
     * @return SafeHTML Provides fluent interface
     */
    public function allowLinks()
    {
        $this->allowTag('a')
             ->allowAttribute('href');
        return $this;
    }

    /**
     * Add the default set of tags, attributes and protocols to the whitelist and remove list.
     * Default tags: b, i, u, strong, em, p, div, span, abbr
     * Default atttributes: id, class, alt, title, name
     * Default protocols: http, https, ftp, mailto
     * Default tags to be removed in full: script, style
     */
    public function setDefault()
    {
        $this->allowTag(array(
            'b', 'i', 'u', 'strong', 'em', 'p', 'div', 'span', 'abbr'
        ));

        $this->removeTag(array('script', 'style'));

        $this->allowAttribute(array(
            'id', 'class', 'alt', 'title', 'name'
        ));

        $this->allowProtocol(array(
            'http', 'https', 'ftp', 'mailto'
        ));

        return $this;
    }

    /**
     * Return the sanitized HTML, according to the set up list of tags and attributes.
     * @return string The sanitized HTML
     */
    public function getHTML()
    {
        $dom = new DOMDocument();

        $interceptor = new ErrorInterceptor(array($dom, 'loadHTML'));
        $interceptor->registerError(E_WARNING, "DOMDocument::loadHTML");

        $interceptor->execute($this->html);
        $body = $dom->getElementsByTagName('body')->item(0);

        $this->sanitizeNode($body);

        $html = "";
        foreach ($body->childNodes as $n)
            $html .= $dom->saveHTML($n);

        return $html;
    }

    /**
     * Helper function to recursively sanitize nodes
     *
     * @param $node DOMNode The node to sanitize
     */
    private function sanitizeNode(DOMNode $node)
    {
        // Traverse the nodes, without foreach because nodes may be removed
        $child = (!empty($node->childNodes) && $node->childNodes->length > 0) ? $node->childNodes->item(0) : null;
        while ($child !== null)
        {
            $tag = $child->nodeName;

            // Allow text nodes
            if ($tag === "#text")
            {
                $child = $child->nextSibling;
                continue;
            }

            if (isset($this->remove_tags[$tag]))
            {
                // Tag is on the list of nodes to fully remove including child nodes
                $remove = $child;
                $child = $child->nextSibling;
                $node->removeChild($remove);
            }
            elseif (!isset($this->allowed_tags[$tag]))
            {
                // Add the child nodes of the disalowed tag to the parent node in its place,
                // and move the pointer to the first inserted child node, or the next node.
                $child = $this->unwrapContents($node, $child);
            }
            else
            {
                // Sanitize the attributes of an allowed tag
                if ($child->attributes !== null)
                    $this->sanitizeAttributes($child);

                // And now process the contents
                $this->sanitizeNode($child);

                // Finally, call a callback if applicable
                if (isset($this->tag_callback[$tag]))
                    $this->tag_callback[$tag]($child);

                $child = $child->nextSibling;
            }
        }
    }

    /**
     * Helper function to unwrap the contents of a node and remove the node.
     * @param $node DOMNode The node from which to remove the child
     * @param $child DOMNode The child to remove from the node
     * @return The first newly added node, or if none were added, the next sibling node
     */
    private function unwrapContents(DOMNode $node, DOMNode $child)
    {
        // Preserve the content by adding the child nodes to the parent
        $next = $child->nextSibling;
        if ($next === null)
            $next = $child;

        if ($child->childNodes !== null)
        {
            $l = $child->childNodes->length;
            for ($i = $l - 1; $i >= 0; --$i)
            {
                // This try/catch seems not necessary as it does not actually
                // check HTML rules, it will gladly accept <option> in a <p>,
                // or <li> in a <div> for example.
                //
                //try
                //{
                $sub = $child->childNodes[$i];
                $next = $node->insertBefore($sub, $next);
                //}
                //catch (DOMException $ex)
                //{}
            }
        }

        // Finally, remove the node
        $node->removeChild($child);

        // Next is now either:
        // 1) the last inserted child == the top of the list of new nodes
        // 2) if the unwrapped node had no children, the next node
        // 3) if the unwrapped node was the last node, it's the unwrapped node itself
        //
        // 1 and 2 do what is expected, in the case of 3, there is no next node,
        // so null should be returned, rather than the removed node.
        return ($next !== null && $next->isSameNode($child)) ? null : $next;
    }

    /**
     * Helper function to sanitize the attributes for the specified node.
     * @param $child DOMNode the child to examine
     */
    private function sanitizeAttributes(DOMNode $child)
    {
        // Compile a list of attributes that should be removed
        $remove_attributes = array();
        foreach ($child->attributes as $attrib)
        {
            $name = $attrib->name;
            if (!isset($this->allowed_attributes[$name]))
            {
                $remove_attributes[] = $attrib;
            }
            elseif ($name === "href")
            {
                $val = $attrib->nodeValue;

                $is_allowed = false;
                $proto_pos = strpos($val, ":");
                if ($proto_pos !== false)
                {
                    $proto = strtolower(substr($val, 0, $proto_pos));
                    if (isset($this->allowed_protocols[$proto]))
                        $is_allowed = true;
                }
                elseif (substr($val, 0, 2) === '//')
                { // External link with same protocol
                    if (isset($this->allowed_protocols['https']))
                        $is_allowed = true;
                }
                else 
                { // Internal link
                    $is_allowed = true;
                }

                if (!$is_allowed)
                    $remove_attributes[] = $attrib;
            }
        }

        // Remove all nodes selected for removal
        foreach ($remove_attributes as $attrib)
            $child->removeAttributeNode($attrib);
    }
}
