Suffix tree behavior
====================
Behavior for use suffix tree.
This behavior attached to your model for usage hierarchical structure.

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist singrana/yii2-suffixtree-behavior "*"
```

or add

```
"singrana/yii2-suffixtree-behavior": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your code by  :

Add in your model behaviors method:

```php
public function behaviors()
{
	return
	[
		...

		'suffixTree'		=>
		[
			'class'			=>	'singrana\behaviors\SuffixBehavior',
		],
		...
	];
}
```

You can configure behavior:

* `fieldKey` - attribute for storage suffix key;
* `fieldTranslit` - attribute for storage translit, null if not need;
* `fieldUrl` - attribute for storage Url, null if not need;
* `fieldParent` - attribute for storage parent field, null if not need different trees storage;
* `fieldParentId` - attribute for parent node Id;
* `fieldLevel` - attribute for storage level node, null if not need;
