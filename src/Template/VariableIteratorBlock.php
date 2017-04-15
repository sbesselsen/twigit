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
     * @var string|null
     */
    public $scopeName = null;

    /**
     * @param string $iteratedVariableName
     * @param string $localVariableName
     * @param string|null $scopeName
     * @param \TwigIt\Template\Node[] $nodes
     */
    public function __construct(
      $iteratedVariableName,
      $localVariableName,
      $scopeName = null,
      array $nodes = []
    ) {
        parent::__construct($nodes);

        $this->iteratedVariableName = $iteratedVariableName;
        $this->localVariableName = $localVariableName;
        $this->scopeName = $scopeName;
    }
}
