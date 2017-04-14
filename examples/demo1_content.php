<?php
$title = '<aap>';
$scripts = array('src/1.js', 'src/2.js');
$has_content = true;
$posts = array(
  (object)array(
    'title' => 'Aap "test"',
    'tags' => array(),
  ),
  (object)array(
    'title' => 'Aap "test2"',
    'tags' => array((object)array('url' => 'http://www.nu.nl/', 'name' => 'nu', 'posts' => 4), (object)array('url' => 'http://www.nu2.nl/', 'name' => 'nu2', 'posts' => 2)),
  ),
  (object)array(
    'title' => 'Aap "test3"',
    'tags' => array(),
  ),
);

include 'demo1.php';