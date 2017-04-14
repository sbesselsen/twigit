<?php
namespace TwigIt\Template;

interface TemplateFormatterInterface
{
    /**
     * @param \TwigIt\Template\Node $node
     * @return string
     */
    public function formatTemplate(Node $node);
}
