<?php
namespace TwigIt\Template;

final class ConditionalBlock extends Node
{
    /**
     * @var Block[]
     */
    public $cases = [];

    /**
     * @var Block|null
     */
    public $elseCase;

    /**
     * @param Block[] $cases
     * @param Block|null $elseCase
     */
    public function __construct(
      array $cases = [],
      $elseCase = null
    ) {
        $this->cases = $cases;
        $this->elseCase = $elseCase;
    }
}
