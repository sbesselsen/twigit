<?php
namespace TwigIt\Twig;

use TwigIt\Template\Node;

interface TwigFormatterInterface
{
    /**
     * @param \TwigIt\Template\Node $node
     * @return string
     */
    public function formatTemplate(Node $node);
}
