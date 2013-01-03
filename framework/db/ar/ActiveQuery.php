<?php
/**
 * ActiveFinder class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2012 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db\ar;

use yii\db\dao\Connection;
use yii\db\dao\Command;
use yii\db\dao\QueryBuilder;
use yii\db\dao\BaseQuery;
use yii\base\VectorIterator;
use yii\db\dao\Expression;
use yii\db\Exception;

class ActiveQuery extends BaseQuery
{
	/**
	 * @var string the name of the ActiveRecord class.
	 */
	public $modelClass;
	/**
	 * @var array list of relations that this query should be performed with
	 */
	public $with;
	/**
	 * @var string the name of the column by which the query result should be indexed.
	 * This is only used when the query result is returned as an array when calling [[all()]].
	 */
	public $index;
	/**
	 * @var boolean whether to return each record as an array. If false (default), an object
	 * of [[modelClass]] will be created to represent each record.
	 */
	public $asArray;
	/**
	 * @var array list of scopes that should be applied to this query
	 */
	public $scopes;
	/**
	 * @var string the SQL statement to be executed for retrieving AR records.
	 * This is set by [[ActiveRecord::findBySql()]].
	 */
	public $sql;

	public function __call($name, $params)
	{
		if (method_exists($this->modelClass, $name)) {
			$this->scopes[$name] = $params;
			return $this;
		} else {
			return parent::__call($name, $params);
		}
	}

	/**
	 * Executes query and returns all results as an array.
	 * @return array the query results. If the query results in nothing, an empty array will be returned.
	 */
	public function all()
	{
		$command = $this->createCommand();
		$rows = $command->queryAll();
		$models = $this->createModels($rows);
		if (empty($this->with)) {
			return $models;
		}
	}

	/**
	 * Executes query and returns a single row of result.
	 * @return ActiveRecord|array|boolean a single row of query result. Depending on the setting of [[asArray]],
	 * the query result may be either an array or an ActiveRecord object. False will be returned
	 * if the query results in nothing.
	 */
	public function one()
	{
		$command = $this->createCommand();
		$row = $command->queryRow();
		if ($row === false || $this->asArray) {
			return $row;
		} else {
			$class = $this->modelClass;
			return $class::create($row);
		}
	}

	/**
	 * Returns a scalar value for this query.
	 * The value returned will be the first column in the first row of the query results.
	 * @return string|boolean the value of the first column in the first row of the query result.
	 * False is returned if there is no value.
	 */
	public function value()
	{
		return $this->createCommand()->queryScalar();
	}

	/**
	 * Executes query and returns if matching row exists in the table.
	 * @return bool if row exists in the table.
	 */
	public function exists()
	{
		return $this->select(array(new Expression('1')))->value() !== false;
	}

	/**
	 * Creates a DB command that can be used to execute this query.
	 * @param Connection $db the database connection used to generate the SQL statement.
	 * If this parameter is not given, the `db` application component will be used.
	 * @return Command the created DB command instance.
	 */
	public function createCommand($db = null)
	{
		$modelClass = $this->modelClass;
		if ($db === null) {
			$db = $modelClass::getDbConnection();
		}
		if ($this->sql === null) {
			if ($this->from === null) {
				$tableName = $modelClass::tableName();
				$this->from = array($tableName);
			}
			if (!empty($this->scopes)) {
				foreach ($this->scopes as $name => $config) {
					if (is_integer($name)) {
						$modelClass::$config($this);
					} else {
						array_unshift($config, $this);
						call_user_func_array(array($modelClass, $name), $config);
					}
				}
			}
			/** @var $qb QueryBuilder */
			$qb = $db->getQueryBuilder();
			return $db->createCommand($qb->build($this), $this->params);
		} else {
			return $db->createCommand($this->sql, $this->params);
		}
	}

	public function asArray($value = true)
	{
		$this->asArray = $value;
		return $this;
	}

	public function with()
	{
		$this->with = func_get_args();
		if (isset($this->with[0]) && is_array($this->with[0])) {
			// the parameter is given as an array
			$this->with = $this->with[0];
		}
		return $this;
	}

	public function index($column)
	{
		$this->index = $column;
		return $this;
	}

	public function scopes($names)
	{
		$this->scopes = $names;
		return $this;
	}

	protected function find()
	{
		$modelClass = $this->modelClass;
		/**
		 * @var \yii\db\dao\Connection $db
		 */
		$db = $modelClass::getDbConnection();
		if ($this->sql === null) {
			if ($this->from === null) {
				$tableName = $modelClass::getTableSchema()->name;
				$this->from = array($tableName);
			}
			foreach ($this->scopes as $name => $config) {
				if (is_integer($name)) {
					$modelClass::$config($this);
				} else {
					array_unshift($config, $this);
					call_user_func_array(array($modelClass, $name), $config);
				}
			}
			$this->sql = $db->getQueryBuilder()->build($this);
		}
		$command = $db->createCommand($this->sql, $this->params);
		$rows = $command->queryAll();
		$records = $this->createRecords($rows);
		if ($records !== array()) {
			foreach ($this->with as $name => $config) {
				/** @var Relation $relation */
				$relation = $model->$name();
				foreach ($config as $p => $v) {
					$relation->$p = $v;
				}
				if ($relation->asArray === null) {
					// inherit asArray from parent query
					$relation->asArray = $this->asArray;
				}
				$rs = $relation->findWith($records);
				/*
				foreach ($rs as $r) {
					// find the matching parent record(s)
					// insert into the parent records(s)
				}
				*/
			}
		}

		return $records;
	}

	protected function createModels($rows)
	{
		$models = array();
		if ($this->asArray) {
			if ($this->index === null) {
				return $rows;
			}
			foreach ($rows as $row) {
				$models[$row[$this->index]] = $row;
			}
		} else {
			/** @var $class ActiveRecord */
			$class = $this->modelClass;
			if ($this->index === null) {
				foreach ($rows as $row) {
					$models[] = $class::create($row);
				}
			} else {
				foreach ($rows as $row) {
					$model = $class::create($row);
					$models[$model->{$this->index}] = $model;
				}
			}
		}
		return $models;
	}
}
