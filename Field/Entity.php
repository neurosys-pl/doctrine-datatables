<?php
namespace NeuroSYS\DoctrineDatatables\Field;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use NeuroSYS\DoctrineDatatables\Table;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class Entity extends AbstractField
{

    /**
     * @var string Field name
     */
    protected $name;

    /**
     * @var string Field alias
     */
    protected $alias;

    /**
     * Alias index used to generate alias for a field
     * @var int
     */
    private static $aliasIndex = 1;

    /**
     * @var AbstractField[]
     */
    protected $fields = array();

    /**
     * @var Entity[]
     */
    protected $relations = array();

    /**
     * @var string Join type
     */
    protected $joinType;

    /**
     * DQL join condition type
     *
     * @var string|null
     */
    protected $joinConditionType;

    /**
     * DQL join condition
     *
     * @var string|null
     */
    protected $joinCondition;

    public static function generateAlias($name)
    {
        if (!$name) {
            $name = 'x';
        }
        $name = preg_replace('/[^A-Z]/i', '', $name);

        return $name[0] . (self::$aliasIndex++);
    }
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    public function setAlias($alias)
    {
        if (!$alias) {
            $alias = $this->generateAlias($this->getName() ?: 'x');
        }
        $this->alias = $alias;

        return $this;
    }

    /**
     * @param string $joinType
     */
    public function setJoinType($joinType)
    {
        $this->joinType = $joinType;

        return $this;
    }

    /**
     * @return string $joinType
     */
    public function getJoinType()
    {
        return $this->joinType;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @param array $options
     */
    public function setOptions($options)
    {
        $this->options = $options;
    }

    /**
     * @return null|string
     */
    public function getJoinCondition()
    {
        return $this->joinCondition;
    }

    /**
     * @param null|string $joinCondition
     */
    public function setJoinCondition($joinCondition)
    {
        $this->joinCondition = $joinCondition;
    }

    /**
     * @return null|string
     */
    public function getJoinConditionType()
    {
        return $this->joinConditionType;
    }

    /**
     * @param null|string $joinConditionType
     */
    public function setJoinConditionType($joinConditionType)
    {
        $this->joinConditionType = $joinConditionType;
    }

    /**
     * @return array Field path
     */
    public function getPath()
    {
        $path = array();
        if ($this->getParent()) {
            $path = $this->getParent()->getPath();
        }
        $path[] = $this->getName();

        return $path;
    }

    /**
     * @param Table  $table
     * @param array  $name
     * @param string $alias
     * @param array  $options
     *
     * @throws \Exception
     */
    public function __construct(Table $table, $name, $alias, array $options = array())
    {
        if (empty($name) || empty($alias)) {
            throw new \Exception("Name and alias must not be empty");
        }

        parent::__construct($table, $options);

        $this->setName($name);
        $this->setAlias($alias);

        $table->addEntity($this);
    }

    /**
     * Gets this field alias
     *
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * Gets this field name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param  AbstractField $field
     * @return $this
     */
    public function setField($name, AbstractField $field)
    {
        $this->fields[$name] = $field;

        return $this;
    }

    public function getFields()
    {
        return $this->fields;
    }

    /**
     * Gets full name containing entity alias and field name
     *
     * @return string
     */
    public function getFullName()
    {
        return ($this->getParent() ? $this->getParent()->getAlias() . '.' : '') . $this->getName();
    }

    public function getField($index)
    {
        return $this->fields[$index];
    }

    /**
     * @param  QueryBuilder $qb
     * @return self
     */
    public function select(QueryBuilder $qb)
    {
        $qb->addSelect($this->getAlias());
    }

    public function join($name, $alias, $type = 'LEFT', $conditionType = null, $condition = null)
    {
        if (!isset($this->relations[$name])) {
            $child = $this->getTable()->getEntity($alias);
            if (!$child) {
                $child = new self($this->getTable(), $name, $alias);
                $child->setParent($this);
            }
            $child->setJoinType($type);
            $child->setJoinConditionType($conditionType);
            $child->setJoinCondition($condition);

            return $this->relations[$name] = $child;
        }

        return $this->relations[$name];
    }

    public function getClassName()
    {
        if ($this->getParent()) {
            $class = $this->getParent()->getClassName();

            return $this->getTable()->getManager()->getClassMetadata($class)->getAssociationTargetClass($this->getName());
        }

        return $this->getTable()->getManager()->getClassMetadata($this->getName())->getName();
    }

    /**
     * @return array Entity primary keys
     */
    public function getPrimaryKeys()
    {
        return $this->getTable()->getManager()->getMetadataFactory()->getMetadataFor($this->getClassName())->getIdentifier();
    }

    public function isJoined(QueryBuilder $qb)
    {
        /**
         * @var Join[] $join
         */
        $joins = $qb->getDQLPart('join');
        foreach ($joins as $join) {
            foreach ($join as $j) {
                if ($j->getAlias() == $this->getAlias()) { // already joined

                    return true;
                }
            }
        }

        return false;
    }

    public function format($values, $value = null)
    {
        $result = array();
        $accessor = new PropertyAccessor();

        foreach ($this->getFields() as $name => $field) {
            $accessPath = $field->getPath(0);
            if (is_array($value)) {
                $accessPath = '['.$accessPath.']';
            }

            return $result[$name] = $field->format($value, $accessor->getValue($value, $accessPath));
        }

        return $result;
    }

    public function getSelect()
    {
        $select = array();
        foreach ($this->getFields() as $field) {
            $select = array_merge_recursive($select, $field->getSelect());
        }

        return $select;
    }

    /**
     * @param QueryBuilder $qb
     * @param bool|false $global decide is filter or global search
     * @return Expr\Orx
     */
    public function filter(QueryBuilder $qb, $global = false)
    {
        $orx = $qb->expr()->orX();
        foreach ($this->getFields() as $field) {
            if ($field->isSearch($global)) {
                $orx->add($field->filter($qb, $global));
            }
        }
        if ($orx->count() > 0) {
            $qb->andWhere($orx);
        }

        return $orx;
    }
}
