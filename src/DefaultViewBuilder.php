<?php
namespace TwigIt;

use PhpParser\PrettyPrinterAbstract;

final class DefaultViewBuilder implements ViewBuilderInterface
{
    /**
     * @var DefaultViewBuilderProcessor
     */
    private $processor;

    /**
     * @var \PhpParser\PrettyPrinterAbstract
     */
    private $prettyPrinter;

    /**
     * @param \PhpParser\PrettyPrinterAbstract $prettyPrinter
     */
    public function __construct(PrettyPrinterAbstract $prettyPrinter)
    {
        $this->prettyPrinter = $prettyPrinter;
    }

    /**
     * @inheritDoc
     */
    public function buildViewFromPHPNodes(array $nodes)
    {
        return $this->getProcessor()->process($nodes);
    }

    /**
     * @return \TwigIt\DefaultViewBuilderProcessor
     */
    private function getProcessor()
    {
        if ($this->processor === null) {
            $this->processor = $this->buildProcessor();
        }

        return $this->processor;
    }

    /**
     * @return \TwigIt\DefaultViewBuilderProcessor
     */
    private function buildProcessor()
    {
        return new DefaultViewBuilderProcessor($this->prettyPrinter);
    }
}
