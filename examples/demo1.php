<?php
$xs = [1, 2, 3];
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
$rows = [['title' => 'Aap<test>'], ['title' => 'Schaap<test>']];
$users = [['name' => 'Aap<test>'], ['name' => 'Schaap<test>']];
?>
<html>
<head>
<title><?php echo htmlspecialchars($title); ?></title>
<?php foreach (array_reverse($scripts) as $i => $script) : ?>
<script src="<?php echo htmlentities($script) ?>" type="text/javascript"></script>
<?php endforeach; ?>
</head>
<body>
<h1><?php echo htmlspecialchars("Hello {$title} world") ?></h1>
<?php
$sum = 0;
foreach ($xs as $x) {
  $sum += $x;
  echo 'x';
}
?>
<?php if ($has_content) : ?>
<div class="content">
  <?php foreach ($posts as $post) : ?>
  <h2><?php echo htmlspecialchars($post->title) ?></h2>
  <div class="tags"><?php foreach ($post->tags as $tag) : ?><a href="<?php echo $tag->url ?>"><?php print($tag->name . ' (' . $tag->posts . ' posts)') ?></a><?php endforeach ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<ul>
<?php while ($row = array_shift($rows)) { ?>
<li><?php echo htmlspecialchars($row['title']) ?></li>
<? } ?>
</ul>
<ul>
<?php do { ?>
<li><?php echo htmlspecialchars($user['name']) ?></li>
<? } while ($user = array_shift($users)) ?>
</ul>
<?php for ($i = 0; $i < 10; $i++) { ?>
aap<?php echo (int)$i ?>test
<?php } ?>
<?php
$j = 0;
while ($j * $j < 30) {
  $j++;
  echo (int)$j . ',';
}
?>
</body>
<?php
echo 1, 2, 3, "<b>" . htmlspecialchars("this is y: " . strlen($title)) . "</b>", 4;
?>
</html>