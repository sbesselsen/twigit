<html>
<head>
<title><?php echo htmlspecialchars($title); ?></title>
<?php foreach ($scripts as $script) : ?>
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
</body>
<?php
echo 1, 2, 3, "<b>" . htmlspecialchars("this is y: " . strlen($title)) . "</b>", 4;
?>
</html>