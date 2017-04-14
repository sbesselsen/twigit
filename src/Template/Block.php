<?php
namespace TwigIt\Template;

class Block extends Node {
    /**
     * @var \TwigIt\Template\Node[]
     */
    public $nodes;

    /**
     * @param \TwigIt\Template\Node[] $nodes
     */
    public function __construct(array $nodes = [])
    {
        $this->nodes = $nodes;
    }
}
