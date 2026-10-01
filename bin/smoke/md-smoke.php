<?php
require __DIR__ . '/../../core/Support/Markdown.php';
use Core\Support\Markdown;

$src = <<<'MD'
# Hello world

This is a **bold** statement with _italic_ flair and an
[external link](https://example.com) plus a [relative link](/about).

## Lists

- one
- two with `inline code`
- three

1. first
2. second
3. third

## Code

```php
echo "hello";
```

## Quote

> Stay hungry,
> stay foolish.

---

A safety check: [click me](javascript:alert(1)) should be neutered.

A hard break
follows.
MD;

echo Markdown::render($src), PHP_EOL;
