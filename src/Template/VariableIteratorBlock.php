<?php
namespace TwigIt\Template;

final class VariableIteratorBlock extends Block
{
    /**
     * @var string
     */
    public $iteratedVariableName;

    /**
     * @var string
     */
    public $localVariableName;

    /**
     * @param string $iteratedVariableName
     * @param string $localVariableName
     * @param \TwigIt\Template\Node[] $nodes
     */
    public function __construct(
      $iteratedVariableName,
      $localVariableName,
      array $nodes = []
    ) {
        parent::__construct($nodes);

        $this->iteratedVariableName = $iteratedVariableName;
        $this->localVariableName = $localVariableName;
    }
}
