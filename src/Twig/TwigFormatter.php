<?php
namespace TwigIt\Twig;

use TwigIt\Template\Block;
use TwigIt\Template\HTML;
use TwigIt\Template\Node;
use TwigIt\Template\TemplateFormatterInterface;
use TwigIt\Template\VariableIteratorBlock;
use TwigIt\Template\VariableOutputBlock;

final class TwigFormatter implements TemplateFormatterInterface
{
    /**
     * @inheritDoc
     */
    public function formatTemplate(Node $node)
    {
        $output = [];
        if ($node instanceof Block) {
            foreach ($node->nodes as $subNode) {
                $output[] = $this->formatTemplate($subNode);
            }
        } else if ($node instanceof HTML) {
            $output[] = $node->html;
        } else if ($node instanceof VariableOutputBlock) {
            $variableNameParts = array_filter([ $node->scopeName, $node->variableName ]);
            $variableName = implode('.', $variableNameParts);

            $suffix = '';
            if ($node->mode === VariableOutputBlock::MODE_RAW) {
                $suffix = ' | raw';
            }
            $output[] = '{ ' . $variableName . $suffix . ' }';
        } else if ($node instanceof VariableIteratorBlock) {
            // TODO
        }
        return implode('', $output);
    }
}
