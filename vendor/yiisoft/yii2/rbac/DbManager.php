<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\rbac;

use Yii;
use yii\caching\Cache;
use yii\db\Connection;
use yii\db\Query;
use yii\db\Expression;
use yii\base\InvalidCallException;
use yii\base\InvalidParamException;
use yii\di\Instance;

/**
 * DbManager represents an authorization manager that stores authorization information in database.
 *
 * The database connection is specified by [[db]]. The database schema could be initialized by applying migration:
 *
 * ```
 * yii migrate --migrationPath=@yii/rbac/migrations/
 * ```
 *
 * If you don't want to use migration and need SQL instead, files for all databases are in migrations directory.
 *
 * You may change the names of the tables used to store the authorization and rule data by setting [[itemTable]],
 * [[itemChildTable]], [[assignmentTable]] and [[ruleTable]].
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Alexander Kochetov <creocoder@gmail.com>
 * @since 2.0
 */
class DbManager extends BaseManager
{
    /**
     * @var Connection|array|string the DB connection object or the application component ID of the DB connection.
     * After the DbManager object is created, if you want to change this property, you should only assign it
     * with a DB connection object.
     * Starting from version 2.0.2, this can also be a configuration array for creating the object.
     * Connection实例或数组或字符串。数据库链接对象，或数据库链接的应用组件ID，在本对象穿件后，假如你想改变本属性的值，
     * 只能为其分配一个数据库链接对象。在2.0.2版本之后，也可以接受一个用于创建对象的配置数组。
     */
    public $db = 'db';
    /**
     * @var string the name of the table storing authorization items. Defaults to "auth_item".
     * 字符串，用于存放认证项目的表名，默认为"auth_item"
     */
    public $itemTable = '{{%auth_item}}';
    /**
     * @var string the name of the table storing authorization item hierarchy. Defaults to "auth_item_child".
     * 字符串，用于存放认证项目层次结构的表名，默认为"auth_item_child"
     */
    public $itemChildTable = '{{%auth_item_child}}';
    /**
     * @var string the name of the table storing authorization item assignments. Defaults to "auth_assignment".
     * 字符串，用于存放认证项目分配关系的表名，默认为"auth_assignment"
     */
    public $assignmentTable = '{{%auth_assignment}}';
    /**
     * @var string the name of the table storing rules. Defaults to "auth_rule".
     * 字符串，用于存放规则的表名。默认为"auth_rule"
     */
    public $ruleTable = '{{%auth_rule}}';
    /**
     * @var Cache|array|string the cache used to improve RBAC performance. This can be one of the following:
     * Cache实例，数组或字符串，本属性（缓存）用于改善RBAC的性能。本属性值可以是以下之一：
     *
     * - an application component ID (e.g. `cache`)
     * - 一个用户组件ID
     * - a configuration array
     * - 配置数组
     * - a [[\yii\caching\Cache]] object
     * - [[\yii\caching\Cache]]类的实例
     *
     * When this is not set, it means caching is not enabled.
     * 假如未设定本属性，则表示未开启缓存
     *
     * Note that by enabling RBAC cache, all auth items, rules and auth item parent-child relationships will
     * be cached and loaded into memory. This will improve the performance of RBAC permission check. However,
     * it does require extra memory and as a result may not be appropriate if your RBAC system contains too many
     * auth items. You should seek other RBAC implementations (e.g. RBAC based on Redis storage) in this case.
     * 注意：开启RBAC缓存后，所有的认证项目、规则，和认证项目的父子关系都会被缓存和载入到内存中。此举会改善RBAC验证的
     * 性能。然而，这需要额外的内存，并且当你的RBAC系统包含很多认证项目时会不适宜，假如这样的话，你需要寻找其他的（必须
     * 基于Redis储存的）RBAC插件。
     *
     * Also note that if you modify RBAC items, rules or parent-child relationships from outside of this component,
     * you have to manually call [[invalidateCache()]] to ensure data consistency.
     * 还请注意：假如你在本应用组件之外更改了RBAC系统的认证项目，规则，或者认证项目的父子关系。则需要调用[[invalidateCache()]]
     * 方法确保数据的一致性。
     *
     * @since 2.0.3
     */
    public $cache;
    /**
     * @var string the key used to store RBAC data in cache
     * 在缓存中保存RBAC数据的键
     * @see cache
     * @since 2.0.3
     */
    public $cacheKey = 'rbac';

    /**
     * @var Item[] all auth items (name => Item)
     * 所有的认证项目组建成的键值对集合
     */
    protected $items;
    /**
     * @var Rule[] all auth rules (name => Rule)
     * 所有的授权规则组成的键值对集合
     */
    protected $rules;
    /**
     * @var array auth item parent-child relationships (childName => list of parents)
     * 认证项目的父子关系组成的集合 (childName => list of parents)
     */
    protected $parents;


    /**
     * Initializes the application component.
     * This method overrides the parent implementation by establishing the database connection.
     * 初始化本应用组件
     */
    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::className());
        if ($this->cache !== null) {
            $this->cache = Instance::ensure($this->cache, Cache::className());
        }
    }

    /**
     * @inheritdoc
     */
    public function checkAccess($userId, $permissionName, $params = [])
    {
        $assignments = $this->getAssignments($userId);
        $this->loadFromCache();
        if ($this->items !== null) {
            return $this->checkAccessFromCache($userId, $permissionName, $params, $assignments);
        } else {
            return $this->checkAccessRecursive($userId, $permissionName, $params, $assignments);
        }
    }

    /**
     * Performs access check for the specified user based on the data loaded from cache.
     * This method is internally called by [[checkAccess()]] when [[cache]] is enabled.
     * 从缓存载入权限数据，以执行权限验证。本方法在缓存开启时，由 [[checkAccess()]] 方法内部调用
     * @param string|integer $user the user ID. This should can be either an integer or a string representing
     * the unique identifier of a user. See [[\yii\web\User::id]].
     * @param string $itemName the name of the operation that need access check
     * @param array $params name-value pairs that would be passed to rules associated
     * with the tasks and roles assigned to the user. A param with name 'user' is added to this array,
     * which holds the value of `$userId`.
     * @param Assignment[] $assignments the assignments to the specified user
     * @return boolean whether the operations can be performed by the user.
     * @since 2.0.3
     */
    protected function checkAccessFromCache($user, $itemName, $params, $assignments)
    {
        if (!isset($this->items[$itemName])) {
            return false;
        }

        $item = $this->items[$itemName];

        Yii::trace($item instanceof Role ? "Checking role: $itemName" : "Checking permission: $itemName", __METHOD__);

        if (!$this->executeRule($user, $item, $params)) {
            return false;
        }

        if (isset($assignments[$itemName]) || in_array($itemName, $this->defaultRoles)) {
            return true;
        }

        if (!empty($this->parents[$itemName])) {
            foreach ($this->parents[$itemName] as $parent) {
                if ($this->checkAccessFromCache($user, $parent, $params, $assignments)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Performs access check for the specified user.
     * This method is internally called by [[checkAccess()]].
     * 为指定的用户执行权限认证。本方法由[[checkAccess()]]方法内部调用。
     * @param string|integer $user the user ID. This should can be either an integer or a string representing
     * the unique identifier of a user. See [[\yii\web\User::id]].
     * @param string $itemName the name of the operation that need access check
     * @param array $params name-value pairs that would be passed to rules associated
     * with the tasks and roles assigned to the user. A param with name 'user' is added to this array,
     * which holds the value of `$userId`.
     * @param Assignment[] $assignments the assignments to the specified user
     * @return boolean whether the operations can be performed by the user.
     */
    protected function checkAccessRecursive($user, $itemName, $params, $assignments)
    {
        if (($item = $this->getItem($itemName)) === null) {
            return false;
        }

        Yii::trace($item instanceof Role ? "Checking role: $itemName" : "Checking permission: $itemName", __METHOD__);

        if (!$this->executeRule($user, $item, $params)) {
            return false;
        }

        if (isset($assignments[$itemName]) || in_array($itemName, $this->defaultRoles)) {
            return true;
        }

        $query = new Query;
        $parents = $query->select(['parent'])
            ->from($this->itemChildTable)
            ->where(['child' => $itemName])
            ->column($this->db);
        foreach ($parents as $parent) {
            if ($this->checkAccessRecursive($user, $parent, $params, $assignments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    protected function getItem($name)
    {
        if (empty($name)) {
            return null;
        }

        if (!empty($this->items[$name])) {
            return $this->items[$name];
        }

        $row = (new Query)->from($this->itemTable)
            ->where(['name' => $name])
            ->one($this->db);

        if ($row === false) {
            return null;
        }

        if (!isset($row['data']) || ($data = @unserialize($row['data'])) === false) {
            $row['data'] = null;
        }

        return $this->populateItem($row);
    }

    /**
     * Returns a value indicating whether the database supports cascading update and delete.
     * The default implementation will return false for SQLite database and true for all other databases.
     * 判断数据库是否支持级联更新和级联删除。默认实现逻辑是，除了SQLite数据库外，其他数据库都支持
     * @return boolean whether the database supports cascading update and delete.
     */
    protected function supportsCascadeUpdate()
    {
        return strncmp($this->db->getDriverName(), 'sqlite', 6) !== 0;
    }

    /**
     * @inheritdoc
     */
    protected function addItem($item)
    {
        $time = time();
        if ($item->createdAt === null) {
            $item->createdAt = $time;
        }
        if ($item->updatedAt === null) {
            $item->updatedAt = $time;
        }
        $this->db->createCommand()
            ->insert($this->itemTable, [
                'name' => $item->name,
                'type' => $item->type,
                'description' => $item->description,
                'rule_name' => $item->ruleName,
                'data' => $item->data === null ? null : serialize($item->data),
                'created_at' => $item->createdAt,
                'updated_at' => $item->updatedAt,
            ])->execute();

        $this->invalidateCache();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function removeItem($item)
    {
        if (!$this->supportsCascadeUpdate()) {
            $this->db->createCommand()
                ->delete($this->itemChildTable, ['or', '[[parent]]=:name', '[[child]]=:name'], [':name' => $item->name])
                ->execute();
            $this->db->createCommand()
                ->delete($this->assignmentTable, ['item_name' => $item->name])
                ->execute();
        }

        $this->db->createCommand()
            ->delete($this->itemTable, ['name' => $item->name])
            ->execute();

        $this->invalidateCache();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function updateItem($name, $item)
    {
        if ($item->name !== $name && !$this->supportsCascadeUpdate()) {
            $this->db->createCommand()
                ->update($this->itemChildTable, ['parent' => $item->name], ['parent' => $name])
                ->execute();
            $this->db->createCommand()
                ->update($this->itemChildTable, ['child' => $item->name], ['child' => $name])
                ->execute();
            $this->db->createCommand()
                ->update($this->assignmentTable, ['item_name' => $item->name], ['item_name' => $name])
                ->execute();
        }

        $item->updatedAt = time();

        $this->db->createCommand()
            ->update($this->itemTable, [
                'name' => $item->name,
                'description' => $item->description,
                'rule_name' => $item->ruleName,
                'data' => $item->data === null ? null : serialize($item->data),
                'updated_at' => $item->updatedAt,
            ], [
                'name' => $name,
            ])->execute();

        $this->invalidateCache();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function addRule($rule)
    {
        $time = time();
        if ($rule->createdAt === null) {
            $rule->createdAt = $time;
        }
        if ($rule->updatedAt === null) {
            $rule->updatedAt = $time;
        }
        $this->db->createCommand()
            ->insert($this->ruleTable, [
                'name' => $rule->name,
                'data' => serialize($rule),
                'created_at' => $rule->createdAt,
                'updated_at' => $rule->updatedAt,
            ])->execute();

        $this->invalidateCache();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function updateRule($name, $rule)
    {
        if ($rule->name !== $name && !$this->supportsCascadeUpdate()) {
            $this->db->createCommand()
                ->update($this->itemTable, ['rule_name' => $rule->name], ['rule_name' => $name])
                ->execute();
        }

        $rule->updatedAt = time();

        $this->db->createCommand()
            ->update($this->ruleTable, [
                'name' => $rule->name,
                'data' => serialize($rule),
                'updated_at' => $rule->updatedAt,
            ], [
                'name' => $name,
            ])->execute();

        $this->invalidateCache();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function removeRule($rule)
    {
        if (!$this->supportsCascadeUpdate()) {
            $this->db->createCommand()
                ->update($this->itemTable, ['rule_name' => null], ['rule_name' => $rule->name])
                ->execute();
        }

        $this->db->createCommand()
            ->delete($this->ruleTable, ['name' => $rule->name])
            ->execute();

        $this->invalidateCache();

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function getItems($type)
    {
        $query = (new Query)
            ->from($this->itemTable)
            ->where(['type' => $type]);

        $items = [];
        foreach ($query->all($this->db) as $row) {
            $items[$row['name']] = $this->populateItem($row);
        }

        return $items;
    }

    /**
     * Populates an auth item with the data fetched from database
     * 以数据库中取出的数据填充一个认证项目
     * @param array $row the data from the auth item table
     * @return Item the populated auth item instance (either Role or Permission)
     */
    protected function populateItem($row)
    {
        $class = $row['type'] == Item::TYPE_PERMISSION ? Permission::className() : Role::className();

        if (!isset($row['data']) || ($data = @unserialize($row['data'])) === false) {
            $data = null;
        }

        return new $class([
            'name' => $row['name'],
            'type' => $row['type'],
            'description' => $row['description'],
            'ruleName' => $row['rule_name'],
            'data' => $data,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getRolesByUser($userId)
    {
        if (empty($userId)) {
            return [];
        }

        $query = (new Query)->select('b.*')
            ->from(['a' => $this->assignmentTable, 'b' => $this->itemTable])
            ->where('{{a}}.[[item_name]]={{b}}.[[name]]')
            ->andWhere(['a.user_id' => (string) $userId])
            ->andWhere(['b.type' => Item::TYPE_ROLE]);

        $roles = [];
        foreach ($query->all($this->db) as $row) {
            $roles[$row['name']] = $this->populateItem($row);
        }
        return $roles;
    }

    /**
     * @inheritdoc
     */
    public function getPermissionsByRole($roleName)
    {
        $childrenList = $this->getChildrenList();
        $result = [];
        $this->getChildrenRecursive($roleName, $childrenList, $result);
        if (empty($result)) {
            return [];
        }
        $query = (new Query)->from($this->itemTable)->where([
            'type' => Item::TYPE_PERMISSION,
            'name' => array_keys($result),
        ]);
        $permissions = [];
        foreach ($query->all($this->db) as $row) {
            $permissions[$row['name']] = $this->populateItem($row);
        }
        return $permissions;
    }

    /**
     * @inheritdoc
     */
    public function getPermissionsByUser($userId)
    {
        if (empty($userId)) {
            return [];
        }

        $query = (new Query)->select('item_name')
            ->from($this->assignmentTable)
            ->where(['user_id' => (string) $userId]);

        $childrenList = $this->getChildrenList();
        $result = [];
        foreach ($query->column($this->db) as $roleName) {
            $this->getChildrenRecursive($roleName, $childrenList, $result);
        }

        if (empty($result)) {
            return [];
        }

        $query = (new Query)->from($this->itemTable)->where([
            'type' => Item::TYPE_PERMISSION,
            'name' => array_keys($result),
        ]);
        $permissions = [];
        foreach ($query->all($this->db) as $row) {
            $permissions[$row['name']] = $this->populateItem($row);
        }
        return $permissions;
    }

    /**
     * Returns the children for every parent.
     * 返回多个子认证项目的所有父认证项目
     * @return array the children list. Each array key is a parent item name,
     * and the corresponding array value is a list of child item names.
     */
    protected function getChildrenList()
    {
        $query = (new Query)->from($this->itemChildTable);
        $parents = [];
        foreach ($query->all($this->db) as $row) {
            $parents[$row['parent']][] = $row['child'];
        }
        return $parents;
    }

    /**
     * Recursively finds all children and grand children of the specified item.
     * 为指定的认证项目递归地找出所有子孙认证项目
     * @param string $name the name of the item whose children are to be looked for.
     * @param array $childrenList the child list built via [[getChildrenList()]]
     * @param array $result the children and grand children (in array keys)
     */
    protected function getChildrenRecursive($name, $childrenList, &$result)
    {
        if (isset($childrenList[$name])) {
            foreach ($childrenList[$name] as $child) {
                $result[$child] = true;
                $this->getChildrenRecursive($child, $childrenList, $result);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getRule($name)
    {
        if ($this->rules !== null) {
            return isset($this->rules[$name]) ? $this->rules[$name] : null;
        }

        $row = (new Query)->select(['data'])
            ->from($this->ruleTable)
            ->where(['name' => $name])
            ->one($this->db);
        return $row === false ? null : unserialize($row['data']);
    }

    /**
     * @inheritdoc
     */
    public function getRules()
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $query = (new Query)->from($this->ruleTable);

        $rules = [];
        foreach ($query->all($this->db) as $row) {
            $rules[$row['name']] = unserialize($row['data']);
        }

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getAssignment($roleName, $userId)
    {
        if (empty($userId)) {
            return null;
        }

        $row = (new Query)->from($this->assignmentTable)
            ->where(['user_id' => (string) $userId, 'item_name' => $roleName])
            ->one($this->db);

        if ($row === false) {
            return null;
        }

        return new Assignment([
            'userId' => $row['user_id'],
            'roleName' => $row['item_name'],
            'createdAt' => $row['created_at'],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getAssignments($userId)
    {
        if (empty($userId)) {
            return [];
        }

        $query = (new Query)
            ->from($this->assignmentTable)
            ->where(['user_id' => (string) $userId]);

        $assignments = [];
        foreach ($query->all($this->db) as $row) {
            $assignments[$row['item_name']] = new Assignment([
                'userId' => $row['user_id'],
                'roleName' => $row['item_name'],
                'createdAt' => $row['created_at'],
            ]);
        }

        return $assignments;
    }

    /**
     * @inheritdoc
     */
    public function addChild($parent, $child)
    {
        if ($parent->name === $child->name) {
            throw new InvalidParamException("Cannot add '{$parent->name}' as a child of itself.");
        }

        if ($parent instanceof Permission && $child instanceof Role) {
            throw new InvalidParamException("Cannot add a role as a child of a permission.");
        }

        if ($this->detectLoop($parent, $child)) {
            throw new InvalidCallException("Cannot add '{$child->name}' as a child of '{$parent->name}'. A loop has been detected.");
        }

        $this->db->createCommand()
            ->insert($this->itemChildTable, ['parent' => $parent->name, 'child' => $child->name])
            ->execute();

        $this->invalidateCache();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function removeChild($parent, $child)
    {
        $result = $this->db->createCommand()
            ->delete($this->itemChildTable, ['parent' => $parent->name, 'child' => $child->name])
            ->execute() > 0;

        $this->invalidateCache();

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function removeChildren($parent)
    {
        $result = $this->db->createCommand()
            ->delete($this->itemChildTable, ['parent' => $parent->name])
            ->execute() > 0;

        $this->invalidateCache();

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function hasChild($parent, $child)
    {
        return (new Query)
            ->from($this->itemChildTable)
            ->where(['parent' => $parent->name, 'child' => $child->name])
            ->one($this->db) !== false;
    }

    /**
     * @inheritdoc
     */
    public function getChildren($name)
    {
        $query = (new Query)
            ->select(['name', 'type', 'description', 'rule_name', 'data', 'created_at', 'updated_at'])
            ->from([$this->itemTable, $this->itemChildTable])
            ->where(['parent' => $name, 'name' => new Expression('[[child]]')]);

        $children = [];
        foreach ($query->all($this->db) as $row) {
            $children[$row['name']] = $this->populateItem($row);
        }

        return $children;
    }

    /**
     * Checks whether there is a loop in the authorization item hierarchy.
     * 检查给定的授权项目层次结构是否是循环的
     * @param Item $parent the parent item
     * @param Item $child the child item to be added to the hierarchy
     * @return boolean whether a loop exists
     */
    protected function detectLoop($parent, $child)
    {
        if ($child->name === $parent->name) {
            return true;
        }
        foreach ($this->getChildren($child->name) as $grandchild) {
            if ($this->detectLoop($parent, $grandchild)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function assign($role, $userId)
    {
        $assignment = new Assignment([
            'userId' => $userId,
            'roleName' => $role->name,
            'createdAt' => time(),
        ]);

        $this->db->createCommand()
            ->insert($this->assignmentTable, [
                'user_id' => $assignment->userId,
                'item_name' => $assignment->roleName,
                'created_at' => $assignment->createdAt,
            ])->execute();

        return $assignment;
    }

    /**
     * @inheritdoc
     */
    public function revoke($role, $userId)
    {
        if (empty($userId)) {
            return false;
        }

        return $this->db->createCommand()
            ->delete($this->assignmentTable, ['user_id' => (string) $userId, 'item_name' => $role->name])
            ->execute() > 0;
    }

    /**
     * @inheritdoc
     */
    public function revokeAll($userId)
    {
        if (empty($userId)) {
            return false;
        }

        return $this->db->createCommand()
            ->delete($this->assignmentTable, ['user_id' => (string) $userId])
            ->execute() > 0;
    }

    /**
     * @inheritdoc
     */
    public function removeAll()
    {
        $this->removeAllAssignments();
        $this->db->createCommand()->delete($this->itemChildTable)->execute();
        $this->db->createCommand()->delete($this->itemTable)->execute();
        $this->db->createCommand()->delete($this->ruleTable)->execute();
        $this->invalidateCache();
    }

    /**
     * @inheritdoc
     */
    public function removeAllPermissions()
    {
        $this->removeAllItems(Item::TYPE_PERMISSION);
    }

    /**
     * @inheritdoc
     */
    public function removeAllRoles()
    {
        $this->removeAllItems(Item::TYPE_ROLE);
    }

    /**
     * Removes all auth items of the specified type.
     * @param integer $type the auth item type (either Item::TYPE_PERMISSION or Item::TYPE_ROLE)
     */
    protected function removeAllItems($type)
    {
        if (!$this->supportsCascadeUpdate()) {
            $names = (new Query)
                ->select(['name'])
                ->from($this->itemTable)
                ->where(['type' => $type])
                ->column($this->db);
            if (empty($names)) {
                return;
            }
            $key = $type == Item::TYPE_PERMISSION ? 'child' : 'parent';
            $this->db->createCommand()
                ->delete($this->itemChildTable, [$key => $names])
                ->execute();
            $this->db->createCommand()
                ->delete($this->assignmentTable, ['item_name' => $names])
                ->execute();
        }
        $this->db->createCommand()
            ->delete($this->itemTable, ['type' => $type])
            ->execute();

        $this->invalidateCache();
    }

    /**
     * @inheritdoc
     */
    public function removeAllRules()
    {
        if (!$this->supportsCascadeUpdate()) {
            $this->db->createCommand()
                ->update($this->itemTable, ['ruleName' => null])
                ->execute();
        }

        $this->db->createCommand()->delete($this->ruleTable)->execute();

        $this->invalidateCache();
    }

    /**
     * @inheritdoc
     */
    public function removeAllAssignments()
    {
        $this->db->createCommand()->delete($this->assignmentTable)->execute();
    }

    /**
     * 清空缓存，保证数据一致性
     */
    public function invalidateCache()
    {
        if ($this->cache !== null) {
            $this->cache->delete($this->cacheKey);
            $this->items = null;
            $this->rules = null;
            $this->parents = null;
        }
    }

    /**
     * 从缓存加载授权信息，包括认证项目集合、
     * 授权规则集合、认证项目的父子关系集合
     */
    public function loadFromCache()
    {
        if ($this->items !== null || !$this->cache instanceof Cache) {
            return;
        }

        $data = $this->cache->get($this->cacheKey);
        if (is_array($data) && isset($data[0], $data[1], $data[2])) {
            list ($this->items, $this->rules, $this->parents) = $data;
            return;
        }

        $query = (new Query)->from($this->itemTable);
        $this->items = [];
        foreach ($query->all($this->db) as $row) {
            $this->items[$row['name']] = $this->populateItem($row);
        }

        $query = (new Query)->from($this->ruleTable);
        $this->rules = [];
        foreach ($query->all($this->db) as $row) {
            $this->rules[$row['name']] = unserialize($row['data']);
        }

        $query = (new Query)->from($this->itemChildTable);
        $this->parents = [];
        foreach ($query->all($this->db) as $row) {
            if (isset($this->items[$row['child']])) {
                $this->parents[$row['child']][] = $row['parent'];
            }
        }

        $this->cache->set($this->cacheKey, [$this->items, $this->rules, $this->parents]);
    }
}
