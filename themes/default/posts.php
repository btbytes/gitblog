<h1><?= gb_title() ?></h1>
<? foreach ($postspage->posts as $post): ?>
	<h2><a href="<?= $post->url() ?>"><?= $post->title ?></a></h2>
	<div id="post-meta">
		<h3>Details</h3>
		<ul>
			<li>Author: <a href="mailto:<?= $post->author->email ?>"><?= $post->author->name ?></a></li>
			<li>Published: <?= $post->published ?></li>
			<li>Modified: <?= $post->modified ?></li>
			<li>Tags: <?= $post->tagLinks() ?></li>
			<li>Categories: <?= $post->categoryLinks() ?></li>
			<li>Comments: <?= $post->comments ?></li>
			<li>Version: <?= $post->id ?></li>
		</ul>
	</div>
	<?= $post->body ?>
	<? if ($post->excerpt): ?>
		<p><a href="<?= $post->url() ?>#<?= $post->domID() ?>-more">Read more...</a></p>
	<? endif; ?>
	<div class="breaker"></div>
<? endforeach ?>
<? if ($postspage->nextpage != -1): ?>
	<a href="?page=<?= $postspage->nextpage ?>">« Older posts</a>
<? endif; ?>
<? if ($postspage->prevpage != -1): ?>
	<a href="?page=<?= $postspage->prevpage ?>">Newer posts »</a>
<? endif; ?>