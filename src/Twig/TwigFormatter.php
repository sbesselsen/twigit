<?php
namespace TwigIt\Twig;

use TwigIt\Template\Block;
use TwigIt\Template\ConditionalBlock;
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
        if ($node instanceof VariableIteratorBlock) {
            $iteratedVariableName = $node->iteratedVariableName;
            if ($node->scopeName !== null) {
                $iteratedVariableName = $node->scopeName . '.' . $iteratedVariableName;
            }
            $output[] = '{% for ' . $node->localVariableName . ' in ' . $iteratedVariableName . ' %}' . PHP_EOL;
            foreach ($node->nodes as $subNode) {
                $output[] = $this->formatTemplate($subNode);
            }
            $output[] = '{% endfor %}' . PHP_EOL;
        } elseif($node instanceof ConditionalBlock) {
            $firstCase = true;
            $hasCases = false;
            $scopePrefix = $node->scopeName !== null ? $node->scopeName . '.' : '';
            foreach ($node->cases as $caseName => $caseBlock) {
                if ($firstCase) {
                    $output[] = '{% if ' . $scopePrefix . $caseName . ' %}' . PHP_EOL;
                    $firstCase = false;
                } else {
                    $output[] = '{% elseif ' . $scopePrefix . $caseName . ' %}' . PHP_EOL;
                }
                $output[] = $this->formatTemplate($caseBlock);
                $hasCases = true;
            }
            if ($node->elseCase) {
                $output[] = '{% else %}' . PHP_EOL;
                $output[] = $this->formatTemplate($node->elseCase);
            }
            if ($hasCases) {
                $output[] = '{% endif %}' . PHP_EOL;
            }
        } elseif ($node instanceof Block) {
            foreach ($node->nodes as $subNode) {
                $output[] = $this->formatTemplate($subNode);
            }
        } elseif ($node instanceof HTML) {
            $output[] = $node->html;
        } elseif ($node instanceof VariableOutputBlock) {
            $variableNameParts = array_filter([ $node->scopeName, $node->variableName ]);
            $variableName = implode('.', $variableNameParts);

            $suffix = '';
            if ($node->mode === VariableOutputBlock::MODE_RAW) {
                $suffix = ' | raw';
            }
            $output[] = '{{ ' . $variableName . $suffix . ' }}';
        }
        return implode('', $output);
    }
}
