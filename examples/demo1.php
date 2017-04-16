<html>
<head>
<?php
$sum = 0;
foreach ($xs as $x) {
  $sum += $x;
  echo 'x';
}
?>
<title><?php echo htmlspecialchars($title); ?></title>
<?php foreach (array_reverse($scripts) as $i => $script) : ?>
<script src="<?php echo htmlentities($script) ?>" type="text/javascript"></script>
<?php endforeach; ?>
</head>
<body>
<h1><?php echo htmlspecialchars("Hello {$title} world") ?></h1>
<?php if ($has_content) : ?>
<div class="content">
  <?php foreach ($posts as $post) : ?>
  <h2><?php echo htmlspecialchars($post->title) ?></h2>
  <div class="tags"><?php foreach ($post->tags as $tag) : ?><a href="<?php echo $tag->url ?>"><?php print($tag->name . ' (' . $tag->posts . ' posts)') ?></a><?php endforeach ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php for ($i = 0; $i < 10; $i++) { ?>
aap<?php echo $i ?>test
<?php } ?>
</body>
<?php
echo 1, 2, 3, "<b>" . htmlspecialchars("this is y: " . strlen($title)) . "</b>", 4;
?>
</html>