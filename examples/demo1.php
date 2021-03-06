<?php
include(__DIR__ . '/demo1-data.php');
?>
<?php
switch ($has_content) {
    case true:
        $y = 2;
        echo "x";
        break;
    case 'aap':
        $y = 1;
        break;
    default:
        $y = 3;
        echo "aap $y";
}
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
echo fetchItem();
$sum = 0;
foreach ($xs as $x) {
  $sum += $x;
  if ($sum > 3) {
      echo 'x';
  }
}
?>
<?php if ($has_content) : ?>
<div class="content">
  <?php foreach ($posts as $post) : ?>
  <h2><?php echo htmlspecialchars($post->title) ?></h2>
  <div class="tags">
    <?php foreach ($post->tags as $tag) : ?>
      <a href="<?php echo $tag->url ?>">
        <?php print($tag->name) ?>
        <?php if ($tag->posts > 0) : ?>
        <?php print($tag->name . ' (' . $tag->posts . ' posts)') ?>
        <?php else : ?>
          <?php print($tag->name) ?>
        <?php endif; ?>
      </a>
    <?php endforeach ?>
  </div>
  <?php endforeach; ?>
</div>
<?php elseif($has_other_content) : ?>
test<?php echo strlen($title) + 10 ?>
<?php else : ?>
test2
<?php endif ?>
<ul>
<?php while ($row = array_shift($rows)) { ?>
<li><?php echo htmlspecialchars($row['title']) ?></li>
<?php } ?>
</ul>
<ul>
<?php do { ?>
<li><?php echo htmlspecialchars($user['name']) ?></li>
<?php } while ($user = array_shift($users)) ?>
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
