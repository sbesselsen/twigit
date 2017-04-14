<?php
namespace TwigIt;

final class View
{
    /**
     * @var \TwigIt\Template\Block
     */
    public $templateRootNode;

    /**
     * @var \PhpParser\Node[]
     */
    public $codeNodes = [];

    /**
     * @var string
     */
    public $dataVariableName;
}
