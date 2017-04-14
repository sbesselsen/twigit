<?php
namespace TwigIt\Template;

final class HTML extends Node
{
    /**
     * @var string
     */
    public $html;

    /**
     * @param string|null $html
     */
    public function __construct($html = null)
    {
        $this->html = $html !== null ? (string)$html : '';
    }
}
