<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\db;

use yii\base\InvalidConfigException;
use yii\base\Event;
use yii\base\Model;
use yii\base\InvalidParamException;
use yii\base\ModelEvent;
use yii\base\NotSupportedException;
use yii\base\UnknownMethodException;
use yii\base\InvalidCallException;
use yii\helpers\ArrayHelper;

/**
 * ActiveRecord is the base class for classes representing relational data in terms of objects.
 * ActiveRecord 是所有关系数据对象的基类。
 *
 * See [[\yii\db\ActiveRecord]] for a concrete implementation.
 * 请参考具体实现[[\yii\db\ActiveRecord]]类
 *
 * @property array $dirtyAttributes The changed attribute values (name-value pairs). This property is
 * read-only.
 * 已更改的属性键值对集合，只读属性。
 * @property boolean $isNewRecord Whether the record is new and should be inserted when calling [[save()]].
 * 本条记录是否是新的，如果是新的调用save()方法会执行insert()方法，否则执行update()方法。
 * @property array $oldAttributes The old attribute values (name-value pairs). Note that the type of this
 * property differs in getter and setter. See [[getOldAttributes()]] and [[setOldAttributes()]] for details.
 * 旧的属性键值对集合，注意，本属性的getter和setter方法与其他的不同。
 * @property mixed $oldPrimaryKey The old primary key value. An array (column name => column value) is
 * returned if the primary key is composite. A string is returned otherwise (null will be returned if the key
 * value is null). This property is read-only.
 * 旧的主键值。假如是联合主键，则返回主键值的键值对集合。否则返回字符串。假如是空的，表示主键值为空。只读属性。
 * @property mixed $primaryKey The primary key value. An array (column name => column value) is returned if
 * the primary key is composite. A string is returned otherwise (null will be returned if the key value is null).
 * This property is read-only.
 * 主键值集合，其余同上。
 * @property array $relatedRecords An array of related records indexed by relation names. This property is
 * read-only.
 * 以关系名为索引的关联记录集合。只读属性。
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
abstract class BaseActiveRecord extends Model implements ActiveRecordInterface
{
    /**
     * @event Event an event that is triggered when the record is initialized via [[init()]].
     */
    const EVENT_INIT = 'init';
    /**
     * @event Event an event that is triggered after the record is created and populated with query result.
     */
    const EVENT_AFTER_FIND = 'afterFind';
    /**
     * @event ModelEvent an event that is triggered before inserting a record.
     * You may set [[ModelEvent::isValid]] to be false to stop the insertion.
     */
    const EVENT_BEFORE_INSERT = 'beforeInsert';
    /**
     * @event Event an event that is triggered after a record is inserted.
     */
    const EVENT_AFTER_INSERT = 'afterInsert';
    /**
     * @event ModelEvent an event that is triggered before updating a record.
     * You may set [[ModelEvent::isValid]] to be false to stop the update.
     */
    const EVENT_BEFORE_UPDATE = 'beforeUpdate';
    /**
     * @event Event an event that is triggered after a record is updated.
     */
    const EVENT_AFTER_UPDATE = 'afterUpdate';
    /**
     * @event ModelEvent an event that is triggered before deleting a record.
     * You may set [[ModelEvent::isValid]] to be false to stop the deletion.
     */
    const EVENT_BEFORE_DELETE = 'beforeDelete';
    /**
     * @event Event an event that is triggered after a record is deleted.
     */
    const EVENT_AFTER_DELETE = 'afterDelete';

    /**
     * @var array attribute values indexed by attribute names
     */
    private $_attributes = [];
    /**
     * @var array|null old attribute values indexed by attribute names.
     * This is `null` if the record [[isNewRecord|is new]].
     */
    private $_oldAttributes;
    /**
     * @var array related models indexed by the relation names
     */
    private $_related = [];


    /**
     * @inheritdoc
     * @return static|null ActiveRecord instance matching the condition, or `null` if nothing matches.
     */
    public static function findOne($condition)
    {
        return static::findByCondition($condition)->one();
    }

    /**
     * @inheritdoc
     * @return static[] an array of ActiveRecord instances, or an empty array if nothing matches.
     */
    public static function findAll($condition)
    {
        return static::findByCondition($condition)->all();
    }

    /**
     * Finds ActiveRecord instance(s) by the given condition.
     * This method is internally called by [[findOne()]] and [[findAll()]].
     * @param mixed $condition please refer to [[findOne()]] for the explanation of this parameter
     * @return ActiveQueryInterface the newly created [[ActiveQueryInterface|ActiveQuery]] instance.
     * @throws InvalidConfigException if there is no primary key defined
     * @internal
     */
    protected static function findByCondition($condition)
    {
        $query = static::find();

        if (!ArrayHelper::isAssociative($condition)) {
            // query by primary key
            $primaryKey = static::primaryKey();
            if (isset($primaryKey[0])) {
                $condition = [$primaryKey[0] => $condition];
            } else {
                throw new InvalidConfigException('"' . get_called_class() . '" must have a primary key.');
            }
        }

        return $query->andWhere($condition);
    }

    /**
     * Updates the whole table using the provided attribute values and conditions.
     * For example, to change the status to be 1 for all customers whose status is 2:
     * 使用提供的属性值和条件更新整个数据库表，例如将所有status为2的用户更新为status为1：
     *
     * ```php
     * Customer::updateAll(['status' => 1], 'status = 2');
     * ```
     *
     * @param array $attributes attribute values (name-value pairs) to be saved into the table
     * @param string|array $condition the conditions that will be put in the WHERE part of the UPDATE SQL.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * 查询条件
     * @return integer the number of rows updated
     * @throws NotSupportedException if not overridden
     */
    public static function updateAll($attributes, $condition = '')
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    /**
     * Updates the whole table using the provided counter changes and conditions.
     * For example, to increment all customers' age by 1,
     * 【具体用途还是看子类实现吧，现在没太看懂】例如，将所有用户年龄加1
     *
     * ```php
     * Customer::updateAllCounters(['age' => 1]);
     * ```
     *
     * @param array $counters the counters to be updated (attribute name => increment value).
     * Use negative values if you want to decrement the counters.
     * @param string|array $condition the conditions that will be put in the WHERE part of the UPDATE SQL.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @return integer the number of rows updated
     * @throws NotSupportedException if not overrided
     */
    public static function updateAllCounters($counters, $condition = '')
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    /**
     * Deletes rows in the table using the provided conditions.
     * WARNING: If you do not specify any condition, this method will delete ALL rows in the table.
     * 使用给定的条件删除记录。注意：假如没给任何条件，本方法将会删除整张表。
     *
     * For example, to delete all customers whose status is 3:
     *
     * ```php
     * Customer::deleteAll('status = 3');
     * ```
     *
     * @param string|array $condition the conditions that will be put in the WHERE part of the DELETE SQL.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return integer the number of rows deleted
     * @throws NotSupportedException if not overrided
     */
    public static function deleteAll($condition = '', $params = [])
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    /**
     * Returns the name of the column that stores the lock version for implementing optimistic locking.
     * 返回储存了实现乐观锁的锁版本号的数据库列名。
     *
     * Optimistic locking allows multiple users to access the same record for edits and avoids
     * potential conflicts. In case when a user attempts to save the record upon some staled data
     * (because another user has modified the data), a [[StaleObjectException]] exception will be thrown,
     * and the update or deletion is skipped.
     * 乐观锁机制允许多个用户同时使用相同记录编辑，并防止潜在冲突。假如一个用户尝试将记录保存在国企数据中
     * （因为此时其他用户已经更改了数据），此时将会触发一个[[StaleObjectException]] 异常，并且更新和删除动作
     * 会被跳过。
     *
     * Optimistic locking is only supported by [[update()]] and [[delete()]].
     * 乐观锁机制仅在[[update()]] 和 [[delete()]]操作中被支持。
     *
     * To use Optimistic locking:
     * 使用乐观锁的步骤。
     *
     * 1. Create a column to store the version number of each row. The column type should be `BIGINT DEFAULT 0`.
     *    Override this method to return the name of this column.
     *    创建一个列用于储存每一行的版本号。该列的类型应该为`BIGINT DEFAULT 0`。重写本方法要返回该列名。
     * 2. Add a `required` validation rule for the version column to ensure the version value is submitted.
     *    为该列增加一个`required`的验证规则以保证版本号必须提交。
     * 3. In the Web form that collects the user input, add a hidden field that stores
     *    the lock version of the recording being updated.
     *    在收集用户输入数据的表单中，增加一个隐藏字段用于储存记录的版本号。
     * 4. In the controller action that does the data updating, try to catch the [[StaleObjectException]]
     *    and implement necessary business logic (e.g. merging the changes, prompting stated data)
     *    to resolve the conflict.
     *    在处理数据上传的控制器动作中，尝试捕捉[[StaleObjectException]]异常 并且实现必要的业务逻辑（如覆盖
     *    数据或提示数据冲突）用来解决数据冲突。
     *
     * @return string the column name that stores the lock version of a table row.
     * If null is returned (default implemented), optimistic locking will not be supported.
     */
    public function optimisticLock()
    {
        return null;
    }

    /**
     * PHP getter magic method.
     * This method is overridden so that attributes and related objects can be accessed like properties.
     * 重写本方法以达到以对象属性的方式显示数据库字段的目的。
     *
     * @param string $name property name
     * @throws \yii\base\InvalidParamException if relation name is wrong
     * @return mixed property value
     * @see getAttribute()
     */
    public function __get($name)
    {
        if (isset($this->_attributes[$name]) || array_key_exists($name, $this->_attributes)) {
            return $this->_attributes[$name];
            // 有本属性，但是$this->_attributes里面找不到，则返回null，说明没被赋值。
        } elseif ($this->hasAttribute($name)) {
            return null;
        } else {
            if (isset($this->_related[$name]) || array_key_exists($name, $this->_related)) {
                return $this->_related[$name];
            }
            $value = parent::__get($name);
            if ($value instanceof ActiveQueryInterface) {
                return $this->_related[$name] = $value->findFor($name, $this);
            } else {
                return $value;
            }
        }
    }

    /**
     * PHP setter magic method.
     * This method is overridden so that AR attributes can be accessed like properties.
     * 如果该属性是关联数据库的字段，则存进$this->_attributes属性集合，否则执行父类方法。
     * @param string $name property name
     * @param mixed $value property value
     */
    public function __set($name, $value)
    {
        if ($this->hasAttribute($name)) {
            $this->_attributes[$name] = $value;
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * Checks if a property value is null.
     * This method overrides the parent implementation by checking if the named attribute is null or not.
     * @param string $name the property name or the event name
     * @return boolean whether the property value is null
     */
    public function __isset($name)
    {
        try {
            return $this->__get($name) !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Sets a component property to be null.
     * This method overrides the parent implementation by clearing
     * the specified attribute value.
     * @param string $name the property name or the event name
     */
    public function __unset($name)
    {
        if ($this->hasAttribute($name)) {
            unset($this->_attributes[$name]);
        } elseif (array_key_exists($name, $this->_related)) {
            unset($this->_related[$name]);
        } elseif ($this->getRelation($name, false) === null) {
            parent::__unset($name);
        }
    }

    /**
     * Declares a `has-one` relation.
     * The declaration is returned in terms of a relational [[ActiveQuery]] instance
     * through which the related record can be queried and retrieved back.
     * 定义一个`has-one` 的关系
     *
     * A `has-one` relation means that there is at most one related record matching
     * the criteria set by this relation, e.g., a customer has one country.
     *
     * For example, to declare the `country` relation for `Customer` class, we can write
     * the following code in the `Customer` class:
     *
     * ```php
     * public function getCountry()
     * {
     *     return $this->hasOne(Country::className(), ['id' => 'country_id']);
     * }
     * ```
     *
     * Note that in the above, the 'id' key in the `$link` parameter refers to an attribute name
     * in the related class `Country`, while the 'country_id' value refers to an attribute name
     * in the current AR class.
     *
     * Call methods declared in [[ActiveQuery]] to further customize the relation.
     *
     * @param string $class the class name of the related record
     * @param array $link the primary-foreign key constraint. The keys of the array refer to
     * the attributes of the record associated with the `$class` model, while the values of the
     * array refer to the corresponding attributes in **this** AR class.
     * @return ActiveQueryInterface the relational query object.
     */
    public function hasOne($class, $link)
    {
        /* @var $class ActiveRecordInterface */
        /* @var $query ActiveQuery */
        $query = $class::find();
        $query->primaryModel = $this;
        $query->link = $link;
        $query->multiple = false;
        return $query;
    }

    /**
     * Declares a `has-many` relation.
     * The declaration is returned in terms of a relational [[ActiveQuery]] instance
     * through which the related record can be queried and retrieved back.
     *
     * A `has-many` relation means that there are multiple related records matching
     * the criteria set by this relation, e.g., a customer has many orders.
     *
     * For example, to declare the `orders` relation for `Customer` class, we can write
     * the following code in the `Customer` class:
     *
     * ```php
     * public function getOrders()
     * {
     *     return $this->hasMany(Order::className(), ['customer_id' => 'id']);
     * }
     * ```
     *
     * Note that in the above, the 'customer_id' key in the `$link` parameter refers to
     * an attribute name in the related class `Order`, while the 'id' value refers to
     * an attribute name in the current AR class.
     *
     * Call methods declared in [[ActiveQuery]] to further customize the relation.
     *
     * @param string $class the class name of the related record
     * @param array $link the primary-foreign key constraint. The keys of the array refer to
     * the attributes of the record associated with the `$class` model, while the values of the
     * array refer to the corresponding attributes in **this** AR class.
     * @return ActiveQueryInterface the relational query object.
     */
    public function hasMany($class, $link)
    {
        /* @var $class ActiveRecordInterface */
        /* @var $query ActiveQuery */
        $query = $class::find();
        $query->primaryModel = $this;
        $query->link = $link;
        $query->multiple = true;
        return $query;
    }

    /**
     * Populates the named relation with the related records.
     * Note that this method does not check if the relation exists or not.
     * @param string $name the relation name (case-sensitive)
     * @param ActiveRecordInterface|array|null $records the related records to be populated into the relation.
     */
    public function populateRelation($name, $records)
    {
        $this->_related[$name] = $records;
    }

    /**
     * Check whether the named relation has been populated with records.
     * @param string $name the relation name (case-sensitive)
     * @return boolean whether relation has been populated with records.
     */
    public function isRelationPopulated($name)
    {
        return array_key_exists($name, $this->_related);
    }

    /**
     * Returns all populated related records.
     * @return array an array of related records indexed by relation names.
     */
    public function getRelatedRecords()
    {
        return $this->_related;
    }

    /**
     * Returns a value indicating whether the model has an attribute with the specified name.
     * 判断指定的属性是否在本AR类内
     * @param string $name the name of the attribute
     * @return boolean whether the model has an attribute with the specified name.
     */
    public function hasAttribute($name)
    {
        return isset($this->_attributes[$name]) || in_array($name, $this->attributes());
    }

    /**
     * Returns the named attribute value.
     * If this record is the result of a query and the attribute is not loaded,
     * null will be returned.
     * 根据属性名返回属性值
     * @param string $name the attribute name
     * @return mixed the attribute value. Null if the attribute is not set or does not exist.
     * @see hasAttribute()
     */
    public function getAttribute($name)
    {
        return isset($this->_attributes[$name]) ? $this->_attributes[$name] : null;
    }

    /**
     * Sets the named attribute value.
     * 为数据库字段关联属性指定的属性名赋指定的值
     * @param string $name the attribute name
     * @param mixed $value the attribute value.
     * @throws InvalidParamException if the named attribute does not exist.
     * @see hasAttribute()
     */
    public function setAttribute($name, $value)
    {
        if ($this->hasAttribute($name)) {
            $this->_attributes[$name] = $value;
        } else {
            throw new InvalidParamException(get_class($this) . ' has no attribute named "' . $name . '".');
        }
    }

    /**
     * Returns the old attribute values.
     * 获取旧的属性值集合
     * @return array the old attribute values (name-value pairs)
     */
    public function getOldAttributes()
    {
        return $this->_oldAttributes === null ? [] : $this->_oldAttributes;
    }

    /**
     * Sets the old attribute values.
     * All existing old attribute values will be discarded.
     * 获取旧的属性值集合，所有存在的旧的属性值都会被抛弃
     * @param array|null $values old attribute values to be set.
     * If set to `null` this record is considered to be [[isNewRecord|new]].
     */
    public function setOldAttributes($values)
    {
        $this->_oldAttributes = $values;
    }

    /**
     * Returns the old value of the named attribute.
     * If this record is the result of a query and the attribute is not loaded,
     * null will be returned.
     * 根据属性值获取指定的旧属性名。假如本条记录是一条查询结果，并且属性未被载入，
     * 则会返回null。
     * @param string $name the attribute name
     * @return mixed the old attribute value. Null if the attribute is not loaded before
     * or does not exist.
     * @see hasAttribute()
     */
    public function getOldAttribute($name)
    {
        return isset($this->_oldAttributes[$name]) ? $this->_oldAttributes[$name] : null;
    }

    /**
     * Sets the old value of the named attribute.
     * 按照文件名，设置旧的属性值
     * @param string $name the attribute name
     * @param mixed $value the old attribute value.
     * @throws InvalidParamException if the named attribute does not exist.
     * @see hasAttribute()
     */
    public function setOldAttribute($name, $value)
    {
        if (isset($this->_oldAttributes[$name]) || $this->hasAttribute($name)) {
            $this->_oldAttributes[$name] = $value;
        } else {
            throw new InvalidParamException(get_class($this) . ' has no attribute named "' . $name . '".');
        }
    }

    /**
     * Marks an attribute dirty.
     * This method may be called to force updating a record when calling [[update()]],
     * even if there is no change being made to the record.
     * 污染一个属性。本方法可以用于调用[[update()]]强制更新一条记录，甚至在该条记录
     * 没有被更改的情况下
     * @param string $name the attribute name
     */
    public function markAttributeDirty($name)
    {
        unset($this->_oldAttributes[$name]);
    }

    /**
     * Returns a value indicating whether the named attribute has been changed.
     * @param string $name the name of the attribute.
     * @param bool $identical whether the comparison of new and old value is made for
     * identical values using `===`, defaults to `true`. Otherwise `==` is used for comparison.
     * This parameter is available since version 2.0.4.
     * 开启严格不等于
     * @return bool whether the attribute has been changed
     */
    public function isAttributeChanged($name, $identical = true)
    {
        if (isset($this->_attributes[$name], $this->_oldAttributes[$name])) {
            if ($identical) {
                return $this->_attributes[$name] !== $this->_oldAttributes[$name];
            } else {
                return $this->_attributes[$name] != $this->_oldAttributes[$name];
            }
        } else {
            return isset($this->_attributes[$name]) || isset($this->_oldAttributes[$name]);
        }
    }

    /**
     * Returns the attribute values that have been modified since they are loaded or saved most recently.
     * 返回从最近的读取和保存数据库的操作到现在 这段时间内被修改过的属性值
     *
     * The comparison of new and old values is made for identical values using `===`.
     * 比较新旧属性值使用的是严格不等于
     *
     * @param string[]|null $names the names of the attributes whose values may be returned if they are
     * changed recently. If null, [[attributes()]] will be used.
     * @return array the changed attribute values (name-value pairs)
     */
    public function getDirtyAttributes($names = null)
    {
        if ($names === null) {
            $names = $this->attributes();
        }
        $names = array_flip($names);
        $attributes = [];
        if ($this->_oldAttributes === null) {
            foreach ($this->_attributes as $name => $value) {
                if (isset($names[$name])) {
                    $attributes[$name] = $value;
                }
            }
        } else {
            foreach ($this->_attributes as $name => $value) {
                if (isset($names[$name]) && (!array_key_exists($name, $this->_oldAttributes) || $value !== $this->_oldAttributes[$name])) {
                    $attributes[$name] = $value;
                }
            }
        }
        return $attributes;
    }

    /**
     * Saves the current record.
     * 保存当前记录
     *
     * This method will call [[insert()]] when [[isNewRecord]] is true, or [[update()]]
     * when [[isNewRecord]] is false.
     * 如果是新记录调用insert()如果不是新记录，则调用update()
     *
     * For example, to save a customer record:
     * 例如：
     *
     * ```php
     * $customer = new Customer; // or $customer = Customer::findOne($id);
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->save();
     * ```
     *
     * @param boolean $runValidation whether to perform validation (calling [[validate()]])
     * before saving the record. Defaults to `true`. If the validation fails, the record
     * will not be saved to the database and this method will return `false`.
     * @param array $attributeNames list of attribute names that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return boolean whether the saving succeeded (i.e. no validation errors occurred).
     */
    public function save($runValidation = true, $attributeNames = null)
    {
        if ($this->getIsNewRecord()) {
            return $this->insert($runValidation, $attributeNames);
        } else {
            return $this->update($runValidation, $attributeNames) !== false;
        }
    }

    /**
     * Saves the changes to this active record into the associated database table.
     * 保存更改到关联的数据库表中
     *
     * This method performs the following steps in order:
     * 本方法依次执行以下几步：
     *
     * 1. call [[beforeValidate()]] when `$runValidation` is true. If [[beforeValidate()]]
     *    returns `false`, the rest of the steps will be skipped;
     *    当`$runValidation`为真时，调用[[beforeValidate()]] ，假如[[beforeValidate()]] 返回
     *    false，则以下步骤均不会执行。
     * 2. call [[afterValidate()]] when `$runValidation` is true. If validation
     *    failed, the rest of the steps will be skipped;
     *    当`$runValidation`为真时，调用[[afterValidate()]]方法，假如验证器验证失败，则其余
     *     步骤也不会执行。
     * 3. call [[beforeSave()]]. If [[beforeSave()]] returns `false`,
     *    the rest of the steps will be skipped;
     *    调用[[beforeSave()]] 方法，假如[[beforeSave()]]方法返回false，那么其余的步骤也不会被执行
     * 4. save the record into database. If this fails, it will skip the rest of the steps;
     *    执行SQL语句，如果出错，则其余的部分也不会被执行。
     * 5. call [[afterSave()]];
     *    调用 [[afterSave()]];
     *
     * In the above step 1, 2, 3 and 5, events [[EVENT_BEFORE_VALIDATE]],
     * [[EVENT_AFTER_VALIDATE]], [[EVENT_BEFORE_UPDATE]], and [[EVENT_AFTER_UPDATE]]
     * will be raised by the corresponding methods.
     * 在1，2，3，5四个步骤中，四个事件会被依次触发。
     *
     * Only the [[dirtyAttributes|changed attribute values]] will be saved into database.
     * 只有被更改的属性值，也就是[[dirtyAttributes|changed attribute values]] 会被保存到数据库中
     *
     * For example, to update a customer record:
     * 例如：
     *
     * ```php
     * $customer = Customer::findOne($id);
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->update();
     * ```
     *
     * Note that it is possible the update does not affect any row in the table.
     * In this case, this method will return 0. For this reason, you should use the following
     * code to check if update() is successful or not:
     * 注意，有可能update()方法没影响任何数据库行。假如是这样，那么update()方法会返回0，因此
     * 应该使用严格不等于。
     *
     * ```php
     * if ($customer->update() !== false) {
     *     // update successful
     * } else {
     *     // update failed
     * }
     * ```
     *
     * @param boolean $runValidation whether to perform validation (calling [[validate()]])
     * before saving the record. Defaults to `true`. If the validation fails, the record
     * will not be saved to the database and this method will return `false`.
     * @param array $attributeNames list of attribute names that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return integer|boolean the number of rows affected, or false if validation fails
     * or [[beforeSave()]] stops the updating process.
     * @throws StaleObjectException if [[optimisticLock|optimistic locking]] is enabled and the data
     * being updated is outdated.
     * @throws Exception in case update failed.
     */
    public function update($runValidation = true, $attributeNames = null)
    {
        if ($runValidation && !$this->validate($attributeNames)) {
            return false;
        }
        return $this->updateInternal($attributeNames);
    }

    /**
     * Updates the specified attributes.
     * 更新指定的属性。
     *
     * This method is a shortcut to [[update()]] when data validation is not needed
     * and only a small set attributes need to be updated.
     * 在数据不需要进行合法性验证，并且只有一小部分数据需要更新的时候，本方法是uodate()的快捷方式。
     *
     * You may specify the attributes to be updated as name list or name-value pairs.
     * If the latter, the corresponding attribute values will be modified accordingly.
     * The method will then save the specified attributes into database.
     * 你可以指定更新参数为属性名集合或键值对集合，假如是后者，相应的属性值就是被修改后的值，
     * 本方法会保存相应的值到数据库中。
     *
     * Note that this method will **not** perform data validation and will **not** trigger events.
     * 注意，本方法不会执行认证，也不会触发事件。
     *
     * @param array $attributes the attributes (names or name-value pairs) to be updated
     * @return integer the number of rows affected.
     */
    public function updateAttributes($attributes)
    {
        $attrs = [];
        foreach ($attributes as $name => $value) {
            if (is_int($name)) {
                $attrs[] = $value;
            } else {
                $this->$name = $value;
                $attrs[] = $name;
            }
        }

        $values = $this->getDirtyAttributes($attrs);
        if (empty($values)) {
            return 0;
        }

        $rows = $this->updateAll($values, $this->getOldPrimaryKey(true));

        foreach ($values as $name => $value) {
            $this->_oldAttributes[$name] = $this->_attributes[$name];
        }

        return $rows;
    }

    /**
     * @see update()
     * updateInternal() 由 update() 调用，
     * 类似的有deleteInternal() ，由ActiveRecord定义，这里略去。
     * @param array $attributes attributes to update
     * @return integer number of rows updated
     * @throws StaleObjectException
     */
    protected function updateInternal($attributes = null)
    {
        /*
         *  beforeSave() 会触发相应的before事件
         *  而且如果beforeSave()返回false，就可以中止更新过程。
         */
        if (!$this->beforeSave(false)) {
            return false;
        }
        // 获取待更新的字段键值对
        $values = $this->getDirtyAttributes($attributes);
        /*
         * 没有字段有修改，那么实际上是不需要更新的。【影响行数为0】
         * 因此，直接调用afterSave()来触发相应的after事件。
         */
        if (empty($values)) {
            $this->afterSave(false, $values);
            return 0;
        }
        /** 
         * 这里开始是实际的操作数据库逻辑
         * 【把原来ActiveRecord的主键作为等下更新记录的条件，
         *  也就是说，等下更新的，最多只有1个记录。】
         */
        $condition = $this->getOldPrimaryKey(true);
        $lock = $this->optimisticLock();
        /**
         * 如果开启了乐观锁，则将锁的值加1
         */
        if ($lock !== null) {
            // 待更新版本号为旧的版本号+1
            $values[$lock] = $this->$lock + 1;
            // 旧的版本号为查询条件 查找条件有两个：旧的主键，旧的版本号
            $condition[$lock] = $this->$lock;
        }
        // We do not check the return value of updateAll() because it's possible
        // that the UPDATE statement doesn't change anything and thus returns 0.
        /*
         * 在这里不检查 updateAll()的返回值，因为很有可能更新操作未改变任何行，因此
         * 影响的行数为0
         */
        $rows = $this->updateAll($values, $condition);

        // 如果已经启用了乐观锁，但是却没有完成更新，或者更新的记录数为0；
        // 那就说明是由于 ver 不匹配，记录被修改过了，于是抛出异常。
        if ($lock !== null && !$rows) {
            throw new StaleObjectException('The object being updated is outdated.');
        }

        // 假如设置了版本号，即开启了乐观锁，在这里重新为版本号赋值。
        if (isset($values[$lock])) {
            $this->$lock = $values[$lock];
        }

        /*
         * $changedAttributes 被更改了的属性键值对集合
         */
        $changedAttributes = [];
        foreach ($values as $name => $value) {
            $changedAttributes[$name] = isset($this->_oldAttributes[$name]) ? $this->_oldAttributes[$name] : null;
            $this->_oldAttributes[$name] = $value;
        }
        // 这里触发了afterSave事件
        $this->afterSave(false, $changedAttributes);

        return $rows;
    }

    /**
     * Updates one or several counter columns for the current AR object.
     * Note that this method differs from [[updateAllCounters()]] in that it only
     * saves counters for the current AR object.
     *
     * An example usage is as follows:
     *
     * ```php
     * $post = Post::findOne($id);
     * $post->updateCounters(['view_count' => 1]);
     * ```
     *
     * @param array $counters the counters to be updated (attribute name => increment value)
     * Use negative values if you want to decrement the counters.
     * @return boolean whether the saving is successful
     * @see updateAllCounters()
     */
    public function updateCounters($counters)
    {
        if ($this->updateAllCounters($counters, $this->getOldPrimaryKey(true)) > 0) {
            foreach ($counters as $name => $value) {
                if (!isset($this->_attributes[$name])) {
                    $this->_attributes[$name] = $value;
                } else {
                    $this->_attributes[$name] += $value;
                }
                $this->_oldAttributes[$name] = $this->_attributes[$name];
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Deletes the table row corresponding to this active record.
     * 删除数据库表中与本AR对象相关的行。
     *
     * This method performs the following steps in order:
     * 本方法按顺序执行以下几步：
     *
     * 1. call [[beforeDelete()]]. If the method returns false, it will skip the
     *    rest of the steps;
     *    调用[[beforeDelete()]]触发删除前事件，假如返回false，则会跳过剩下步骤。
     * 2. delete the record from the database;
     *    从数据库中删除记录。
     * 3. call [[afterDelete()]].
     *    触发删除后事件。
     *
     * In the above step 1 and 3, events named [[EVENT_BEFORE_DELETE]] and [[EVENT_AFTER_DELETE]]
     * will be raised by the corresponding methods.
     *
     * @return integer|false the number of rows deleted, or false if the deletion is unsuccessful for some reason.
     * Note that it is possible the number of rows deleted is 0, even though the deletion execution is successful.
     * @throws StaleObjectException if [[optimisticLock|optimistic locking]] is enabled and the data
     * being deleted is outdated.
     * @throws Exception in case delete failed.
     */
    public function delete()
    {
        $result = false;
        if ($this->beforeDelete()) {
            // we do not check the return value of deleteAll() because it's possible
            // the record is already deleted in the database and thus the method will return 0
            // 旧的版本号是主键
            $condition = $this->getOldPrimaryKey(true);
            $lock = $this->optimisticLock();
            // 如果启用了乐观锁，那就再加一个条件
            if ($lock !== null) {
                $condition[$lock] = $this->$lock;
            }
            $result = $this->deleteAll($condition);
            if ($lock !== null && !$result) {
                throw new StaleObjectException('The object being deleted is outdated.');
            }
            $this->_oldAttributes = null;
            $this->afterDelete();
        }

        return $result;
    }

    /**
     * Returns a value indicating whether the current record is new.
     * @return boolean whether the record is new and should be inserted when calling [[save()]].
     */
    public function getIsNewRecord()
    {
        return $this->_oldAttributes === null;
    }

    /**
     * Sets the value indicating whether the record is new.
     * @param boolean $value whether the record is new and should be inserted when calling [[save()]].
     * @see getIsNewRecord()
     */
    public function setIsNewRecord($value)
    {
        $this->_oldAttributes = $value ? null : $this->_attributes;
    }

    /**
     * Initializes the object.
     * This method is called at the end of the constructor.
     * The default implementation will trigger an [[EVENT_INIT]] event.
     * If you override this method, make sure you call the parent implementation at the end
     * to ensure triggering of the event.
     */
    public function init()
    {
        parent::init();
        $this->trigger(self::EVENT_INIT);
    }

    /**
     * This method is called when the AR object is created and populated with the query result.
     * The default implementation will trigger an [[EVENT_AFTER_FIND]] event.
     * When overriding this method, make sure you call the parent implementation to ensure the
     * event is triggered.
     */
    public function afterFind()
    {
        $this->trigger(self::EVENT_AFTER_FIND);
    }

    /**
     * This method is called at the beginning of inserting or updating a record.
     * The default implementation will trigger an [[EVENT_BEFORE_INSERT]] event when `$insert` is true,
     * or an [[EVENT_BEFORE_UPDATE]] event if `$insert` is false.
     * When overriding this method, make sure you call the parent implementation like the following:
     *
     * ```php
     * public function beforeSave($insert)
     * {
     *     if (parent::beforeSave($insert)) {
     *         // ...custom code here...
     *         return true;
     *     } else {
     *         return false;
     *     }
     * }
     * ```
     *
     * @param boolean $insert whether this method called while inserting a record.
     * If false, it means the method is called while updating a record.
     * @return boolean whether the insertion or updating should continue.
     * If false, the insertion or updating will be cancelled.
     */
    public function beforeSave($insert)
    {
        $event = new ModelEvent;
        $this->trigger($insert ? self::EVENT_BEFORE_INSERT : self::EVENT_BEFORE_UPDATE, $event);

        return $event->isValid;
    }

    /**
     * This method is called at the end of inserting or updating a record.
     * The default implementation will trigger an [[EVENT_AFTER_INSERT]] event when `$insert` is true,
     * or an [[EVENT_AFTER_UPDATE]] event if `$insert` is false. The event class used is [[AfterSaveEvent]].
     * When overriding this method, make sure you call the parent implementation so that
     * the event is triggered.
     * @param boolean $insert whether this method called while inserting a record.
     * If false, it means the method is called while updating a record.
     * @param array $changedAttributes The old values of attributes that had changed and were saved.
     * You can use this parameter to take action based on the changes made for example send an email
     * when the password had changed or implement audit trail that tracks all the changes.
     * `$changedAttributes` gives you the old attribute values while the active record (`$this`) has
     * already the new, updated values.
     */
    public function afterSave($insert, $changedAttributes)
    {
        $this->trigger($insert ? self::EVENT_AFTER_INSERT : self::EVENT_AFTER_UPDATE, new AfterSaveEvent([
            'changedAttributes' => $changedAttributes
        ]));
    }

    /**
     * This method is invoked before deleting a record.
     * The default implementation raises the [[EVENT_BEFORE_DELETE]] event.
     * When overriding this method, make sure you call the parent implementation like the following:
     *
     * ```php
     * public function beforeDelete()
     * {
     *     if (parent::beforeDelete()) {
     *         // ...custom code here...
     *         return true;
     *     } else {
     *         return false;
     *     }
     * }
     * ```
     *
     * @return boolean whether the record should be deleted. Defaults to true.
     */
    public function beforeDelete()
    {
        $event = new ModelEvent;
        $this->trigger(self::EVENT_BEFORE_DELETE, $event);

        return $event->isValid;
    }

    /**
     * This method is invoked after deleting a record.
     * The default implementation raises the [[EVENT_AFTER_DELETE]] event.
     * You may override this method to do postprocessing after the record is deleted.
     * Make sure you call the parent implementation so that the event is raised properly.
     */
    public function afterDelete()
    {
        $this->trigger(self::EVENT_AFTER_DELETE);
    }

    /**
     * Repopulates this active record with the latest data.
     * @return boolean whether the row still exists in the database. If true, the latest data
     * will be populated to this active record. Otherwise, this record will remain unchanged.
     */
    public function refresh()
    {
        /* @var $record BaseActiveRecord */
        $record = $this->findOne($this->getPrimaryKey(true));
        if ($record === null) {
            return false;
        }
        foreach ($this->attributes() as $name) {
            $this->_attributes[$name] = isset($record->_attributes[$name]) ? $record->_attributes[$name] : null;
        }
        $this->_oldAttributes = $this->_attributes;
        $this->_related = [];

        return true;
    }

    /**
     * Returns a value indicating whether the given active record is the same as the current one.
     * The comparison is made by comparing the table names and the primary key values of the two active records.
     * If one of the records [[isNewRecord|is new]] they are also considered not equal.
     * @param ActiveRecordInterface $record record to compare to
     * @return boolean whether the two active records refer to the same row in the same database table.
     */
    public function equals($record)
    {
        if ($this->getIsNewRecord() || $record->getIsNewRecord()) {
            return false;
        }

        return get_class($this) === get_class($record) && $this->getPrimaryKey() === $record->getPrimaryKey();
    }

    /**
     * Returns the primary key value(s).
     * @param boolean $asArray whether to return the primary key value as an array. If true,
     * the return value will be an array with column names as keys and column values as values.
     * Note that for composite primary keys, an array will always be returned regardless of this parameter value.
     * @property mixed The primary key value. An array (column name => column value) is returned if
     * the primary key is composite. A string is returned otherwise (null will be returned if
     * the key value is null).
     * @return mixed the primary key value. An array (column name => column value) is returned if the primary key
     * is composite or `$asArray` is true. A string is returned otherwise (null will be returned if
     * the key value is null).
     */
    public function getPrimaryKey($asArray = false)
    {
        $keys = $this->primaryKey();
        if (!$asArray && count($keys) === 1) {
            return isset($this->_attributes[$keys[0]]) ? $this->_attributes[$keys[0]] : null;
        } else {
            $values = [];
            foreach ($keys as $name) {
                $values[$name] = isset($this->_attributes[$name]) ? $this->_attributes[$name] : null;
            }

            return $values;
        }
    }

    /**
     * Returns the old primary key value(s).
     * This refers to the primary key value that is populated into the record
     * after executing a find method (e.g. find(), findOne()).
     * The value remains unchanged even if the primary key attribute is manually assigned with a different value.
     * @param boolean $asArray whether to return the primary key value as an array. If true,
     * the return value will be an array with column name as key and column value as value.
     * If this is false (default), a scalar value will be returned for non-composite primary key.
     * @property mixed The old primary key value. An array (column name => column value) is
     * returned if the primary key is composite. A string is returned otherwise (null will be
     * returned if the key value is null).
     * @return mixed the old primary key value. An array (column name => column value) is returned if the primary key
     * is composite or `$asArray` is true. A string is returned otherwise (null will be returned if
     * the key value is null).
     * @throws Exception if the AR model does not have a primary key
     */
    public function getOldPrimaryKey($asArray = false)
    {
        $keys = $this->primaryKey();
        if (empty($keys)) {
            throw new Exception(get_class($this) . ' does not have a primary key. You should either define a primary key for the corresponding table or override the primaryKey() method.');
        }
        if (!$asArray && count($keys) === 1) {
            return isset($this->_oldAttributes[$keys[0]]) ? $this->_oldAttributes[$keys[0]] : null;
        } else {
            $values = [];
            foreach ($keys as $name) {
                $values[$name] = isset($this->_oldAttributes[$name]) ? $this->_oldAttributes[$name] : null;
            }

            return $values;
        }
    }

    /**
     * Populates an active record object using a row of data from the database/storage.
     *
     * This is an internal method meant to be called to create active record objects after
     * fetching data from the database. It is mainly used by [[ActiveQuery]] to populate
     * the query results into active records.
     *
     * When calling this method manually you should call [[afterFind()]] on the created
     * record to trigger the [[EVENT_AFTER_FIND|afterFind Event]].
     *
     * @param BaseActiveRecord $record the record to be populated. In most cases this will be an instance
     * created by [[instantiate()]] beforehand.
     * @param array $row attribute values (name => value)
     */
    public static function populateRecord($record, $row)
    {
        $columns = array_flip($record->attributes());
        foreach ($row as $name => $value) {
            if (isset($columns[$name])) {
                $record->_attributes[$name] = $value;
            } elseif ($record->canSetProperty($name)) {
                $record->$name = $value;
            }
        }
        $record->_oldAttributes = $record->_attributes;
    }

    /**
     * Creates an active record instance.
     *
     * This method is called together with [[populateRecord()]] by [[ActiveQuery]].
     * It is not meant to be used for creating new records directly.
     *
     * You may override this method if the instance being created
     * depends on the row data to be populated into the record.
     * For example, by creating a record based on the value of a column,
     * you may implement the so-called single-table inheritance mapping.
     * @param array $row row data to be populated into the record.
     * @return static the newly created active record
     */
    public static function instantiate($row)
    {
        return new static;
    }

    /**
     * Returns whether there is an element at the specified offset.
     * This method is required by the interface [[\ArrayAccess]].
     * @param mixed $offset the offset to check on
     * @return boolean whether there is an element at the specified offset.
     */
    public function offsetExists($offset)
    {
        return $this->__isset($offset);
    }

    /**
     * Returns the relation object with the specified name.
     * A relation is defined by a getter method which returns an [[ActiveQueryInterface]] object.
     * It can be declared in either the Active Record class itself or one of its behaviors.
     * @param string $name the relation name
     * @param boolean $throwException whether to throw exception if the relation does not exist.
     * @return ActiveQueryInterface|ActiveQuery the relational query object. If the relation does not exist
     * and `$throwException` is false, null will be returned.
     * @throws InvalidParamException if the named relation does not exist.
     */
    public function getRelation($name, $throwException = true)
    {
        $getter = 'get' . $name;
        try {
            // the relation could be defined in a behavior
            $relation = $this->$getter();
        } catch (UnknownMethodException $e) {
            if ($throwException) {
                throw new InvalidParamException(get_class($this) . ' has no relation named "' . $name . '".', 0, $e);
            } else {
                return null;
            }
        }
        if (!$relation instanceof ActiveQueryInterface) {
            if ($throwException) {
                throw new InvalidParamException(get_class($this) . ' has no relation named "' . $name . '".');
            } else {
                return null;
            }
        }

        if (method_exists($this, $getter)) {
            // relation name is case sensitive, trying to validate it when the relation is defined within this class
            $method = new \ReflectionMethod($this, $getter);
            $realName = lcfirst(substr($method->getName(), 3));
            if ($realName !== $name) {
                if ($throwException) {
                    throw new InvalidParamException('Relation names are case sensitive. ' . get_class($this) . " has a relation named \"$realName\" instead of \"$name\".");
                } else {
                    return null;
                }
            }
        }

        return $relation;
    }

    /**
     * Establishes the relationship between two models.
     *
     * The relationship is established by setting the foreign key value(s) in one model
     * to be the corresponding primary key value(s) in the other model.
     * The model with the foreign key will be saved into database without performing validation.
     *
     * If the relationship involves a junction table, a new row will be inserted into the
     * junction table which contains the primary key values from both models.
     *
     * Note that this method requires that the primary key value is not null.
     *
     * @param string $name the case sensitive name of the relationship.
     * @param ActiveRecordInterface $model the model to be linked with the current one.
     * @param array $extraColumns additional column values to be saved into the junction table.
     * This parameter is only meaningful for a relationship involving a junction table
     * (i.e., a relation set with [[ActiveRelationTrait::via()]] or [[ActiveQuery::viaTable()]].)
     * @throws InvalidCallException if the method is unable to link two models.
     */
    public function link($name, $model, $extraColumns = [])
    {
        $relation = $this->getRelation($name);

        if ($relation->via !== null) {
            if ($this->getIsNewRecord() || $model->getIsNewRecord()) {
                throw new InvalidCallException('Unable to link models: the models being linked cannot be newly created.');
            }
            if (is_array($relation->via)) {
                /* @var $viaRelation ActiveQuery */
                list($viaName, $viaRelation) = $relation->via;
                $viaClass = $viaRelation->modelClass;
                // unset $viaName so that it can be reloaded to reflect the change
                unset($this->_related[$viaName]);
            } else {
                $viaRelation = $relation->via;
                $viaTable = reset($relation->via->from);
            }
            $columns = [];
            foreach ($viaRelation->link as $a => $b) {
                $columns[$a] = $this->$b;
            }
            foreach ($relation->link as $a => $b) {
                $columns[$b] = $model->$a;
            }
            foreach ($extraColumns as $k => $v) {
                $columns[$k] = $v;
            }
            if (is_array($relation->via)) {
                /* @var $viaClass ActiveRecordInterface */
                /* @var $record ActiveRecordInterface */
                $record = new $viaClass();
                foreach ($columns as $column => $value) {
                    $record->$column = $value;
                }
                $record->insert(false);
            } else {
                /* @var $viaTable string */
                static::getDb()->createCommand()
                    ->insert($viaTable, $columns)->execute();
            }
        } else {
            $p1 = $model->isPrimaryKey(array_keys($relation->link));
            $p2 = $this->isPrimaryKey(array_values($relation->link));
            if ($p1 && $p2) {
                if ($this->getIsNewRecord() && $model->getIsNewRecord()) {
                    throw new InvalidCallException('Unable to link models: at most one model can be newly created.');
                } elseif ($this->getIsNewRecord()) {
                    $this->bindModels(array_flip($relation->link), $this, $model);
                } else {
                    $this->bindModels($relation->link, $model, $this);
                }
            } elseif ($p1) {
                $this->bindModels(array_flip($relation->link), $this, $model);
            } elseif ($p2) {
                $this->bindModels($relation->link, $model, $this);
            } else {
                throw new InvalidCallException('Unable to link models: the link defining the relation does not involve any primary key.');
            }
        }

        // update lazily loaded related objects
        if (!$relation->multiple) {
            $this->_related[$name] = $model;
        } elseif (isset($this->_related[$name])) {
            if ($relation->indexBy !== null) {
                $indexBy = $relation->indexBy;
                $this->_related[$name][$model->$indexBy] = $model;
            } else {
                $this->_related[$name][] = $model;
            }
        }
    }

    /**
     * Destroys the relationship between two models.
     *
     * The model with the foreign key of the relationship will be deleted if `$delete` is true.
     * Otherwise, the foreign key will be set null and the model will be saved without validation.
     *
     * @param string $name the case sensitive name of the relationship.
     * @param ActiveRecordInterface $model the model to be unlinked from the current one.
     * You have to make sure that the model is really related with the current model as this method
     * does not check this.
     * @param boolean $delete whether to delete the model that contains the foreign key.
     * If false, the model's foreign key will be set null and saved.
     * If true, the model containing the foreign key will be deleted.
     * @throws InvalidCallException if the models cannot be unlinked
     */
    public function unlink($name, $model, $delete = false)
    {
        $relation = $this->getRelation($name);

        if ($relation->via !== null) {
            if (is_array($relation->via)) {
                /* @var $viaRelation ActiveQuery */
                list($viaName, $viaRelation) = $relation->via;
                $viaClass = $viaRelation->modelClass;
                unset($this->_related[$viaName]);
            } else {
                $viaRelation = $relation->via;
                $viaTable = reset($relation->via->from);
            }
            $columns = [];
            foreach ($viaRelation->link as $a => $b) {
                $columns[$a] = $this->$b;
            }
            foreach ($relation->link as $a => $b) {
                $columns[$b] = $model->$a;
            }
            $nulls = [];
            foreach (array_keys($columns) as $a) {
                $nulls[$a] = null;
            }
            if (is_array($relation->via)) {
                /* @var $viaClass ActiveRecordInterface */
                if ($delete) {
                    $viaClass::deleteAll($columns);
                } else {
                    $viaClass::updateAll($nulls, $columns);
                }
            } else {
                /* @var $viaTable string */
                /* @var $command Command */
                $command = static::getDb()->createCommand();
                if ($delete) {
                    $command->delete($viaTable, $columns)->execute();
                } else {
                    $command->update($viaTable, $nulls, $columns)->execute();
                }
            }
        } else {
            $p1 = $model->isPrimaryKey(array_keys($relation->link));
            $p2 = $this->isPrimaryKey(array_values($relation->link));
            if ($p2) {
                foreach ($relation->link as $a => $b) {
                    $model->$a = null;
                }
                $delete ? $model->delete() : $model->save(false);
            } elseif ($p1) {
                foreach ($relation->link as $a => $b) {
                    if (is_array($this->$b)) { // relation via array valued attribute
                        if (($key = array_search($model->$a, $this->$b, false)) !== false) {
                            $values = $this->$b;
                            unset($values[$key]);
                            $this->$b = array_values($values);
                        }
                    } else {
                        $this->$b = null;
                    }
                }
                $delete ? $this->delete() : $this->save(false);
            } else {
                throw new InvalidCallException('Unable to unlink models: the link does not involve any primary key.');
            }
        }

        if (!$relation->multiple) {
            unset($this->_related[$name]);
        } elseif (isset($this->_related[$name])) {
            /* @var $b ActiveRecordInterface */
            foreach ($this->_related[$name] as $a => $b) {
                if ($model->getPrimaryKey() === $b->getPrimaryKey()) {
                    unset($this->_related[$name][$a]);
                }
            }
        }
    }

    /**
     * Destroys the relationship in current model.
     *
     * The model with the foreign key of the relationship will be deleted if `$delete` is true.
     * Otherwise, the foreign key will be set null and the model will be saved without validation.
     *
     * Note that to destroy the relationship without removing records make sure your keys can be set to null
     *
     * @param string $name the case sensitive name of the relationship.
     * @param boolean $delete whether to delete the model that contains the foreign key.
     */
    public function unlinkAll($name, $delete = false)
    {
        $relation = $this->getRelation($name);

        if ($relation->via !== null) {
            if (is_array($relation->via)) {
                /* @var $viaRelation ActiveQuery */
                list($viaName, $viaRelation) = $relation->via;
                $viaClass = $viaRelation->modelClass;
                unset($this->_related[$viaName]);
            } else {
                $viaRelation = $relation->via;
                $viaTable = reset($relation->via->from);
            }
            $condition = [];
            $nulls = [];
            foreach ($viaRelation->link as $a => $b) {
                $nulls[$a] = null;
                $condition[$a] = $this->$b;
            }
            if (!empty($viaRelation->where)) {
                $condition = ['and', $condition, $viaRelation->where];
            }
            if (is_array($relation->via)) {
                /* @var $viaClass ActiveRecordInterface */
                if ($delete) {
                    $viaClass::deleteAll($condition);
                } else {
                    $viaClass::updateAll($nulls, $condition);
                }
            } else {
                /* @var $viaTable string */
                /* @var $command Command */
                $command = static::getDb()->createCommand();
                if ($delete) {
                    $command->delete($viaTable, $condition)->execute();
                } else {
                    $command->update($viaTable, $nulls, $condition)->execute();
                }
            }
        } else {
            /* @var $relatedModel ActiveRecordInterface */
            $relatedModel = $relation->modelClass;
            if (!$delete && count($relation->link) == 1 && is_array($this->{$b = reset($relation->link)})) {
                // relation via array valued attribute
                $this->$b = [];
                $this->save(false);
            } else {
                $nulls = [];
                $condition = [];
                foreach ($relation->link as $a => $b) {
                    $nulls[$a] = null;
                    $condition[$a] = $this->$b;
                }
                if (!empty($relation->where)) {
                    $condition = ['and', $condition, $relation->where];
                }
                if ($delete) {
                    $relatedModel::deleteAll($condition);
                } else {
                    $relatedModel::updateAll($nulls, $condition);
                }
            }
        }

        unset($this->_related[$name]);
    }

    /**
     * @param array $link
     * @param ActiveRecordInterface $foreignModel
     * @param ActiveRecordInterface $primaryModel
     * @throws InvalidCallException
     */
    private function bindModels($link, $foreignModel, $primaryModel)
    {
        foreach ($link as $fk => $pk) {
            $value = $primaryModel->$pk;
            if ($value === null) {
                throw new InvalidCallException('Unable to link models: the primary key of ' . get_class($primaryModel) . ' is null.');
            }
            if (is_array($foreignModel->$fk)) { // relation via array valued attribute
                $foreignModel->$fk = array_merge($foreignModel->$fk, [$value]);
            } else {
                $foreignModel->$fk = $value;
            }
        }
        $foreignModel->save(false);
    }

    /**
     * Returns a value indicating whether the given set of attributes represents the primary key for this model
     * @param array $keys the set of attributes to check
     * @return boolean whether the given set of attributes represents the primary key for this model
     */
    public static function isPrimaryKey($keys)
    {
        $pks = static::primaryKey();
        if (count($keys) === count($pks)) {
            return count(array_intersect($keys, $pks)) === count($pks);
        } else {
            return false;
        }
    }

    /**
     * Returns the text label for the specified attribute.
     * If the attribute looks like `relatedModel.attribute`, then the attribute will be received from the related model.
     * @param string $attribute the attribute name
     * @return string the attribute label
     * @see generateAttributeLabel()
     * @see attributeLabels()
     */
    public function getAttributeLabel($attribute)
    {
        $labels = $this->attributeLabels();
        if (isset($labels[$attribute])) {
            return ($labels[$attribute]);
        } elseif (strpos($attribute, '.')) {
            $attributeParts = explode('.', $attribute);
            $neededAttribute = array_pop($attributeParts);

            $relatedModel = $this;
            foreach ($attributeParts as $relationName) {
                if ($relatedModel->isRelationPopulated($relationName) && $relatedModel->$relationName instanceof self) {
                    $relatedModel = $relatedModel->$relationName;
                } else {
                    try {
                        $relation = $relatedModel->getRelation($relationName);
                    } catch (InvalidParamException $e) {
                        return $this->generateAttributeLabel($attribute);
                    }
                    $relatedModel = new $relation->modelClass;
                }
            }

            $labels = $relatedModel->attributeLabels();
            if (isset($labels[$neededAttribute])) {
                return $labels[$neededAttribute];
            }
        }

        return $this->generateAttributeLabel($attribute);
    }

    /**
     * Returns the text hint for the specified attribute.
     * If the attribute looks like `relatedModel.attribute`, then the attribute will be received from the related model.
     * @param string $attribute the attribute name
     * @return string the attribute hint
     * @see attributeHints()
     * @since 2.0.4
     */
    public function getAttributeHint($attribute)
    {
        $hints = $this->attributeHints();
        if (isset($hints[$attribute])) {
            return ($hints[$attribute]);
        } elseif (strpos($attribute, '.')) {
            $attributeParts = explode('.', $attribute);
            $neededAttribute = array_pop($attributeParts);

            $relatedModel = $this;
            foreach ($attributeParts as $relationName) {
                if ($relatedModel->isRelationPopulated($relationName) && $relatedModel->$relationName instanceof self) {
                    $relatedModel = $relatedModel->$relationName;
                } else {
                    try {
                        $relation = $relatedModel->getRelation($relationName);
                    } catch (InvalidParamException $e) {
                        return '';
                    }
                    $relatedModel = new $relation->modelClass;
                }
            }

            $hints = $relatedModel->attributeHints();
            if (isset($hints[$neededAttribute])) {
                return $hints[$neededAttribute];
            }
        }
        return '';
    }

    /**
     * @inheritdoc
     *
     * The default implementation returns the names of the columns whose values have been populated into this record.
     */
    public function fields()
    {
        $fields = array_keys($this->_attributes);

        return array_combine($fields, $fields);
    }

    /**
     * @inheritdoc
     *
     * The default implementation returns the names of the relations that have been populated into this record.
     */
    public function extraFields()
    {
        $fields = array_keys($this->getRelatedRecords());

        return array_combine($fields, $fields);
    }

    /**
     * Sets the element value at the specified offset to null.
     * This method is required by the SPL interface [[\ArrayAccess]].
     * It is implicitly called when you use something like `unset($model[$offset])`.
     * @param mixed $offset the offset to unset element
     */
    public function offsetUnset($offset)
    {
        if (property_exists($this, $offset)) {
            $this->$offset = null;
        } else {
            unset($this->$offset);
        }
    }
}
