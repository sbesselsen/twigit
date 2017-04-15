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
     * @var array
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
        $viewVariableName = $this->generateRootViewVariableName($nodes);

        $view = new View();

        $view->dataVariableName = $viewVariableName;

        $view->templateRootNode = new Block();
        $this->templateBlockStack[] = $view->templateRootNode;
        $this->currentTemplateBlock = $view->templateRootNode;
        $scopeCreateNodes = $this->pushScope($viewVariableName);

        try {
            $traverser = $this->createNodeTraverser();
            $traverser->addVisitor($this);
            $view->codeNodes = $traverser->traverse($nodes);
            $view->codeNodes = array_merge($scopeCreateNodes, $view->codeNodes);
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

        // function: and handle ob_ etc.
        if ($node instanceof Node\Stmt\Foreach_) {
            // $block = new VariableIteratorBlock();
        }

        if ($node instanceof Node\Stmt\InlineHTML) {
            $this->currentTemplateBlock->nodes[] = new HTML($node->value);

            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }
        if ($node instanceof Node\Stmt\Echo_) {
            return $this->processOutputCall($node->exprs);
        }
        if ($node instanceof Node\Expr\Print_) {
            return $this->processOutputCall([$node->expr]);
        }

        return null;
    }

    /**
     * @param \PhpParser\Node\Expr[] $exprs
     * @return \PhpParser\Node
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
            } else {
                // Check if this expression is safe to HTML escape.
                $varExpr = $expr;
                $mode = VariableOutputBlock::MODE_RAW;
                $escapableExpr = self::htmlEscapableExpression($expr);
                if ($escapableExpr) {
                    $varExpr = $escapableExpr;
                    $mode = VariableOutputBlock::MODE_SAFE;
                }

                // TODO: check if it's a simple expression and if so, don't go through the outputParameterExpressions.
                $varName = $this->generateVariableName($varExpr);
                $this->currentScope->values[$varName] = [];
                $outputParameterExpressions[$varName] = $varExpr;

                $this->currentTemplateBlock->nodes[] = new VariableOutputBlock(
                  $varName, $this->currentScope->variableName, $mode
                );
            }
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
     * @param string $name
     * @return \PhpParser\Node[]
     */
    private function pushScope($name)
    {
        $this->currentScope = new DefaultViewBuilderProcessor_Scope($name);
        $this->scopeStack[] = $this->currentScope;

        return [
          new Node\Expr\Assign(
            new Node\Expr\Variable($name),
            new Node\Expr\Array_([])
          ),
        ];
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
     * @param \PhpParser\Node\Expr[] $exprs
     * @return \PhpParser\Node\Expr[]
     */
    private static function unwrapStringLikeExpressions(array $exprs)
    {
        $unwrappedExprs = [];
        while ($expr = array_shift($exprs)) {
            if ($expr instanceof Node\Expr\BinaryOp\Concat) {
                array_unshift($exprs, $expr->right);
                array_unshift($exprs, $expr->left);
            } else {
                if ($expr instanceof Node\Scalar\Encapsed) {
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
                } else {
                    $unwrappedExprs[] = $expr;
                }
            }
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

        if ($expr instanceof Node\Scalar\MagicConst) {
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
     * @param \PhpParser\Node[] $nodes
     * @return string
     */
    private function generateRootViewVariableName(array $nodes)
    {
        // First get all used variable names in the file.
        $traverser = $this->createNodeTraverser();
        $variableNameCollector = new DefaultViewBuilderProcessor_VariableCollector(
        );
        $traverser->addVisitor($variableNameCollector);
        $traverser->traverse($nodes);

        $name = 'view';
        $prefix = '';
        $i = 0;
        while (isset($variableNameCollector->variableNames[$prefix.$name])) {
            $prefix = str_repeat('_', ++$i);
        }

        return $prefix.$name;
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

        $suffix = '';
        $i = 0;
        while (isset($this->currentScope->values[$name.$suffix])) {
            $i++;
            $suffix = '_'.$i;
        }

        return $name.$suffix;
    }
}

class DefaultViewBuilderProcessor_Scope
{
    /**
     * @var string
     */
    public $variableName;

    /**
     * @var array
     */
    public $values = [];

    /**
     * @param string $name
     */
    public function __construct($name)
    {
        $this->variableName = $name;
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
