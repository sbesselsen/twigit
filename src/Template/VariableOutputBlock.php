<?php
namespace TwigIt\Template;

final class VariableOutputBlock extends Node
{
    const MODE_RAW = 0;
    const MODE_SAFE = 1;

    /**
     * @var string
     */
    public $variableName;

    /**
     * @var null|string
     */
    public $scopeName;

    /**
     * One of VariableOutputBlock::MODE_*
     * @var int
     */
    public $mode;

    /**
     * VariableOutputBlock constructor.
     * @param string $variableName
     * @param string|null $scopeName
     * @param int|null $mode
     *  One of VariableOutputBlock::MODE_*. Defaults to MODE_SAFE.
     */
    public function __construct($variableName, $scopeName = null, $mode = null)
    {
        $this->variableName = $variableName;
        $this->scopeName = $scopeName;

        $this->mode = self::MODE_SAFE;
        if ($mode === self::MODE_RAW) {
            $this->mode = $mode;
        }
    }
}
