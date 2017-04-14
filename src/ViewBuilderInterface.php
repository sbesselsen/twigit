<?php
namespace TwigIt;

interface ViewBuilderInterface
{
    /**
     * @param \PhpParser\Node[] $nodes
     * @return \TwigIt\View
     */
    public function buildViewFromPHPNodes(array $nodes);
}
