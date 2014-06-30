<?php

/**
 * Behavior that allows easy setting/getting record attributes such as relations, attributes defined with setters/getters and table attributes.
 *
 * @uses CActiveRecordBehavior
 * @package
 */
class EActiveRecordExtendedAttributesBehavior extends CActiveRecordBehavior {
	const RELATION_MODE_SET = 'set';
	const RELATION_MODE_ADD = 'add';
	const HAS_MANY = 'EActiveRecordExtendedAttributesHasManyRelationHelper';
	const HAS_ONE = 'EActiveRecordExtendedAttributesHasOneRelationHelper';
	const MANY_MANY = 'EActiveRecordExtendedAttributesManyToManyRelationHelper';
	const BELONGS_TO = 'EActiveRecordExtendedAttributesBelongsToRelationHelper';

	static protected $relationMap = array(
		CActiveRecord::HAS_MANY => self::HAS_MANY,
		CActiveRecord::HAS_ONE => self::HAS_ONE,
		CActiveRecord::MANY_MANY => self::MANY_MANY,
		CActiveRecord::BELONGS_TO => self::BELONGS_TO,
	);

	protected function setRelatedRecords($relation, $value, $mode) {
		$r = $this->owner->r($relation);
		switch (get_class($r)) {
			case self::BELONGS_TO:
				$r->setRelatedAttributes($value);
				break;
			case self::HAS_ONE:
				$r->set($value);
				break;
			case self::MANY_MANY:
			case self::HAS_MANY:
				if ($mode === self::RELATION_MODE_ADD && is_array($value)) {
					foreach ($value as $v) {
						$r->add($v);
					}
				} else {
					$f = $mode;
					$r->$f($value);
				}
				break;
		}
	}

	/**
	 * Set class properties, table attributes, attributes with setters and related records. Related records could be applied in 2 ways%
	 *	- EActiveRecordExtendedAttributesBehavior::RELATION_MODE_ADD adding related records
	 *	- EActiveRecordExtendedAttributesBehavior::RELATION_MODE_SET adding related records and removing records not presented in supplied set
	 * BELONGS_TO relation only setting owner's attributes and does not save record.
	 *
	 *
	 * WARNING: Existing records will be deleted from database if it is not presented in new set:
	 *	- existing record for BELONGS_TO, HAS_ONE relation;
	 *	- existing records for HAS_MANY.
	 *
	 * @param array $attributes [attr => value, ] - attributes to set
	 * @param string $relationMode how to handle records for relations, self::RELATION_MODE_ADD - records/records will be added, self::RELATION_MODE_SET - records will be set.
	 * @access public
	 * @return void
	 */
	public function setExtendedAttributes(array $attributes, $relationMode = self::RELATION_MODE_SET) {
		foreach ($attributes as $attr => $value) {
			if ($this->owner->getMetaData()->hasRelation($attr)) {
				$this->setRelatedRecords($attr, $value, $relationMode);
			} else {
				$this->owner->$attr = $value;
			}
		}
	}

	/**
	 * Get class properties, table attributes, attributes with setters and related records. As simple as $owner->$attribute.
	 *
	 * @param array $attributes
	 * @access public
	 * @return void
	 */
	public function getExtendedAttributes(array $attributes) {
		$res = array();
		foreach ($attributes as $attr) {
			$res[$attr] = $this->owner->$attr;
		}
		return $res;
	}

	/**
	 * Return relation helpers for manipulating related records of owner.
	 * Supported relations BELONGS_TO, HAS_ONE, HAS_MANY, MANY_MANY.
	 * Supported method on helpers:
	 *
	 * @param string $relationName
	 * @access public
	 * @return EActiveRecordExtendedAttributesRelationHelper
	 */
	public function r($relationName) {
 		if (($relation = $this->owner->getActiveRelation($relationName)) === null) {
			throw new CDbException(Yii::t('yii','Relation "{name}" is not defined in active record class "{class}".', array(
				'{class}' => get_class($this->owner),
				'{name}'=> $relationName,
			)));
		}

		if (!isset(self::$relationMap[get_class($relation)])) {
			throw new CDbException(Yii::t('yii', 'Unsupported relation type "{type}" for "{relation}".', array(
				'type' => get_class($relation),
				'relation' => $relationName,
			)));
		}
		$helper = self::$relationMap[get_class($relation)];
		return new $helper($this->owner, $relation);
	}
}

class EActiveRecordExtendedAttributesRelationHelper extends CComponent {
	public $relation;
	public $owner;

	public function __construct(CActiveRecord $owner, CActiveRelation $relation) {
		$this->relation = $relation;
		$this->owner = $owner;
	}
}

trait EActiveRecordExtendedAttributesBehaviorHasRelationTrait {
	protected function getForeignKeyFieldsMap() {
		$relatedModel = CActiveRecord::model($this->relation->className);
		$owner = $this->owner;
		$ownerTable = $this->owner->getTableSchema();
		$relatedTable = $relatedModel->getTableSchema();

		$map = array();

		$fks = is_array($this->relation->foreignKey) ? $this->relation->foreignKey : preg_split('/\s*,\s*/', $this->relation->foreignKey, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($fks as $i => $fk) {
			if (!is_int($i)) {
				$pk = $fk;
				$fk = $i;
			} elseif (isset($relatedTable->foreignKeys[$fk]))  { // FK defined
				$pk = $relatedTable->foreignKeys[$fk][1];
			} elseif(is_array($ownerTable->primaryKey)) { // composite PK
				$pk = $ownerTable->primaryKey[$i];
			} else {
				$pk = $ownerTable->primaryKey;
			}

			$map[$fk] = $pk;
		}
		return $map;
	}
}

class EActiveRecordExtendedAttributesHasOneRelationHelper extends EActiveRecordExtendedAttributesRelationHelper {
	use EActiveRecordExtendedAttributesBehaviorHasRelationTrait;

	/**
	 * Returns related model attribute values that linked it to owner
	 *
	 * @access public
	 * @return array
	 */
	public function getRelatedAttributeValues() {
		$attrs = array();
		foreach ($this->getForeignKeyFieldsMap() as $relatedField => $ownerField) {
			$attrs[$relatedField] = $this->owner->$ownerField;
		}
		return $attrs;
	}

	public function setRelatedAttributes(CActiveRecord $record) {
		foreach ($this->getRelatedAttributeValues() as $attr => $value) {
			$record->$attr = $value;
		}
	}

	/**
	 * Set HAS_ONE related record. If old record is exists and is not equal to new one it will be deleted from database. If $record is new it will be saved.
	 *
	 * @param CActiveRecord $record
	 * @access public
	 * @return void
	 */
	public function set(CActiveRecord $record = null) {
		$this->setRelatedAttributes($record);

		if (($oldRecord = $this->owner->{$this->relation->name}()) && ($oldRecord->id != $record->id)) {
			if (!$r = $oldRecord->delete()) {
				throw new CDbException(Yii::t('yii', 'Could not delete old related record {class}({primary}) for relation "{relation}"', array(
					 '{class}' => get_class($oldRecord),
					 '{relation}' => $this->relation->name,
					 '{primary}' => is_string($oldRecord->primaryKey) ? $oldRecord->primaryKey : implode(',', $oldRecord->primaryKey),
				)));
			}
		}

		$this->owner->{$this->relation->name} = $record;
		return $record->getIsNewRecord() ? $record->save() : $record->update(array_keys($this->getForeignKeyFieldsMap()));
	}

	/**
	 * Get related record.
	 *
	 * @access public
	 * @return void
	 */
	public function get() {
		return $this->owner->{$this->relation->name};
	}
}

class EActiveRecordExtendedAttributesHasManyRelationHelper extends EActiveRecordExtendedAttributesRelationHelper {
	use EActiveRecordExtendedAttributesBehaviorHasRelationTrait;
	protected function getRelationCriteria(CActiveRecord $related) {
		$attrs = array();
		foreach ($this->getForeignKeyFieldsMap() as $relatedField => $ownerField) {
			$attrs[$relatedField] = $this->owner->$ownerField;
		}
		$crit = new CDbCriteria();
		$crit->addColumnCondition($attrs);
		return $crit;
	}

	public function getRelatedAttributeValues() {
		$attrs = [];
		foreach ($this->getForeignKeyFieldsMap() as $relatedField => $ownerField) {
			$attrs[$relatedField] = $this->owner->$ownerField;
		}
		return $attrs;
	}

	/**
	 * Set attributes for $record to make it related to owner.
	 *
	 * @param CActiveRecord $record
	 * @access public
	 * @return void
	 */
	public function setRelatedAttributes(CActiveRecord $record) {
		foreach ($this->getRelatedAttributeValues() as $attr => $value) {
			$record->$attr = $value;
		}
	}

	/**
	 * Set $records as related to owner and remove all other related record from database.
	 *
	 * @param array $records each record could be CActiveRecord object or primary key.
	 * @access public
	 * @return bool
	 */
	public function set(array $records) {
		$relationModel = CActiveRecord::model($this->relation->className);
		$relationName = $this->relation->name;

		$pks = array();
		$records = array_map(function($r) use ($relationModel, &$pks) {
			if (is_object($r)) {
				if (!$r->getIsNewRecord()) {
					$pks[] = $r->primaryKey;
				}
			} else {
				$pks[] = $r;
				$r = $relationModel->findByPk($r);
			}
			return $r;
		}, $records);

		$map = $this->getForeignKeyFieldsMap();

		$deleteCriteria = new CDbCriteria();
		$deleteCriteria->addColumnCondition(array_combine(array_keys($map), $this->owner->getAttributes($map)));
		$pk = CActiveRecord::model($this->relation->className)->getTableSchema()->primaryKey;
		if (is_string($pk)) {
			$deleteCriteria->addNotInCondition($pk, $pks);
		} else {
			$params = array();
			$tpl = array();
			$placeHolders = array();
			$count = 0;
			foreach ($pk as $field) {
				$placeHolders[] = $h = ':pk'.$count.'_{i}';
				$tpl[] = $field . ' = '. $h;
				$count++;
			}
			$tpl = ' NOT (' . implode(' AND ', $tpl) . ')';
			$count = 0;
			foreach ($pks as $pk) {
				$deleteCriteria->addCondition(str_replace('{i}', $count, $tpl), array_combine($placeHolders, $pk));
				$count++;
			}
		}

		$fields = array_keys($map);

		$relationModel->deleteAll($deleteCriteria);

		$newRelatedFieldValues = array_combine($fields, $this->owner->getAttributes($map));

		$success = true;
		$this->owner->$relationName = array();
		foreach ($records as $record) {
			if ($record->getAttributes($fields) !== $newRelatedFieldValues || $record->getIsNewRecord()) {
				$record->setAttributes($newRelatedFieldValues, false);
				$success &= $record->getIsNewRecord() ? $record->save() : $record->update($fields);
			}
			$this->owner->addRelatedRecord($relationName, $record, true);
		}
		return $success;
	}

	/**
	 * Add $record to owner as related. If $related is new it will be saved.
	 *
	 * @param CActiveRecord $record
	 * @param mixed $index index to apply for addRelatedRecord
	 * @access public
	 * @return bool
	 */
	public function add(CActiveRecord $record, $index = null) {
		$this->setRelatedAttributes($record);
		$this->owner->addRelatedRecord($this->relation->name, $record, $index ? $index : true);
		return $record->getIsNewRecord() ? $record->save() : $record->update(array_keys($this->getForeignKeyFieldsMap()));
	}

	/**
	 * Get related to owner record by primary key. If record is not exists or is correspondign record is not related returns null
	 *
	 * @param mixed $pk
	 * @access public
	 * @return null|CActiveRecord
	 */
	public function getByPk($pk) {
		$r = CActiveRecord::model($this->relation->className);
		return $r->findByPk($pk, $this->getRelationCriteria($r));
	}

	/**
	 * Get all related records
	 *
	 * @access public
	 * @return array
	 */
	public function getAll() {
		$r = CActiveRecord::model($this->relation->className);
		return $r->findAll($this->getRelationCriteria($r));
	}
}

class EActiveRecordExtendedAttributesBelongsToRelationHelper extends EActiveRecordExtendedAttributesRelationHelper {
	/**
	 * Map owner fields to related record
	 *
	 * @access protected
	 * @return array
	 */
	protected function getForeignKeyFieldsMap() {
		$relatedModel = CActiveRecord::model($this->relation->className);
		$owner = $this->owner;
		$ownerTable = $this->owner->getTableSchema();
		$schema = $this->owner->getDbConnection()->getSchema();
		$relatedTable = $relatedModel->getTableSchema();

		$map = array();

		$fks = is_array($this->relation->foreignKey) ? $this->relation->foreignKey : preg_split('/\s*,\s*/', $this->relation->foreignKey, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($fks as $i => $fk) {
			if (!is_int($i)) {
				$pk = $fk;
				$fk = $i;
			}

			if (is_int($i)) {
				if (isset($ownerTable->foreignKeys[$fk])) { // FK defined
					$pk = $ownerTable->foreignKeys[$fk][1];
				} elseif (is_array($relatedTable->primaryKey)) {// composite PK
					$pk = $relatedTable->primaryKey[$i];
				} else {
					$pk = $relatedTable->primaryKey;
				}
			}
			$map[$fk] = $pk;
		}
		return $map;
	}

	/**
	 * Set attributes for $record to make it related to owner.
	 *
	 * @param CActiveRecord $record
	 * @access public
	 * @return void
	 */
	public function setRelatedAttributes(CActiveRecord $record = null) {
		$map = $this->getForeignKeyFieldsMap();
		foreach ($map as $ownerField => $relatedField) {
			$this->owner->$ownerField = $record ? $record->$relatedField : null;
		}
	}

	/**
	 * Make owner belongs to $record. If owner is new it will be saved.
	 *
	 * @param null|CActiveRecord $records
	 * @access public
	 * @return bool
	 */
	public function set(CActiveRecord $record = null) {
		if ($record && $record->getIsNewRecord()) {
			throw new CDbException('Could not set belongs to relation to new record');
		}
		$this->setRelatedAttributes($record);

		$r = $this->owner->getIsNewRecord() ? $this->owner->save() : $this->owner->update(array_keys($this->getForeignKeyFieldsMap()));

		$this->owner->{$this->relation->name} = $record;
		return $r;
	}

	/**
	 * Get related record.
	 *
	 * @access public
	 * @return void
	 */
	public function get() {
		return $this->owner->{$this->relation->name};
	}
}

class EActiveRecordExtendedAttributesManyToManyRelationHelper extends EActiveRecordExtendedAttributesRelationHelper {
	protected function getForeignKeyFieldsMap() {
		$map = array(
			'owner' => array(),
			'related' => array(),
		);

		$owner = $this->owner;
		$ownerTable = $this->owner->getTableSchema();
		$schema = $this->owner->getDbConnection()->getSchema();
		$relatedModel = CActiveRecord::model($this->relation->className);
		$relatedTable = $relatedModel->getTableSchema();
		$joinTableName=$this->relation->getJunctionTableName();

		if (($joinTable=$schema->getTable($joinTableName))===null) {
			throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is not specified correctly: the join table "{joinTable}" given in the foreign key cannot be found in the database.', array(
				'{class}' => get_class($owner),
				'{relation}' => $this->relation->name,
				'{joinTable}' => $joinTableName
			)));
		}
		$fks = $this->relation->getJunctionForeignKeys();

		$relatedAlias = $relatedModel->getTableAlias();
		$count = 0;
		$params = array();

		$fkDefined = true;
		foreach ($fks as $i => $fk) {
			if (isset($joinTable->foreignKeys[$fk])) { // FK defined
				list($tableName, $pk) = $joinTable->foreignKeys[$fk];
				if (!isset($map['owner'][$pk]) && $schema->compareTableNames($ownerTable->rawName, $tableName)) {
					$map['owner'][$pk] = $fk;
				} elseif (!isset($map['related'][$pk]) && $schema->compareTableNames($relatedTable->rawName, $tableName)) {
					$map['related'][$pk] = $fk;
				} else {
					$fkDefined = false;
					break;
				}
			} else {
				$fkDefined=false;
				break;
			}
		}

		if (!$fkDefined) {
			$map = array('owner' => array(), 'related' => array());
			$count = 0;
			$params = array();
			foreach($fks as $i => $fk) {
				if ($i < count($ownerTable->primaryKey)) { //i-th component of primary key
					$pk = is_array($ownerTable->primaryKey) ? $ownerTable->primaryKey[$i] : $ownerTable->primaryKey;
					$map['owner'][$pk] = $fk;
				} else {
					$j = $i-count($ownerTable->primaryKey);
					$pk = is_array($relatedTable->primaryKey) ? $relatedTable->primaryKey[$j] : $relatedTable->primaryKey;
					$map['related'][$pk] = $fk;
				}
			}
		}
		if ($map['owner'] === array() || $map['related'] === array()) {
			throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an incomplete foreign key. The foreign key must consist of columns referencing both joining tables.', array(
				'{class}' => get_class($owner),
				'{relation}' => $this->relation->name
			)));
		}
		return $map;
	}

	protected function getRelationCriteria(CActiveRecord $related) {
		$map = $this->getForeignKeyFieldsMap();

		$owner = $this->owner;
		$schema = $owner->getDbConnection()->getSchema();

		$joinTableName = $this->relation->getJunctionTableName();
		$joinTable = $schema->getTable($joinTableName);
		$joinAlias = $schema->quoteTableName($this->relation->name . '_' . $owner->tableAlias);

		$conditions = array();
		$params = array();
		$count = 0;
		foreach ($map['owner'] as $field => $joinTableField) {
			$conditions[] = $joinAlias.'.'.$schema->quoteColumnName($joinTableField).'=:ypl'.$count;
			$params[':ypl'.$count] = $this->owner->$field;
			$count++;
		}
		$relatedAlias = $related->getTableAlias();
		foreach ($map['related'] as $field => $joinTableField) {
			$conditions[] = $joinAlias.'.'.$schema->quoteColumnName($joinTableField).'='.$relatedAlias.'.'.$schema->quoteColumnName($field);
		}

		$crit = new CDbCriteria();
		$crit->mergeWith(array(
			'join' => 'INNER JOIN '.$joinTable->rawName.' '.$joinAlias.' ON ('.implode(') AND (', $conditions).')',
			'params' => $params,
		));
		return $crit;
	}

	/**
	 * Set $records as related to owner.
	 *
	 * @param array $records each record could be CActiveRecord object or primary key.
	 * @access public
	 * @return bool
	 */
	public function set(array $records) {
		$map = $this->getForeignKeyFieldsMap();

		$getJoinFieldValues = function(CActiveRecord $record, array $fieldMap)  {
			$res = array();
			foreach ($fieldMap as $field => $joinTableField) {
				$res[$joinTableField] = $record->$field;
			}
			return $res;
		};

		$ownerFieldValues = $getJoinFieldValues($this->owner, $map['owner']);

		$relatedModel = CActiveRecord::model($this->relation->className);

		$insertValues = array();

		$records = array_map(function($r) use ($relatedModel) {
			return is_object($r) ? $r : $relatedModel->findByPk($r);
		}, $records);

		foreach ($records as $record) {
			$key = implode('~', $record->getAttributes(array_keys($map['related'])));

			$insertValues[$key] = $ownerFieldValues + $getJoinFieldValues($record, $map['related']);
		}

		$relationName = $this->relation->name;
		$deleteCriteria = new CDbCriteria();
		foreach ($this->owner->$relationName() as $oldRelated) {
			$key = implode('~', $oldRelated->getAttributes(array_keys($map['related'])));
			if (!isset($insertValues[$key])) {
				$deleteCriteria->addColumnCondition($ownerFieldValues + $getJoinFieldValues($oldRelated, $map['related']), 'AND', 'OR');
			} else {
				unset($insertValues[$key]);
			}
		}

		$joinTableName = $this->relation->getJunctionTableName();
		$cmdBuilder = $this->owner->getDbConnection()->getCommandBuilder();
		if ($deleteCriteria->condition) {
			$cmdBuilder->createDeleteCommand($joinTableName, $deleteCriteria)->execute();
		}
		if ($insertValues) {
			$cmdBuilder->createMultipleInsertCommand($joinTableName, $insertValues)->execute();
		}

		$this->owner->$relationName = [];
		foreach ($records as $record) {
			$this->owner->addRelatedRecord($relationName, $record, true);
		}
		return true;
	}

	/**
	 * Add $record to owner as related.
	 *
	 * @param CActiveRecord $record
	 * @param mixed $index index to apply for addRelatedRecord
	 * @access public
	 * @return bool
	 */
	public function add(CActiveRecord $record, $index = null) {
		$values = array();
		$map = $this->getForeignKeyFieldsMap();
		foreach ($map['owner'] as $field => $joinTableField) {
			$values[$joinTableField] = $this->owner->$field;
		}
		foreach ($map['related'] as $field => $joinTableField) {
			$values[$joinTableField] = $record->$field;
		}
		$joinTableName = $this->relation->getJunctionTableName();
		if ($r = $this->owner->getDbConnection()->getCommandBuilder()->createInsertCommand($joinTableName, $values)->execute()) {
			$this->owner->addRelatedRecord($this->relation->name, $record, $index ? $index : true);
		}
		return $r;
	}

	/**
	 * Get related to owner record by primary key. If record is not exists or is correspondign record is not related returns null
	 *
	 * @param mixed $pk
	 * @access public
	 * @return null|CActiveRecord
	 */
	public function getByPk($pk) {
		$r = CActiveRecord::model($this->relation->className);
		return $r->findByPk($pk, $this->getRelationCriteria($r));
	}

	/**
	 * Get all related records.
	 *
	 * @access public
	 * @return array
	 */
	public function getAll() {
		$r = CActiveRecord::model($this->relation->className);
		return $r->findAll($this->getRelationCriteria($r));
	}
}

