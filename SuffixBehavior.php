<?php

namespace singrana\behaviors;

use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\db\Query;

use yii\web\NotFoundHttpException;

class SuffixBehavior extends Behavior
{
	private $fieldId;

	private $parentNode		=	null;
	private $keyMoved		=	null;
	private $oldKey			=	null;
	private $keyId			=	null;

	public $fieldKey		=	'pkey';
	public $fieldTranslit	=	'translit';
	public $fieldUrl		=	'url';
	public $fieldParent		=	null;
	public $fieldParentId	=	'parentId';
	public $fieldLevel		=	null;


	public function events()
	{
		return
		[
			ActiveRecord::EVENT_BEFORE_INSERT	=>	'beforeSave',
			ActiveRecord::EVENT_BEFORE_UPDATE	=>	'beforeSave',
			ActiveRecord::EVENT_AFTER_INSERT	=>	'afterSave',
			ActiveRecord::EVENT_AFTER_UPDATE	=>	'afterSave',
			ActiveRecord::EVENT_BEFORE_DELETE	=>	'beforeDelete',
		];
	}


	public function attach($owner)
	{
		parent::attach($owner);

		$this->fieldId	=	is_array($owner->tableSchema->primaryKey)?$owner->tableSchema->primaryKey[0]:$owner->tableSchema->primaryKey;
	}

	public function beforeSave()
	{
		if (!$this->owner->{$this->fieldKey} || $this->owner->isNewRecord)
			$this->createNode();
		else
			$this->editNode();

		if($this->fieldUrl)
		{
			if ($this->parentNode)
			{
				$this->owner->{$this->fieldUrl} = $this->parentNode->{$this->fieldUrl} . '/' . $this->owner->{$this->fieldTranslit};
			}
			else
			{
				$this->owner->{$this->fieldUrl} = $this->owner->{$this->fieldTranslit};
			}
		}

		if($this->fieldLevel)
		{
			$this->owner->{$this->fieldLevel}=strlen($this->owner->{$this->fieldKey})/3;
		}

	}


	function afterSave()
	{
		if ($this->keyMoved)
		{
			$model=$this->owner;
			$model=$model::find();

			$model->andWhere(
				'LENGTH(' . $this->fieldKey . ') = :lenght AND SUBSTRING(' . $this->fieldKey . ', 1, ' . strlen($this->oldKey) . ') = :key',
				[':lenght' => (strlen($this->oldKey) + 3), ':key' => $this->oldKey]
			);

			if($this->fieldParent)
			{
				$model->andWhere(
					$this->fieldParent.'=:parent',
					[':parent' => $this->owner->{$this->fieldParent}]
				);
			}

			if ($list = $model->all())
			{
				foreach ($list as $item)
				{
					$item->{$this->fieldParentId} = $this->keyId;
					$item->save();
				}
			}
		}
	}

	function beforeDelete()
	{
		$model=$this->owner;
		$model=$model::find();

		$model->andWhere
		(
			$this->fieldKey . ' LIKE :key AND ' . $this->fieldKey . '<>:key2',
			[
				':key'  => $this->owner->{$this->fieldKey} . '%',
				':key2' => $this->owner->{$this->fieldKey},
			]
		);

		if($this->fieldParent)
		{
			$model->andWhere
			(
				$this->fieldParent.'=:parent',
				[':parent' => $this->owner->{$this->fieldParent}]
			);
		}

		if ($node = $model->all())
		{
			foreach ($node as $item)
			{
				$item->delete();
			}
		}

	}

	public function createNode()
	{
		$key			=	'';

		$model=$this->owner;

		if ($this->owner->{$this->fieldParentId})
		{
			$arr=[$this->fieldId => $this->owner->{$this->fieldParentId}];
			if($this->fieldParent)
				$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

			if (!$this->parentNode = $model::find()->andWhere($arr)->one())
				throw new NotFoundHttpException('Integrity tree corrupt');

			$key = $this->parentNode->{$this->fieldKey};
		}

		$len = strlen($key);

		$model=$model::find();
		$model=$model->andWhere(
			'LENGTH(' . $this->fieldKey . ') = :lenght ',
			[':lenght' => $len + 3]
		);

		if($this->fieldParent)
		{
			$model->andWhere(
				$this->fieldParent.'=:parent',
				[':parent' => $this->owner->{$this->fieldParent}]
			);
		}

		if ($this->owner->{$this->fieldParentId})
		{
			$model->andWhere(
				'SUBSTR(' . $this->fieldKey . ', 1, ' . $len . ') = :key2',
				[':key2' => substr($key, 0, $len)]
			);
		}
		$model->orderBy($this->fieldKey . ' DESC');

		$node = $model->one();

		if ($node)
			$this->owner->{$this->fieldKey} = $this->incSuffix($node->{$this->fieldKey});
		else
			$this->owner->{$this->fieldKey} = $key . '000';
	}



	public function editNode()
	{
		// Edit Node
		$this->keyMoved	=	false;
		$oldParent		=	null;
		$oldParentId	=	0;

		$model=$this->owner;


		if ($this->owner->{$this->fieldParentId})
		{
			$arr=[$this->fieldId => $this->owner->{$this->fieldParentId}];

			if($this->fieldParent)
				$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

			if (!$this->parentNode = $model::find()->andWhere($arr)->one())
				throw new NotFoundHttpException('Integrity tree corrupt');

		}

		// Get current record (before save data)
		$current=$model::find()->andWhere([$this->fieldId => $this->owner->{$this->fieldId}])->one();
		if(!$current)
			throw new NotFoundHttpException('The requested node does not exist.');

		if (strlen($current->{$this->fieldKey}) > 3)
		{
			$arr=[$this->fieldKey => substr($current->{$this->fieldKey}, 0, strlen($current->{$this->fieldKey}) - 3)];
			if($this->fieldParent)
				$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

			if ($oldParent = $model::find()->andWhere($arr)->one())
				$oldParentId = $oldParent->{$this->fieldId};
		}

		// Проверяем - была ли смена потомка
		if ($this->owner->{$this->fieldParentId} != $oldParentId)
		{
			// Обновляем ветвь потомков
			$this->keyMoved = true;

			while ($this->nodeDown() == true)
				$this->owner->{$this->fieldKey} = $this->_getKey($this->owner->{$this->fieldId});

			$this->owner->{$this->fieldKey}	=	$this->_getKey($this->owner->{$this->fieldId});
			$this->oldKey					=	$this->owner->{$this->fieldKey};

			if($this->owner->{$this->fieldParentId})
			{
				$arr=[$this->fieldId => $this->owner->{$this->fieldParentId}];
				if($this->fieldParent)
					$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

				$newParent = $model::find()->andWhere($arr)->one();

				if ($this->owner->{$this->fieldParentId} && $newParent)
					$len = strlen($newParent->{$this->fieldKey});
				else
					$len = 0;

				$model=$model::find();
				$model->andWhere(
					'LENGTH(' . $this->fieldKey . ') = :lenght ',
					[':lenght' => $len + 3]
				);

				if($this->fieldParent)
				{

					$model->andWhere(
						$this->fieldParent.'=:parent',
						[':parent' => $this->owner->{$this->fieldParent}]
					);
				}

				if ($this->owner->{$this->fieldParentId} && $newParent)
				{

					$model->andWhere(
						'SUBSTRING(' . $this->fieldKey . ', 1, ' . $len . ') = :key2',
						[':key2' => substr($newParent->{$this->fieldKey}, 0, $len)]
					);
				}

				$model->orderBy($this->fieldKey . ' DESC');

				$node = $model->one();

				if ($node && $newParent)
					$this->owner->{$this->fieldKey}	=	$this->incSuffix($node->{$this->fieldKey});
				else
					$this->owner->{$this->fieldKey}	=	$newParent->{$this->fieldKey} . '000';
			}
			else
			{
				$model=$model::find();
				$model->andWhere(
					'LENGTH(' . $this->fieldKey . ') = :lenght ',
					[':lenght' => 3]
				);

				if($this->fieldParent)
				{
					$model->andWhere(
						$this->fieldParent.'=:parent',
						[':parent' => $this->owner->{$this->fieldParent}]
					);
				}

				$model->orderBy($this->fieldKey . ' DESC');

				if(!($node = $model->one()))
					throw new NotFoundHttpException('Update error. Nodes not found.');

				$this->owner->{$this->fieldKey} = $this->incSuffix($node->{$this->fieldKey});

			}

			$this->keyId = $this->owner->{$this->fieldId};
		}
	}


	public function swap($key1, $key2)
	{
		$table = $this->owner->tableName();

		$model=$this->owner;

		$q=$model::find();
		$q->andWhere(
			'SUBSTRING(' . $this->fieldKey . ', 1, :len) = :key',
			[':key' => $key1, ':len' => strlen($key1)]
		);

		if($this->fieldParent)
		{
			$q->andWhere(
				$this->fieldParent.'=:parent',
				[':parent' => $this->owner->{$this->fieldParent}]
			);
		}

		$itemsList = $q->all();

		foreach ($itemsList as $nn => $item)
		{
			$command = \Yii::$app->db->createCommand();

			$arr=[$this->fieldKey => '_' . $item->{$this->fieldKey}];

			if($this->fieldParent)
				$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

			$command->update($table, $arr, $this->fieldId . '=:id', [':id' => $item->{$this->fieldId}]);
			$command->execute();
		}

		$q=$model::find();

		$q->andWhere(
			'SUBSTRING(' . $this->fieldKey . ', 1, :len) = :key',
			[':key' => $key2, ':len' => strlen($key2)]
		);


		if($this->fieldParent)
		{
			$q->andWhere(
				$this->fieldParent.'=:parent',
				[':parent' => $this->owner->{$this->fieldParent}]
			);
		}

		$itemsList = $q->all();

		foreach ($itemsList as $item)
		{
			$command = \Yii::$app->db->createCommand();

			$arr=[$this->fieldKey => $key1 . substr($item->{$this->fieldKey}, strlen($key1))];

			if($this->fieldParent)
				$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

			$command->update($table, $arr, $this->fieldId . '=:id', [':id' => $item->{$this->fieldId}]);
			$command->execute();
		}

		$q=$model::find();

		$q->andWhere(
			'SUBSTRING(' . $this->fieldKey . ', 1, 1) = :key',
			[':key' => '_']
		);

		if($this->fieldParent)
		{
			$q->andWhere(
				$this->fieldParent.'=:parent',
				[':parent' => $this->owner->{$this->fieldParent}]
			);
		}

		$itemsList = $q->all();

		foreach ($itemsList as $item)
		{
			$command = \Yii::$app->db->createCommand();

			$arr=[$this->fieldKey => $key2 . substr($item->{$this->fieldKey}, strlen($key1) + 1)];

			if($this->fieldParent)
				$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

			$command->update($table, $arr, $this->fieldId . '=:id', [':id' => $item->{$this->fieldId}]);
			$command->execute();
		}
		return true;
	}


	function _getKey($id)
	{
		$model=$this->owner;

		$node =$model::find()->andWhere([$this->fieldId => $id])->one();
		if (!$node)
			return false;

		return $node->{$this->fieldKey};
	}


	public function nodeDown()
	{
		$model=$this->owner;
		$model=$model::find();

		$nextSuffix = $this->incSuffix($this->owner->{$this->fieldKey});

		$arr=[$this->fieldKey => $nextSuffix];
		if($this->fieldParent)
			$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

		if ($toSwap = $model->andWhere($arr)->one())
		{
			return $this->swap($this->owner->{$this->fieldKey}, $toSwap->{$this->fieldKey});
		}

		return false;
	}

	public function nodeUp()
	{
		if (substr($this->owner->{$this->fieldKey}, -3) == '000')
			return false;

		$model=$this->owner;
		$model=$model::find();

		$prevSuffix = $this->decSuffix($this->owner->{$this->fieldKey});

		$arr=[$this->fieldKey => $prevSuffix];
		if($this->fieldParent)
			$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

		if ($toSwap = $model->andWhere($arr)->one())
		{
			return $this->swap($this->owner->{$this->fieldKey}, $toSwap->{$this->fieldKey});
		}

		return false;
	}


	public function incSuffix($key)
	{
		$maxLenght = pow(strlen($key), 36);
		$dec       = base_convert($key, 36, 10);

		if ($dec == $maxLenght)
			return $key;

		$dec++;

		return sprintf("%0" . strlen($key) . "s", base_convert($dec, 10, 36));

	}

	public function decSuffix($key)
	{
		$dec = base_convert($key, 36, 10);
		if ($dec == 0)
			return $key;

		$dec--;

		return sprintf("%0" . strlen($key) . "s", base_convert($dec, 10, 36));
	}

	public function getAttachableNodes()
	{
		$model=$this->owner;
		$model=$model::find();

		if ($this->owner->{$this->fieldKey})
		{
			$model->andWhere(
				$this->fieldKey . ' NOT LIKE :key',
				[':key' => $this->owner->{$this->fieldKey} . "%"]
			);
		}

		if($this->fieldParent)
		{
			$model->andWhere(
				$this->fieldParent.'=:parent',
				[':parent'=>$this->owner->{$this->fieldParent}]
			);
		}

		$model->orderBy($this->fieldKey . ' ASC');

		return $model->all();

	}

	public function getParent()
	{
		if ($this->owner->isNewRecord || !$this->owner->{$this->fieldKey})
			return null;

		$model=$this->owner;
		$model=$model::find();

		$arr=[$this->fieldKey => substr($this->owner->{$this->fieldKey}, 0, strlen($this->owner->{$this->fieldKey}) - 3)];
		if($this->fieldParent)
			$arr[$this->fieldParent]=$this->owner->{$this->fieldParent};

		if (!$parentNode = $model->andWhere($arr)->one())
			return null;

		return $parentNode->{$this->fieldId};
	}

	public function getTreeStruct($key=null, $level=3)
	{
		$length=$level*3;
		if($key)
			$length+=strlen($key);

		$model=$this->owner;
		$model=$model::find();

		$merge=null;
		if($key)
		{
			if($level)
			{
				$merge=
				[
					'condition'	=>	$this->fieldKey.' LIKE :key AND '.$this->fieldKey.'<>:key2 AND LENGTH('.$this->fieldKey.')<=:length',
					'params'	=>	[':key' => $key.'%', ':key2' => $key, ':length' => $length],
				];
			}
			else
			{
				$merge=
				[
					'condition'	=>	$this->fieldKey.' LIKE :key AND '.$this->fieldKey.'<>:key2',
					'params'	=>	[':key' => $key.'%', ':key2' => $key],
				];
			}
		}
		else
		{
			if($level)
			{
				$merge=
				[
					'condition'	=>	'LENGTH('.$this->fieldKey.') <= :length',
					'params'	=>	[':length' => $length],
				];
			}
		}

		if($merge)
			$model->andWhere($merge['condition'], $merge['params']);

		$model->orderBy($this->fieldKey.' ASC');

		return $model;
	}
}