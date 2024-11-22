<?php

namespace Jenssegers\Mongodb\Eloquent;

use DateTimeInterface;
use Illuminate\Contracts\Queue\QueueableCollection;
use Illuminate\Contracts\Queue\QueueableEntity;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Jenssegers\Mongodb\Query\Builder as QueryBuilder;
use Jenssegers\Mongodb\Relations\Pivot;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;

abstract class Model extends BaseModel
{
    use HybridRelations, EmbedsRelations;

    /**
     * The collection associated with the model.
     * @var string
     */
    protected $collection;

    /**
     * The primary key for the model.
     * @var string
     */
    protected $primaryKey = '_id';

    /**
     * The primary key type.
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The parent relation instance.
     * @var Relation
     */
    protected $parentRelation;

    /**
     * Custom accessor for the model's id.
     * @param mixed $value
     * @return mixed
     */
    public function getIdAttribute($value = null)
    {
        // If we don't have a value for 'id', we will use the Mongo '_id' value.
        // This allows us to work with models in a more sql-like way.
        if (! $value && array_key_exists('_id', $this->attributes)) {
            $value = $this->attributes['_id'];
        }

        // Convert ObjectID to string.
        if ($value instanceof ObjectID) {
            return (string) $value;
        } elseif ($value instanceof Binary) {
            return (string) $value->getData();
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedKeyName()
    {
        return $this->getKeyName();
    }

    /**
     * @inheritdoc
     */
    public function fromDateTime($value)
    {
        // If the value is already a UTCDateTime instance, we don't need to parse it.
        if ($value instanceof UTCDateTime) {
            return $value;
        }

        // Let Eloquent convert the value to a DateTime instance.
        if (! $value instanceof DateTimeInterface) {
            $value = parent::asDateTime($value);
        }

        return new UTCDateTime((int)$value->format('Uv'));
    }

    /**
     * @inheritdoc
     */
    protected function asDateTime($value)
    {
        // Convert UTCDateTime instances.
        if ($value instanceof UTCDateTime) {
            $date = $value->toDateTime();

            $seconds = $date->format('U');
            $milliseconds = abs($date->format('v'));
            $timestampMs = sprintf('%d%03d', $seconds, $milliseconds);

            return Date::createFromTimestampMs($timestampMs);
        }

        return parent::asDateTime($value);
    }

    /**
     * @inheritdoc
     */
    public function getDateFormat()
    {
        return $this->dateFormat ?: 'Y-m-d H:i:s';
    }

    /**
     * @inheritdoc
     */
    public function freshTimestamp()
    {
        return new UTCDateTime((int)Date::now()->format('Uv'));
    }

    /**
     * @inheritdoc
     */
    public function getTable()
    {
        return $this->collection ?: parent::getTable();
    }

    /**
     * @inheritdoc
     */
    public function getAttribute($key)
    {
        if (! $key) {
            return;
        }

        // Dot notation support.
        if (Str::contains($key, '.') && Arr::has($this->attributes, $key)) {
            return $this->getAttributeValue($key);
        }

        // This checks for embedded relation support.
        if (method_exists($this, $key) && ! method_exists(self::class, $key)) {
            return $this->getRelationValue($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * @inheritdoc
     */
    protected function getAttributeFromArray($key)
    {
        // Support keys in dot notation.
        if (Str::contains($key, '.')) {
            return Arr::get($this->attributes, $key);
        }

        return parent::getAttributeFromArray($key);
    }

    /**
     * @inheritdoc
     */
    public function setAttribute($key, $value)
    {
        // Convert _id to ObjectID.
        if ($key == '_id' && is_string($value)) {
            $builder = $this->newBaseQueryBuilder();

            $value = $builder->convertKey($value);
        } // Support keys in dot notation.
        elseif (Str::contains($key, '.')) {
            if (in_array($key, $this->getDates()) && $value) {
                $value = $this->fromDateTime($value);
            }

            Arr::set($this->attributes, $key, $value);

            return;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @inheritdoc
     */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        // Because the original Eloquent never returns objects, we convert
        // MongoDB related objects to a string representation. This kind
        // of mimics the SQL behaviour so that dates are formatted
        // nicely when your models are converted to JSON.
        foreach ($attributes as $key => &$value) {
            if ($value instanceof ObjectID) {
                $value = (string) $value;
            } elseif ($value instanceof Binary) {
                $value = (string) $value->getData();
            }
        }

        // Convert dot-notation dates.
        foreach ($this->getDates() as $key) {
            if (Str::contains($key, '.') && Arr::has($attributes, $key)) {
                Arr::set($attributes, $key, (string) $this->asDateTime(Arr::get($attributes, $key)));
            }
        }

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public function getCasts()
    {
        return $this->casts;
    }

    /**
     * @inheritdoc
     */
    public function originalIsEquivalent($key)
    {
        if (! array_key_exists($key, $this->original)) {
            return false;
        }

        $attribute = Arr::get($this->attributes, $key);
        $original = Arr::get($this->original, $key);

        if ($attribute === $original) {
            return true;
        }

        if (null === $attribute) {
            return false;
        }

        if ($this->isDateAttribute($key)) {
            $attribute = $attribute instanceof UTCDateTime ? $this->asDateTime($attribute) : $attribute;
            $original = $original instanceof UTCDateTime ? $this->asDateTime($original) : $original;

            return $attribute == $original;
        }

        if ($this->hasCast($key, static::$primitiveCastTypes)) {
            return $this->castAttribute($key, $attribute) ===
                $this->castAttribute($key, $original);
        }

        return is_numeric($attribute) && is_numeric($original)
            && strcmp((string) $attribute, (string) $original) === 0;
    }

    /**
     * Remove one or more fields.
     * @param mixed $columns
     * @return int
     */
    public function drop($columns)
    {
        $columns = Arr::wrap($columns);

        // Unset attributes
        foreach ($columns as $column) {
            $this->__unset($column);
        }

        // Perform unset only on current document
        return $this->newQuery()->where($this->getKeyName(), $this->getKey())->unset($columns);
    }

    /**
     * @inheritdoc
     */
    public function push()
    {
        if ($parameters = func_get_args()) {
            $unique = false;

            if (count($parameters) === 3) {
                [$column, $values, $unique] = $parameters;
            } else {
                [$column, $values] = $parameters;
            }

            // Do batch push by default.
            $values = Arr::wrap($values);

            $query = $this->setKeysForSaveQuery($this->newQuery());

            $this->pushAttributeValues($column, $values, $unique);

            return $query->push($column, $values, $unique);
        }

        return parent::push();
    }

    /**
     * Remove one or more values from an array.
     * @param string $column
     * @param mixed $values
     * @return mixed
     */
    public function pull($column, $values)
    {
        // Do batch pull by default.
        $values = Arr::wrap($values);

        $query = $this->setKeysForSaveQuery($this->newQuery());

        $this->pullAttributeValues($column, $values);

        return $query->pull($column, $values);
    }

    /**
     * Append one or more values to the underlying attribute value and sync with original.
     * @param string $column
     * @param array $values
     * @param bool $unique
     */
    protected function pushAttributeValues($column, array $values, $unique = false)
    {
        $current = $this->getAttributeFromArray($column) ?: [];

        foreach ($values as $value) {
            // Don't add duplicate values when we only want unique values.
            if ($unique && (! is_array($current) || in_array($value, $current))) {
                continue;
            }

            $current[] = $value;
        }

        $this->attributes[$column] = $current;

        $this->syncOriginalAttribute($column);
    }

    /**
     * Remove one or more values to the underlying attribute value and sync with original.
     * @param string $column
     * @param array $values
     */
    protected function pullAttributeValues($column, array $values)
    {
        $current = $this->getAttributeFromArray($column) ?: [];

        if (is_array($current)) {
            foreach ($values as $value) {
                $keys = array_keys($current, $value);

                foreach ($keys as $key) {
                    unset($current[$key]);
                }
            }
        }

        $this->attributes[$column] = array_values($current);

        $this->syncOriginalAttribute($column);
    }

    /**
     * @inheritdoc
     */
    public function getForeignKey()
    {
        return Str::snake(class_basename($this)).'_'.ltrim($this->primaryKey, '_');
    }

    /**
     * Set the parent relation.
     * @param \Illuminate\Database\Eloquent\Relations\Relation $relation
     */
    public function setParentRelation(Relation $relation)
    {
        $this->parentRelation = $relation;
    }

    /**
     * Get the parent relation.
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    public function getParentRelation()
    {
        return $this->parentRelation;
    }

    /**
     * @inheritdoc
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * @inheritdoc
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new QueryBuilder($connection, $connection->getPostProcessor());
    }

    /**
     * Create a new pivot model instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  array  $attributes
     * @param  string  $table
     * @param  bool  $exists
     * @param  string|null  $using
     * @return \Illuminate\Database\Eloquent\Relations\Pivot
     */
    public function newPivot(BaseModel $parent, array $attributes, $table, $exists, $using = null)
    {
        return $using ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
            : Pivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /**
     * @inheritdoc
     */
    protected function removeTableFromKey($key)
    {
        return $key;
    }

    /**
     * Get the queueable relationships for the entity.
     * @return array
     */
    public function getQueueableRelations()
    {
        $relations = [];

        foreach ($this->getRelationsWithoutParent() as $key => $relation) {
            if (method_exists($this, $key)) {
                $relations[] = $key;
            }

            if ($relation instanceof QueueableCollection) {
                foreach ($relation->getQueueableRelations() as $collectionValue) {
                    $relations[] = $key.'.'.$collectionValue;
                }
            }

            if ($relation instanceof QueueableEntity) {
                foreach ($relation->getQueueableRelations() as $entityKey => $entityValue) {
                    $relations[] = $key.'.'.$entityValue;
                }
            }
        }

        return array_unique($relations);
    }

    /**
     * Get loaded relations for the instance without parent.
     * @return array
     */
    protected function getRelationsWithoutParent()
    {
        $relations = $this->getRelations();

        if ($parentRelation = $this->getParentRelation()) {
            unset($relations[$parentRelation->getQualifiedForeignKeyName()]);
        }

        return $relations;
    }

    /**
     * Checks if column exists on a table.  As this is a document model, just return true.  This also
     * prevents calls to non-existent function Grammar::compileColumnListing().
     * @param string $key
     * @return bool
     */
    protected function isGuardableColumn($key)
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function __call($method, $parameters)
    {
        // Unset method
        if ($method == 'unset') {
            return call_user_func_array([$this, 'drop'], $parameters);
        }

        return parent::__call($method, $parameters);
    }
}
