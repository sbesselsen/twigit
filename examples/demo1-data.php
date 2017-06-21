<?php
if (!function_exists('fetchItem')) {
    function fetchItem()
    {
        return 10;
    }
}
$xs = [1, 2, 3];
$title = '<aap>';
$scripts = array('src/1.js', 'src/2.js');
$has_content = true;
$has_other_content = false;
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
$rows = [['title' => 'Aap<test>'], ['title' => 'Schaap<test>']];
$users = [['name' => 'Aap<test>'], ['name' => 'Schaap<test>']];
