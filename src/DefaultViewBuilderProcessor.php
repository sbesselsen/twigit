<?php
namespace TwigIt;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinterAbstract;
use TwigIt\Template\Block;
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
        // for
        // foreach
        // while
        // do

        // if
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

        if ($node instanceof Node\Stmt\Foreach_) {
            $block = $this->generateForeachIteratorBlock($node);

            return $this->processIteratorNode($node, $block);
        }

        if ($node instanceof Node\Stmt\For_) {
            $block = $this->generateForIteratorBlock($node);

            return $this->processIteratorNode($node, $block);
        }

        if ($node instanceof Node\Stmt\InlineHTML) {
            $this->currentTemplateBlock->nodes[] = new HTML($node->value);
            $this->currentScope->hasOutput = true;

            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }
        if ($node instanceof Node\Stmt\Echo_) {
            $this->currentScope->hasOutput = true;

            return $this->processOutputCall($node->exprs);
        }
        if ($node instanceof Node\Expr\Print_) {
            $this->currentScope->hasOutput = true;

            return $this->processOutputCall([$node->expr]);
        }

        return null;
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
        if ($this->currentTemplateBlock instanceof VariableIteratorBlock) {
            $block->scopeName = $this->currentTemplateBlock->localVariableName;
        }

        $variable = $this->generateUniquePHPVariableName(
          'view_'.$block->localVariableName
        );

        $this->templateBlockStack[] = $block;
        $this->currentTemplateBlock = $block;
        $node->setAttribute('twigit_block', $block);

        $scope = new DefaultViewBuilderProcessor_Scope(
          $variable,
          $block->localVariableName
        );
        $this->pushScope($scope);

        return null;
    }

    /**
     * @param \PhpParser\Node\Expr[] $exprs
     * @return \PhpParser\Node|null
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
            if ($this->currentTemplateBlock instanceof VariableIteratorBlock) {
                $templateScopeName = $this->currentTemplateBlock->localVariableName;
            }

            $this->currentTemplateBlock->nodes[] = new VariableOutputBlock(
              $varName, $templateScopeName, $mode
            );
        }

        if (!$outputParameterExpressions) {
            return null;
        }

        $expressionData = self::arrayNodeForExpressions(
          $outputParameterExpressions
        );
        $scopeVariable = new Node\Expr\Variable(
          $this->currentScope->variableName
        );

        return new Node\Expr\AssignOp\Plus($scopeVariable, $expressionData);
    }

    /**
     * @inheritDoc
     */
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\InlineHTML) {
            return NodeTraverser::REMOVE_NODE;
        }
        if ($node instanceof Node\Stmt\Echo_) {
            return NodeTraverser::REMOVE_NODE;
        }
        if ($node instanceof Node\Expr\Print_) {
            return NodeTraverser::REMOVE_NODE;
        }
        if ($node->hasAttribute('twigit_block')) {
            $scope = $this->popScope();
            $templateBlock = $this->popTemplateBlock();

            if (!$scope->hasOutput) {
                return null;
            }

            $this->currentTemplateBlock->nodes[] = $templateBlock;

            if (!$templateBlock instanceof VariableIteratorBlock) {
                throw new \Exception("Unexpected template block");
            }

            // Declare the local variable at the start of the loop.
            $declareLocalVariableExpr = new Node\Expr\Assign(
              new Node\Expr\Variable($scope->variableName),
              new Node\Expr\Array_([])
            );

            // And add it to the scope ref at the end.
            $arrayPushExpr = new Node\Expr\Assign(
              new Node\Expr\ArrayDimFetch(
                new Node\Expr\ArrayDimFetch(
                  new Node\Expr\Variable($this->currentScope->variableName),
                  new Node\Scalar\String_($templateBlock->iteratedVariableName)
                ), null
              ),
              new Node\Expr\Variable($scope->variableName)
            );

            if ((
                ($node instanceof Node\Stmt\Foreach_) ||
                ($node instanceof Node\Stmt\For_)
              ) && isset($node->stmts)
            ) {
                array_unshift($node->stmts, $declareLocalVariableExpr);
                $node->stmts[] = $arrayPushExpr;

                $node->stmts = self::combineScopeAssignments(
                  $node->stmts,
                  $scope->variableName
                );
            }

            $nodes = [];

            // Assign the view variable above the loop.
            $nodes[] = new Node\Expr\AssignOp\Plus(
              new Node\Expr\Variable($this->currentScope->variableName),
              new Node\Expr\Array_(
                [
                  new Node\Expr\ArrayItem(
                    new Node\Expr\Array_([]),
                    new Node\Scalar\String_(
                      $templateBlock->iteratedVariableName
                    )
                  ),
                ]
              )
            );
            $nodes[] = $node;

            return $nodes;
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
     * @param \PhpParser\Node\Expr[] $exprs
     * @return \PhpParser\Node\Expr\Array_
     */
    private static function arrayNodeForExpressions(array $exprs)
    {
        $items = [];
        foreach ($exprs as $name => $expr) {
            $items[] = new Node\Expr\ArrayItem(
              $expr,
              new Node\Scalar\String_($name)
            );
        }

        return new Node\Expr\Array_($items);
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
        if ($this->currentTemplateBlock instanceof VariableIteratorBlock &&
          $this->currentTemplateBlock->localVariableName
        ) {
            $loopVariable = $this->currentTemplateBlock->localVariableName;
            if (substr(
                $name,
                0,
                strlen($loopVariable) + 1
              ) === $loopVariable.'_'
            ) {
                $name = substr($name, strlen($loopVariable) + 1);
            }
        }

        $name = preg_replace('(array_|item_|row_|entry_)', '', $name);

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

        // Ignore everything after the first function argument.
        $name = preg_replace('(^(.*\([^,\)]+)(.*)$)', '\\1', $name);
        $name = preg_replace('([^a-z0-9_]+)i', '_', $name);
        $name = trim($name, '_');
        if (preg_match('(^[0-9])', $name)) {
            $name = '_'.$name;
        }

        return $name;
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
        $i = 0;
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
        // Combine assignments on multiple consecutive lines.
        /** @var \PhpParser\Node\Expr\Array_ $prevAssignArray */
        $prevAssignArray = null;
        $newNodes = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\AssignOp\Plus &&
              $node->var instanceof Node\Expr\Variable &&
              $node->var->name === $variableName &&
              $node->expr instanceof Node\Expr\Array_
            ) {
                if ($prevAssignArray === null) {
                    $prevAssignArray = $node->expr;
                    $newNodes[] = $node;
                    continue;
                }
                // Combine assign ops.
                $prevAssignArray->items = array_merge(
                  $prevAssignArray->items,
                  $node->expr->items
                );
                continue;
            }
            $prevAssignArray = null;
            $newNodes[] = $node;
        }

        $nodes = $newNodes;

        // Combine new empty array assignment with the first value assignments.
        $newNodes = [];
        $assignNodeIndex = null;
        $hasValueAssignment = false;
        foreach ($nodes as $i => $node) {
            if ($node instanceof Node\Expr\Assign &&
              $node->var instanceof Node\Expr\Variable &&
              $node->var->name === $variableName &&
              $node->expr instanceof Node\Expr\Array_ &&
              count($node->expr->items) === 0
            ) {
                $assignNodeIndex = $i;
            }
            if ($node instanceof Node\Expr\AssignOp\Plus &&
              $node->var instanceof Node\Expr\Variable &&
              $node->var->name === $variableName &&
              $node->expr instanceof Node\Expr\Array_ &&
              !$hasValueAssignment
            ) {
                // This is the first value assignment.
                $hasValueAssignment = true;
                $node = new Node\Expr\Assign($node->var, $node->expr);
            }
            $newNodes[] = $node;
        }

        if ($hasValueAssignment && $assignNodeIndex !== null) {
            array_splice($newNodes, $assignNodeIndex, 1);
        }

        $nodes = $newNodes;

        $removeAssignNodeIndex = null;

        // If a block contains only an assignment and an array push, combine them.
        foreach ($nodes as $i => $node) {
            if ($node instanceof Node\Expr\Assign &&
              $node->var instanceof Node\Expr\ArrayDimFetch &&
              $node->var->dim === null &&
              $node->expr instanceof Node\Expr\Variable &&
              $node->expr->name === $variableName &&
              $i > 0
            ) {
                $prevNode = $nodes[$i - 1];
                if ($prevNode instanceof Node\Expr\Assign &&
                  $prevNode->var instanceof Node\Expr\Variable &&
                  $prevNode->var->name === $variableName &&
                  $prevNode->expr instanceof Node\Expr\Array_
                ) {
                    $node->expr = $prevNode->expr;
                    $removeAssignNodeIndex = $i - 1;
                }
                break;
            }
        }
        if ($removeAssignNodeIndex !== null) {
            array_splice($nodes, $removeAssignNodeIndex, 1);
        }

        // If the variable only contains an empty array, remove the variable and push the empty array directly.
        $newNodes = [];
        $removeAssignNodeIndex = null;
        $assignNodeIndex = null;
        $hasValueAssignment = false;
        foreach ($nodes as $i => $node) {
            if ($node instanceof Node\Expr\Assign &&
              $node->var instanceof Node\Expr\Variable &&
              $node->var->name === $variableName &&
              $node->expr instanceof Node\Expr\Array_ &&
              count($node->expr->items) === 0
            ) {
                $assignNodeIndex = $i;
            }
            if ($node instanceof Node\Expr\AssignOp\Plus &&
              $node->var instanceof Node\Expr\Variable &&
              $node->var->name === $variableName &&
              $node->expr instanceof Node\Expr\Array_
            ) {
                $hasValueAssignment = true;
            }
            if ($node instanceof Node\Expr\Assign &&
              $node->var instanceof Node\Expr\ArrayDimFetch &&
              $node->var->dim === null &&
              $node->expr instanceof Node\Expr\Variable &&
              $node->expr->name === $variableName
            ) {
                if (!$hasValueAssignment && $assignNodeIndex !== null) {
                    $node->expr = new Node\Expr\Array_([]);
                    $removeAssignNodeIndex = $assignNodeIndex;
                }
            }
            $newNodes[] = $node;
        }
        if ($removeAssignNodeIndex !== null) {
            array_splice($newNodes, $removeAssignNodeIndex, 1);
        }
        $nodes = $newNodes;

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
