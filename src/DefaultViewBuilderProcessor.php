<?php
namespace TwigIt;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinterAbstract;
use TwigIt\Template\Block;
use TwigIt\Template\ConditionalBlock;
use TwigIt\Template\HTML;
use TwigIt\Template\VariableIteratorBlock;
use TwigIt\Template\VariableOutputBlock;

final class DefaultViewBuilderProcessor implements NodeVisitor
{
    /**
     * @var array
     */
    private $templateBlockStack = [];

    /**
     * @var \TwigIt\Template\Block
     */
    private $currentTemplateBlock = null;

    /**
     * @var \TwigIt\DefaultViewBuilderProcessor_Scope[]
     */
    private $scopeStack = [];

    /**
     * @var \TwigIt\DefaultViewBuilderProcessor_Scope
     */
    private $currentScope = null;

    /**
     * @var \PhpParser\PrettyPrinterAbstract
     */
    private $prettyPrinter;

    /**
     * @var array
     */
    private $usedVariables = [];

    /**
     * @param \PhpParser\PrettyPrinterAbstract $prettyPrinter
     */
    public function __construct(PrettyPrinterAbstract $prettyPrinter)
    {
        $this->prettyPrinter = $prettyPrinter;
    }

    /**
     * @param array $nodes
     * @return \TwigIt\View
     * @throws \Exception
     *  If an error occurs while processing.
     */
    public function process(array $nodes)
    {
        // First get all used variable names in the file.
        $traverser = $this->createNodeTraverser();
        $variableNameCollector = new DefaultViewBuilderProcessor_VariableCollector;
        $traverser->addVisitor($variableNameCollector);
        $traverser->traverse($nodes);

        $this->usedVariables = $variableNameCollector->variableNames;

        $viewVariableName = $this->generateUniquePHPVariableName('view');

        $view = new View();

        $view->dataVariableName = $viewVariableName;

        $view->templateRootNode = new Block();
        $this->templateBlockStack[] = $view->templateRootNode;
        $this->currentTemplateBlock = $view->templateRootNode;
        $scope = new DefaultViewBuilderProcessor_Scope($viewVariableName, null);
        $this->pushScope($scope);

        try {
            $traverser = $this->createNodeTraverser();
            $traverser->addVisitor($this);
            $view->codeNodes = $traverser->traverse($nodes);
            array_unshift(
              $view->codeNodes,
              new Node\Expr\Assign(
                new Node\Expr\Variable($viewVariableName),
                new Node\Expr\Array_([])
              )
            );
            $view->codeNodes = self::combineScopeAssignments(
              $view->codeNodes,
              $viewVariableName
            );
        } finally {
            // Clean up.
            $this->templateBlockStack = [];
            $this->currentTemplateBlock = null;
            $this->scopeStack = [];
            $this->currentScope = null;
        }

        return $view;
    }

    /**
     * @inheritDoc
     */
    public function beforeTraverse(array $nodes)
    {
        // TODO: Implement beforeTraverse() method.
    }

    /**
     * @inheritDoc
     */
    public function enterNode(Node $node)
    {
        // TODO:
        // switch

        if ($node instanceof Node\Expr\FuncCall) {
            $function = (string)$node->name;
            if (preg_match('(^ob_)', $function)) {
                // TODO: just make this a warning once we have logging in place.
                throw new \Exception(
                  "Cannot process this file: it contains ob_...() functions."
                );
            }
        }

        if ($node instanceof Node\Stmt\If_) {
            return $this->processIfNode($node);
        }

        if ($node instanceof Node\Stmt\ElseIf_) {
            $this->pushScope(clone $node->getAttribute('twigit_base_scope'));
            $conditionalBlock = $this->templateBlockStack[count(
              $this->templateBlockStack
            ) - 2];
            if (!$conditionalBlock instanceof ConditionalBlock) {
                throw new \LogicException('Invalid template stack for ElseIf_');
            }
            $block = $conditionalBlock->cases[$node->getAttribute(
              'twigit_casename'
            )];
            $this->templateBlockStack[] = $block;
            $this->currentTemplateBlock = $block;
        }
        if ($node instanceof Node\Stmt\Else_) {
            $this->pushScope(clone $node->getAttribute('twigit_base_scope'));
            $conditionalBlock = $this->templateBlockStack[count(
              $this->templateBlockStack
            ) - 2];
            if (!$conditionalBlock instanceof ConditionalBlock) {
                throw new \LogicException('Invalid template stack for ElseIf_');
            }
            $block = $conditionalBlock->elseCase;
            if (!$block) {
                throw new \LogicException('Unexpected Else_ case');
            }
            $this->templateBlockStack[] = $block;
            $this->currentTemplateBlock = $block;
        }

        if ($node instanceof Node\Stmt\Switch_) {
            return $this->processSwitchNode($node);
        }
        if ($node instanceof Node\Stmt\Case_) {
            $this->pushScope(clone $node->getAttribute('twigit_base_scope'));
            if (!$this->currentTemplateBlock instanceof ConditionalBlock) {
                throw new \LogicException('Invalid template stack for Case_');
            }
            $caseName = $node->getAttribute(
              'twigit_casename'
            );
            if ($caseName) {
                $block = $this->currentTemplateBlock->cases[$caseName];
            } else {
                $block = $this->currentTemplateBlock->elseCase;
            }
            $this->templateBlockStack[] = $block;
            $this->currentTemplateBlock = $block;
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            $block = $this->generateForeachIteratorBlock($node);

            return $this->processIteratorNode($node, $block);
        }

        if ($node instanceof Node\Stmt\For_) {
            $block = $this->generateForIteratorBlock($node);

            return $this->processIteratorNode($node, $block);
        }

        if ($node instanceof Node\Stmt\While_) {
            $block = $this->generateWhileIteratorBlock($node);

            return $this->processIteratorNode($node, $block);
        }

        if ($node instanceof Node\Stmt\Do_) {
            $block = $this->generateDoIteratorBlock($node);

            return $this->processIteratorNode($node, $block);
        }

        if ($node instanceof Node\Stmt\InlineHTML) {
            $this->currentTemplateBlock->nodes[] = new HTML($node->value);
            $this->currentScope->hasOutput = true;

            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }
        if ($node instanceof Node\Stmt\Echo_) {
            $this->currentScope->hasOutput = true;

            $replacements = $this->processOutputCall($node->exprs);
            if ($replacements) {
                $node->setAttribute('twigit_replacements', $replacements);
            }
        }
        if ($node instanceof Node\Expr\Print_) {
            $this->currentScope->hasOutput = true;

            $replacements = $this->processOutputCall([$node->expr]);
            if ($replacements) {
                $node->setAttribute('twigit_replacements', $replacements);
            }
        }

        return null;
    }

    /**
     * @param \PhpParser\Node\Stmt\If_ $if
     */
    private function processIfNode(Node\Stmt\If_ $if)
    {
        $cases = [$if];
        foreach ($if->elseifs as $elseIf) {
            $cases[] = $elseIf;
        }
        if ($if->else) {
            $cases[] = $if->else;
        }

        $block = new ConditionalBlock();
        $iteratorBlock = $this->currentVariableIteratorBlock();
        if ($iteratorBlock) {
            $block->scopeName = $iteratorBlock->localVariableName;
        }

        foreach ($cases as $case) {
            if (!$case instanceof Node) {
                continue;
            }

            $case->setAttribute('twigit_base_scope', $this->currentScope);

            if (isset($case->cond) && $case->cond instanceof Node\Expr) {
                $caseName = $this->generateOutputVariableName(
                  $this->generateConditionVariableName($case->cond)
                );
                $block->cases[$caseName] = new Block();
                $case->setAttribute('twigit_casename', $caseName);
                $case->setAttribute('twigit_conditional_block', true);
            } else {
                $block->elseCase = new Block();
                $case->setAttribute('twigit_casename', false);
                $case->setAttribute('twigit_conditional_block', true);
            }
        }

        // Now push the conditional block.
        $this->templateBlockStack[] = $block;
        foreach ($block->cases as $caseBlock) {
            // And push the if block over it.
            $this->templateBlockStack[] = $caseBlock;
            $this->currentTemplateBlock = $caseBlock;
            break;
        }

        // Create new fake scopes so we can reuse variable names in all branches of the if.
        $this->pushScope(clone $this->currentScope);
    }

    /**
     * @param \PhpParser\Node\Stmt\Switch_ $switch
     * @throws \Exception
     *      If there is a fallthrough case.
     */
    private function processSwitchNode(Node\Stmt\Switch_ $switch)
    {
        $switch->setAttribute('twigit_conditional_block', true);

        $cases = $switch->cases;

        $block = new ConditionalBlock();
        $iteratorBlock = $this->currentVariableIteratorBlock();
        if ($iteratorBlock) {
            $block->scopeName = $iteratorBlock->localVariableName;
        }

        foreach ($cases as $case) {
            if (!$case instanceof Node) {
                continue;
            }

            $case->setAttribute('twigit_base_scope', $this->currentScope);

            if (isset($case->cond) && $case->cond instanceof Node\Expr) {
                if (!$case->stmts || !($case->stmts[count(
                      $case->stmts
                    ) - 1] instanceof Node\Stmt\Break_)
                ) {
                    throw new \Exception(
                      'Switch/case with fallthrough is not supported'
                    );
                }

                $cond = new Node\Expr\BinaryOp\Equal(
                  $switch->cond, $case->cond
                );
                $caseName = $this->generateOutputVariableName(
                  $this->generateConditionVariableName($cond)
                );
                $block->cases[$caseName] = new Block();
                $case->setAttribute('twigit_casename', $caseName);
                $case->setAttribute('twigit_conditional_block', true);
            } else {
                $block->elseCase = new Block();
                $case->setAttribute('twigit_casename', false);
                $case->setAttribute('twigit_conditional_block', true);
            }
        }

        // Now push the conditional block.
        $this->templateBlockStack[] = $block;
        $this->currentTemplateBlock = $block;

        // Create new fake scopes so we can reuse variable names in all branches of the if.
        $this->pushScope(clone $this->currentScope);
    }

    /**
     * @param \PhpParser\Node $node
     * @param \TwigIt\Template\VariableIteratorBlock $block
     * @return \PhpParser\Node|null
     */
    private function processIteratorNode(
      Node $node,
      VariableIteratorBlock $block
    ) {
        $iteratorBlock = $this->currentVariableIteratorBlock();
        if ($iteratorBlock) {
            $block->scopeName = $iteratorBlock->localVariableName;
        }

        $variable = $this->generateUniquePHPVariableName(
          'view_'.$block->localVariableName
        );

        $this->templateBlockStack[] = $block;
        $this->currentTemplateBlock = $block;
        $node->setAttribute('twigit_iterator_block', true);

        $this->currentScope->values[$block->iteratedVariableName] = [];

        $scope = new DefaultViewBuilderProcessor_Scope(
          $variable,
          $block->localVariableName
        );
        $this->pushScope($scope);

        return null;
    }

    /**
     * @param \PhpParser\Node\Expr[] $exprs
     * @return \PhpParser\Node[]|null
     */
    private function processOutputCall(array $exprs)
    {
        // Unwrap concats and encapsed strings to a sequence of exprs.
        $exprs = self::unwrapStringLikeExpressions($exprs);

        $outputParameterExpressions = [];

        // Now loop through the exprs to see what we should do.
        foreach ($exprs as $expr) {
            if ($expr instanceof Node\Scalar\String_) {
                // Pass constant strings directly into the output. These are considered safe.
                $this->currentTemplateBlock->nodes[] = new HTML($expr->value);
                continue;
            }

            // Check if this expression is safe to HTML escape.
            $varExpr = $expr;
            $mode = VariableOutputBlock::MODE_RAW;
            $escapableExpr = self::htmlEscapableExpression($expr);

            if (($escapableExpr instanceof Node\Scalar\DNumber) ||
              ($escapableExpr instanceof Node\Scalar\LNumber) ||
              ($escapableExpr instanceof Node\Scalar\String_)
            ) {
                // Pass escaped strings directly into the output in escaped form.
                $this->currentTemplateBlock->nodes[] = new HTML(
                  htmlspecialchars($escapableExpr->value)
                );
                continue;
            }

            if (($varExpr instanceof Node\Scalar) &&
              !($varExpr instanceof Node\Scalar\MagicConst) &&
              isset ($escapableExpr->value)
            ) {
                // Pass consts directly into the output in escaped form.
                $this->currentTemplateBlock->nodes[] = new HTML(
                  htmlspecialchars($escapableExpr->value)
                );
                continue;
            }

            if ($escapableExpr) {
                $varExpr = $escapableExpr;
                $mode = VariableOutputBlock::MODE_SAFE;
            }

            $varName = $this->generateOutputVariableName($varExpr);
            $this->currentScope->values[$varName] = [];
            $outputParameterExpressions[$varName] = $varExpr;

            $templateScopeName = null;
            $iteratorBlock = $this->currentVariableIteratorBlock();
            if ($iteratorBlock) {
                $templateScopeName = $iteratorBlock->localVariableName;
            }

            $this->currentTemplateBlock->nodes[] = new VariableOutputBlock(
              $varName, $templateScopeName, $mode
            );
        }

        if (!$outputParameterExpressions) {
            return null;
        }

        $scopeVariable = new Node\Expr\Variable(
          $this->currentScope->variableName
        );

        $output = [];
        foreach ($outputParameterExpressions as $name => $expr) {
            $output[] = new Node\Expr\Assign(
              new Node\Expr\ArrayDimFetch($scopeVariable,
                new Node\Scalar\String_($name)),
              $expr
            );
        }

        return $output;
    }

    /**
     * @inheritDoc
     */
    public function leaveNode(Node $node)
    {
        if ($node->hasAttribute('twigit_replacements')) {
            return $node->getAttribute('twigit_replacements');
        }
        if ($node instanceof Node\Stmt\InlineHTML) {
            return NodeTraverser::REMOVE_NODE;
        }
        if ($node instanceof Node\Stmt\Echo_) {
            return NodeTraverser::REMOVE_NODE;
        }
        if ($node instanceof Node\Expr\Print_) {
            return NodeTraverser::REMOVE_NODE;
        }
        if ($node->hasAttribute('twigit_iterator_block') && $node->getAttribute(
            'twigit_iterator_block'
          )
        ) {
            $scope = $this->popScope();
            $templateBlock = $this->popTemplateBlock();

            if (!$scope->hasOutput) {
                return null;
            }

            $this->currentTemplateBlock->nodes[] = $templateBlock;

            if (!$templateBlock instanceof VariableIteratorBlock) {
                throw new \Exception("Unexpected template block");
            }

            if (isset($node->stmts)) {
                if ($scope->values) {
                    // Declare the local variable at the start of the loop.
                    $declareLocalVariableExpr = new Node\Expr\Assign(
                      new Node\Expr\Variable($scope->variableName),
                      new Node\Expr\Array_([])
                    );

                    // And add it to the scope ref at the end.
                    $arrayPushExpr = new Node\Expr\Assign(
                      new Node\Expr\ArrayDimFetch(
                        new Node\Expr\ArrayDimFetch(
                          new Node\Expr\Variable(
                            $this->currentScope->variableName
                          ),
                          new Node\Scalar\String_(
                            $templateBlock->iteratedVariableName
                          )
                        ), null
                      ),
                      new Node\Expr\Variable($scope->variableName)
                    );

                    array_unshift($node->stmts, $declareLocalVariableExpr);
                    $node->stmts[] = $arrayPushExpr;
                } else {
                    $emptyPushExpr = new Node\Expr\Assign(
                      new Node\Expr\ArrayDimFetch(
                        new Node\Expr\ArrayDimFetch(
                          new Node\Expr\Variable(
                            $this->currentScope->variableName
                          ),
                          new Node\Scalar\String_(
                            $templateBlock->iteratedVariableName
                          )
                        ), null
                      ),
                      new Node\Expr\Array_([])
                    );
                    $node->stmts[] = $emptyPushExpr;
                }

                $node->stmts = self::combineScopeAssignments(
                  $node->stmts,
                  $scope->variableName
                );
            }

            $nodes = [];

            // Assign the view variable above the loop.
            $nodes[] = new Node\Expr\Assign(
              new Node\Expr\ArrayDimFetch(
                new Node\Expr\Variable($this->currentScope->variableName),
                new Node\Scalar\String_($templateBlock->iteratedVariableName)
              ),
              new Node\Expr\Array_([])
            );
            $nodes[] = $node;

            return $nodes;
        }

        if ($node->hasAttribute(
            'twigit_conditional_block'
          ) && $node->getAttribute('twigit_conditional_block')
        ) {
            // Propagate variables to parent scope.
            $scope = $this->popScope();
            $this->currentScope->values = array_merge(
              $this->currentScope->values,
              $scope->values
            );
            $this->currentScope->hasOutput = $this->currentScope->hasOutput || $scope->hasOutput;

            $templateBlock = $this->popTemplateBlock();
            if ($node instanceof Node\Stmt\Switch_) {
                $conditionalTemplateBlock = $templateBlock;
            } elseif ($node instanceof Node\Stmt\If_) {
                $conditionalTemplateBlock = $this->currentTemplateBlock;
            } elseif ($node instanceof Node\Stmt\Case_) {
                $conditionalTemplateBlock = $this->templateBlockStack[count(
                  $this->templateBlockStack
                ) - 1];
            } else {
                $conditionalTemplateBlock = $this->templateBlockStack[count(
                  $this->templateBlockStack
                ) - 2];
            }
            if (!$conditionalTemplateBlock instanceof ConditionalBlock) {
                throw new \LogicException(
                  'Invalid template stack for conditional block: expect ConditionalBlock'
                );
            }

            $hasOutput = $this->currentScope->hasOutput;

            $caseName = $node->getAttribute('twigit_casename');
            if ($hasOutput) {
                if (isset($node->stmts) && $caseName) {
                    $this->currentScope->values[$caseName] = $caseName;
                    // Set the case here.
                    array_unshift(
                      $node->stmts,
                      new Node\Expr\Assign(
                        new Node\Expr\ArrayDimFetch(
                          new Node\Expr\Variable($this->currentScope->variableName),
                          new Node\Scalar\String_($caseName)
                        ),
                        new Node\Expr\ConstFetch(new Node\Name('true'))
                      )
                    );
                }
            } else {
                if ($caseName) {
                    unset ($conditionalTemplateBlock->cases[$caseName]);
                } else {
                    $conditionalTemplateBlock->elseCase = null;
                }
            }

            // For ifs, we need to pop up one more level.
            if ($node instanceof Node\Stmt\If_) {
                // Pop one additional level off the template block stack.
                $conditionalTemplateBlock = $this->popTemplateBlock();
                if (!$conditionalTemplateBlock instanceof ConditionalBlock) {
                    throw new \LogicException(
                      'Invalid template stack for If_: expect ConditionalBlock'
                    );
                }
                $this->currentTemplateBlock->nodes[] = $conditionalTemplateBlock;
                if ($node->else !== null && !$node->else->stmts) {
                    $node->else = null;
                }
            }
            if ($node instanceof Node\Stmt\Switch_) {
                // Pop one additional level off the template block stack.
                if (!$conditionalTemplateBlock instanceof ConditionalBlock) {
                    throw new \LogicException(
                      'Invalid template stack for Switch_: expect ConditionalBlock'
                    );
                }
                $this->currentTemplateBlock->nodes[] = $conditionalTemplateBlock;
                if ($node->else !== null && !$node->else->stmts) {
                    $node->else = null;
                }
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function afterTraverse(array $nodes)
    {
        // TODO: Implement afterTraverse() method.
    }

    /**
     * @return \PhpParser\NodeTraverser
     */
    private function createNodeTraverser()
    {
        return new NodeTraverser();
    }

    /**
     * @param DefaultViewBuilderProcessor_Scope $scope
     */
    private function pushScope(DefaultViewBuilderProcessor_Scope $scope)
    {
        $this->currentScope = $scope;
        $this->scopeStack[] = $this->currentScope;
    }

    /**
     * @return DefaultViewBuilderProcessor_Scope
     */
    private function popScope()
    {
        $scope = $this->currentScope;
        array_pop($this->scopeStack);
        $this->currentScope = $this->scopeStack[count($this->scopeStack) - 1];

        return $scope;
    }

    /**
     * @return \TwigIt\Template\Node
     */
    private function popTemplateBlock()
    {
        $block = $this->currentTemplateBlock;
        array_pop($this->templateBlockStack);
        $this->currentTemplateBlock = $this->templateBlockStack[count(
          $this->templateBlockStack
        ) - 1];

        return $block;
    }

    /**
     * Unwrap a bunch of string-like expression to a list of parts.
     *
     * This will, for instance, produce this:
     *
     * "x " . $a->x() . "y $q " . 'aap'
     * =>
     * "x ", $a->x(), "y ", $q, " ", "aap"
     *
     * htmlspecialchars("aap {$title} schaap")
     * =>
     * htmlspecialchars("aap "), htmlspecialchars($title), htmlspecialchars(" schaap")
     *
     * @param \PhpParser\Node\Expr[] $exprs
     * @return \PhpParser\Node\Expr[]
     */
    private static function unwrapStringLikeExpressions(array $exprs)
    {
        // These is just a grab bag of functions I could think of.
        static $escapeFunctions = [
          'htmlspecialchars',
          'htmlentities',
        ];

        $unwrappedExprs = [];
        while ($expr = array_shift($exprs)) {
            if ($expr instanceof Node\Expr\BinaryOp\Concat) {
                // "a" . "b" => "a", "b"
                array_unshift($exprs, $expr->right);
                array_unshift($exprs, $expr->left);
                continue;
            }

            if (($expr instanceof Node\Expr\FuncCall) &&
              in_array((string)$expr->name, $escapeFunctions) &&
              isset ($expr->args[0])
            ) {
                // htmlspecialchars(expr) => htmlspecialchars(expr[1]), htmlspecialchars(expr[2])
                $subExpr = $expr->args[0]->value;
                $unwrappedSubExprs = self::unwrapStringLikeExpressions(
                  [$subExpr]
                );
                if (count($unwrappedSubExprs) > 1) {
                    foreach (array_reverse(
                               $unwrappedSubExprs
                             ) as $unwrappedSubExpr) {
                        $escapedUnwrappedSubExpr = new Node\Expr\FuncCall(
                          $expr->name, [new Node\Arg($unwrappedSubExpr)]
                        );
                        array_unshift($exprs, $escapedUnwrappedSubExpr);
                    }
                    continue;
                }
            }

            if ($expr instanceof Node\Scalar\Encapsed) {
                // "a $b c {$d->x}" => "a ", $b, " c ", $d->x
                foreach (array_reverse($expr->parts) as $part) {
                    if ($part instanceof Node\Scalar\EncapsedStringPart) {
                        array_unshift(
                          $exprs,
                          new Node\Scalar\String_($part->value)
                        );
                    } else {
                        array_unshift($exprs, $part);
                    }
                }
                continue;
            }

            $unwrappedExprs[] = $expr;
        }

        return $unwrappedExprs;
    }

    /**
     * Get a version of the specified expression that is  HTML-escapable.
     *
     * Examples:
     * - htmlentities($x) => $x is the escapable version, because we can run it
     *     through htmlspecialchars() without issues
     * - htmlspecialchars($x) => $x is the escapable version
     * - count($array) => count($array) is the escapable version, because we can
     *     run it through htmlspecialchars()
     * - $x ? htmlspecialchars($a) : 0 => $x ? $a : 0 is the escapable version
     *
     * Examples where anescapable version cannot be found:
     * - $x => no idea if it can be escaped or not
     * - $x ? $y : htmlspecialchars($z) => no idea if $y can be escaped, so we
     *     also have no idea about the full expression
     *
     * @param \PhpParser\Node\Expr $expr
     * @return \PhpParser\Node\Expr|null
     */
    private static function htmlEscapableExpression(Node\Expr $expr)
    {
        // These is just a grab bag of functions I could think of.
        static $escapeFunctions = [
          'htmlspecialchars',
          'htmlentities',
        ];

        static $safeFunctions = [
          'abs',
          'intval',
          'sizeof',
          'count',
          'strlen',
        ];

        if ($expr instanceof Node\Scalar\String_) {
            // We can check directly if strings are escapable.
            if ($expr->value === htmlspecialchars($expr->value)) {
                return $expr;
            }

            return null;
        }

        if ($expr instanceof Node\Scalar) {
            // Scalars are all escapable.
            // TODO Not sure about MagicConst scalars, but they mostly contain paths and names so they seem escapable.
            return $expr;
        }

        if ($expr instanceof Node\Expr\FuncCall) {
            $function = (string)$expr->name;
            if (in_array(
                $function,
                $escapeFunctions
              ) && isset ($expr->args[0])
            ) {
                return $expr->args[0]->value;
            }
            if (in_array($function, $safeFunctions)) {
                return $expr;
            }

            return null;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::htmlEscapableExpression($expr->left);
            if ($left) {
                $right = self::htmlEscapableExpression($expr->right);
                if ($right) {
                    return new Node\Expr\BinaryOp\Concat($left, $right);
                }
            }

            return null;
        }

        if ($expr instanceof Node\Expr\Ternary) {
            $if = self::htmlEscapableExpression($expr->if);
            if ($if) {
                $else = self::htmlEscapableExpression($expr->else);
                if ($else) {
                    return new Node\Expr\Ternary($expr->cond, $if, $else);
                }
            }

            return null;
        }

        if ($expr instanceof Node\Expr\Cast) {
            if ($expr instanceof Node\Expr\Cast\String_) {
                $inner = self::htmlEscapableExpression($expr->expr);
                if ($inner) {
                    return new Node\Expr\Cast\String_($inner);
                }
            } else {
                return $expr;
            }
        }

        return null;
    }

    /**
     * @param string $name
     * @param bool $register
     *      Immediately register the variable name for future use?
     * @return string
     */
    private function generateUniquePHPVariableName($name, $register = true)
    {
        $prefix = '';
        $i = 0;
        while (isset($this->usedVariables[$prefix.$name])) {
            $prefix = str_repeat('_', ++$i);
        }

        $name = $prefix.$name;

        if ($register) {
            $this->usedVariables[$name] = $name;
        }

        return $name;
    }

    /**
     * Generate a descriptive variable name for this expression.
     *
     * @param string|\PhpParser\Node\Expr $expr
     * @param bool $unique
     *      Should we make sure the name is unique within the current scope?
     * @return string
     * @throws \Exception
     */
    private function generateOutputVariableName($expr, $unique = true)
    {
        if ($expr instanceof Node\Expr) {
            $name = $this->generateVariableName($expr);
        } elseif (is_string($expr)) {
            $name = $expr;
        } else {
            throw new \Exception("Can't generate output variable for node");
        }
        // Get the current iterator block.
        $iteratorBlock = $this->currentVariableIteratorBlock();
        if ($iteratorBlock && $iteratorBlock->localVariableName
        ) {
            $loopVariable = $iteratorBlock->localVariableName;
            if (substr(
                $name,
                0,
                strlen($loopVariable) + 1
              ) === $loopVariable.'_'
            ) {
                $name = substr($name, strlen($loopVariable) + 1);
            }
        }

        $name = preg_replace(
          '(array_|item_|row_|entry_|abs_|intval_)',
          '',
          $name
        );

        if ($unique) {
            $name = self::addUniqueSuffix($name, $this->currentScope->values);
        }

        return $name;
    }

    /**
     * Generate a descriptive variable name for this expression.
     *
     * @param \PhpParser\Node\Expr $expr
     * @return string
     */
    private function generateVariableName(Node\Expr $expr)
    {
        $php = $this->prettyPrinter->prettyPrintExpr($expr);

        $name = $php;

        // isset(...) ? x : ... => x
        if (preg_match('(^isset\s*\(.*\)\s*\?(.*?)\s*:)s', $name, $match)) {
            $name = $match[1];
        }

        // Ignore casts in naming.
        $name = preg_replace('(\((int|double|float|string)\))', '', $name);

        // Ignore regex expressions.
        $name = preg_replace(
          '((?:p|e)reg_(replace|split|match|match_all)\s*\()s',
          '\\1(\'\',',
          $name
        );

        // Ignore everything after the first function argument.
        $name = preg_replace('(^([^\(]*\([^,\)]+)(.*)$)s', '\\1', $name);
        $name = preg_replace('(->)', '_', $name);
        $name = preg_replace('(\*)', '_times_', $name);
        $name = preg_replace('(\/)', '_div_', $name);
        $name = preg_replace('(-)', '_minus_', $name);
        $name = preg_replace('(\+)', '_plus_', $name);
        $name = preg_replace('([^a-z0-9_]+)i', '_', $name);
        $name = preg_replace('((POST|GET|REQUEST|SERVER|SESSION|ENV)_)', '', $name);
        $name = preg_replace('(_+)', '_', $name);
        $name = trim($name, '_');

        $name = preg_replace('(^isset_(.*)$)', 'have_\\1', $name);

        if (preg_match('(^[0-9])', $name)) {
            $name = '_'.$name;
        }

        return $name;
    }

    /**
     * Generate a descriptive variable name for a condition.
     *
     * @param \PhpParser\Node\Expr $expr
     * @return string
     */
    private function generateConditionVariableName(Node\Expr $expr)
    {
        $maxLength = 50;

        $name = $this->generateDirtyConditionVariableName($expr);
        $name = preg_replace('(_+)', '_', $name);

        $length = -1;
        $nameParts = explode('_', $name);
        $output = [];
        foreach ($nameParts as $namePart) {
            $length += 1 + strlen($namePart);
            if ($length > $maxLength) {
                if ($output) {
                    $output[] = 'etc';
                } else {
                    $output[] = 'condition';
                }
                break;
            } else {
                $output[] = $namePart;
            }
        }

        return implode('_', $output);
    }

    /**
     * Generate a descriptive variable name for a condition, without cleaning it up.
     *
     * @param \PhpParser\Node\Expr $expr
     * @return string
     */
    private function generateDirtyConditionVariableName(Node\Expr $expr)
    {
        if ($expr instanceof Node\Expr\BinaryOp\BooleanAnd || $expr instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_and_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BinaryOp\BooleanOr || $expr instanceof Node\Expr\BinaryOp\LogicalOr) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_or_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BooleanNot) {
            return 'not_'.$this->generateDirtyConditionVariableName(
              $expr->expr
            );
        }
        if ($expr instanceof Node\Expr\Assign) {
            return $this->generateDirtyConditionVariableName(
              $expr->var
            ).'_from_'.$this->generateDirtyConditionVariableName($expr->expr);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Smaller) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_lt_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BinaryOp\SmallerOrEqual) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_lte_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Greater) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_gt_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BinaryOp\GreaterOrEqual) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_gte_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Equal) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_eq_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BinaryOp\NotEqual) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_neq_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Identical) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_eqq_'.$this->generateDirtyConditionVariableName($expr->right);
        }
        if ($expr instanceof Node\Expr\BinaryOp\NotIdentical) {
            return $this->generateDirtyConditionVariableName(
              $expr->left
            ).'_neqq_'.$this->generateDirtyConditionVariableName($expr->right);
        }

        return $this->generateVariableName($expr);
    }

    /**
     * Add a suffix to make the name unique among the specified current values.
     *
     * @param string $name
     * @param array $currentValues
     * @return string
     */
    private static function addUniqueSuffix($name, array $currentValues)
    {
        $suffix = '';
        $i = 1;
        while (isset($currentValues[$name.$suffix])) {
            $i++;
            $suffix = '_'.$i;
        }

        return $name.$suffix;
    }

    /**
     * @param \PhpParser\Node\Stmt\Foreach_ $node
     * @return \TwigIt\Template\VariableIteratorBlock
     */
    private function generateForeachIteratorBlock(Node\Stmt\Foreach_ $node)
    {
        $iteratedVariableName = $this->generateOutputVariableName($node->expr);
        $localVariableName = null;

        if ($node->valueVar instanceof Node\Expr\Variable) {
            $localVariableName = $this->generateVariableName($node->valueVar);
        } else {
            $localVariableName = 'item';
        }

        return new VariableIteratorBlock(
          $iteratedVariableName,
          $localVariableName
        );
    }

    /**
     * @param \PhpParser\Node\Stmt\While_ $node
     * @return \TwigIt\Template\VariableIteratorBlock
     */
    private function generateWhileIteratorBlock(Node\Stmt\While_ $node)
    {
        return $this->generateWhileOrDoIteratorBlock($node->cond);
    }

    /**
     * @param \PhpParser\Node\Stmt\Do_ $node
     * @return \TwigIt\Template\VariableIteratorBlock
     */
    private function generateDoIteratorBlock(Node\Stmt\Do_ $node)
    {
        return $this->generateWhileOrDoIteratorBlock($node->cond);
    }

    /**
     * @param \PhpParser\Node\Expr $cond
     * @return \TwigIt\Template\VariableIteratorBlock
     */
    private function generateWhileOrDoIteratorBlock(Node\Expr $cond)
    {
        $localVariableName = 'step';
        $iteratedVariableName = 'steps';
        if ($cond instanceof Node\Expr\Assign && $cond->var instanceof Node\Expr\Variable) {
            $localVariableName = (string)$cond->var->name;
            $iteratedVariableName = $this->generateOutputVariableName(
              $localVariableName.'s'
            );
        } else {
            $iteratedVariableName = $this->generateOutputVariableName(
              $this->generateConditionVariableName($cond).'_steps'
            );
        }

        return new VariableIteratorBlock(
          $iteratedVariableName,
          $localVariableName
        );
    }

    /**
     * @param \PhpParser\Node\Stmt\For_ $node
     * @return \TwigIt\Template\VariableIteratorBlock
     */
    private function generateForIteratorBlock(Node\Stmt\For_ $node)
    {
        $localVariableName = 'item';
        if (isset($node->init[0]) &&
          $node->init[0] instanceof Node\Expr\Assign &&
          $node->init[0]->var instanceof Node\Expr\Variable
        ) {
            $localVariableName = (string)$node->init[0]->var->name;
        }
        $iteratedVariableName = $this->generateOutputVariableName(
          $localVariableName.'s'
        );

        return new VariableIteratorBlock(
          $iteratedVariableName,
          $localVariableName
        );
    }

    /**
     * @return \TwigIt\Template\VariableIteratorBlock|null
     */
    private function currentVariableIteratorBlock()
    {
        for ($i = count($this->templateBlockStack) - 1; $i >= 0; $i--) {
            $templateBlock = $this->templateBlockStack[$i];
            if ($templateBlock instanceof VariableIteratorBlock) {
                return $templateBlock;
            }
        }

        return null;
    }

    /**
     * Combine scope assignments, reducing lines of code.
     *
     * Example: it turns this:
     *  $view_post = array();
     *  $view_post += array('post_title' => $post->title);
     *  $view_post += array('post_tags' => array());
     *  $view['posts'][] = $view_post;
     *
     * Into this:
     *  $view_post = array('post_title' => $post->title, 'post_tags' => array());
     *  $view['posts'][] = $view_post;
     *
     * And then into this:
     *  $view['posts'][] = array('post_title' => $post->title, 'post_tags' => array());
     *
     * @param \PhpParser\Node[] $nodes
     * @param $variableName
     * @return \PhpParser\Node[]
     */
    private static function combineScopeAssignments(array $nodes, $variableName)
    {
        // If a block contains only an array create, an assignment and an array push, combine them.
        if (count($nodes) === 3 &&
          $nodes[0] instanceof Node\Expr\Assign &&
          $nodes[0]->var instanceof Node\Expr\Variable &&
          $nodes[0]->var->name === $variableName &&
          $nodes[1] instanceof Node\Expr\Assign &&
          $nodes[1]->var instanceof Node\Expr\ArrayDimFetch &&
          $nodes[1]->var->var instanceof Node\Expr\Variable &&
          $nodes[1]->var->var->name === $variableName &&
          $nodes[1]->var->dim instanceof Node\Scalar\String_ &&
          $nodes[2] instanceof Node\Expr\Assign &&
          $nodes[2]->var instanceof Node\Expr\ArrayDimFetch &&
          $nodes[2]->var->dim === null &&
          $nodes[2]->expr instanceof Node\Expr\Variable &&
          $nodes[2]->expr->name === $variableName
        ) {
            return [
              new Node\Expr\Assign(
                $nodes[2]->var,
                new Node\Expr\Array_(
                  [
                    new Node\Expr\ArrayItem(
                      $nodes[1]->expr,
                      $nodes[1]->var->dim
                    ),
                  ]
                )
              ),
            ];
        }

        // Descend into nested if/else structures.
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\If_) {
                $node->stmts = self::combineScopeAssignments(
                  $node->stmts,
                  $variableName
                );
                foreach ($node->elseifs as $elseIf) {
                    $elseIf->stmts = self::combineScopeAssignments(
                      $elseIf->stmts,
                      $variableName
                    );
                }
                if ($node->else) {
                    $node->else->stmts = self::combineScopeAssignments(
                      $node->else->stmts,
                      $variableName
                    );
                }

            }
        }

        return $nodes;
    }
}

class DefaultViewBuilderProcessor_Scope
{
    /**
     * @var string
     */
    public $keyName;

    /**
     * @var string
     */
    public $variableName;

    /**
     * @var array
     */
    public $values = [];

    /**
     * @var bool
     */
    public $hasOutput = false;

    /**
     * @param string $variableName
     * @param string $keyName
     */
    public function __construct($variableName, $keyName)
    {
        $this->variableName = $variableName;
        $this->keyName = $keyName;
    }
}


class DefaultViewBuilderProcessor_VariableCollector extends NodeVisitorAbstract
{
    /**
     * @var array
     */
    public $variableNames = [];

    /**
     * @inheritDoc
     */
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Expr\Variable) {
            $this->variableNames[$node->name] = $node->name;
        }
    }
}
