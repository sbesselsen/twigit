<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use TwigIt\DefaultViewBuilder;

/** @var \TwigIt\ViewBuilder $processor */
$prettyPrinter = new PhpParser\PrettyPrinter\Standard();

$processor = new DefaultViewBuilder($prettyPrinter);

$parserFactory = new ParserFactory();
$parser = $parserFactory->create(ParserFactory::PREFER_PHP5);

$code = file_get_contents(__DIR__ . '/demo1.php');
$stmts = $parser->parse($code);

$viewCode = $processor->buildViewFromPHPNodes($stmts);

echo $prettyPrinter->prettyPrintFile($viewCode->codeNodes);

echo "\n\n-------------\n\n";

$twigFormatter = new \TwigIt\Twig\TwigFormatter();
echo $twigFormatter->formatTemplate($viewCode->templateRootNode);

