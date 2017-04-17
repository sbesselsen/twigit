<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use TwigIt\DefaultViewBuilder;

$prettyPrinter = new PhpParser\PrettyPrinter\Standard();

/** @var \TwigIt\ViewBuilderInterface $processor */
$processor = new DefaultViewBuilder($prettyPrinter);

$parserFactory = new ParserFactory();
$parser = $parserFactory->create(ParserFactory::PREFER_PHP5);

$file = 'demo1';
$demoPhpFile = __DIR__ . '/' . $file . '.php';
$alteredPhpFile = __DIR__ . '/' . $file . '-twig.php';

$code = file_get_contents($demoPhpFile);
$stmts = $parser->parse($code);

$viewCode = $processor->buildViewFromPHPNodes($stmts);

$alteredCode = $prettyPrinter->prettyPrintFile($viewCode->codeNodes);

$twigFormatter = new \TwigIt\Twig\TwigFormatter();
$twigCode = $twigFormatter->formatTemplate($viewCode->templateRootNode);

file_put_contents($alteredPhpFile, $alteredCode);
file_put_contents(__DIR__ . '/' . $file . '.twig', $twigCode);

echo $alteredCode;

echo "\n\n-------------\n\n";

echo $twigCode;

ob_start();
include($demoPhpFile);
$originalOutput = ob_get_clean();

echo "\n\n-------------\n\n";

echo $originalOutput;

echo "\n\n-------------\n\n";

$view = null;
include($alteredPhpFile);

$loader = new Twig_Loader_Array(array(
  'index' => $twigCode,
));
$twig = new Twig_Environment($loader);
$newOutput = $twig->render('index', $view);

echo $newOutput;

echo "\n\n-------------\n\n";

var_dump($view);

echo "\n\n-------------\n\n";

var_dump($newOutput === $originalOutput);