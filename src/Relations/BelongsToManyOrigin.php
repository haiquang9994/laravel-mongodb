<?php

namespace Jenssegers\Mongodb\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;

class BelongsToManyOrigin extends EloquentBelongsToMany
{
    /**
     * Set the select clause for the relation query.
     * @param array $columns
     * @return array
     */
    protected function getSelectColumns(array $columns = ['*'])
    {
        return $columns;
    }

    /**
     * @inheritdoc
     */
    protected function shouldSelect(array $columns = ['*'])
    {
        return $columns;
    }

    /**
     * @inheritdoc
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->setWhere();
        }
    }

    /**
     * Set the where clause for the relation query.
     * @return $this
     */
    protected function setWhere()
    {
        $relatedPivotKey = $this->getQualifiedRelatedPivotKeyName();
        $foreignPivotKey = $this->getQualifiedForeignPivotKeyName();

        $this->query->addPipelineStage([
            '$addFields' => [
                'id' => [
                    '$toString' => '$_id',
                ],
            ],
        ])->addPipelineStage([
            '$lookup' => [
                'from' => $this->table,
                'localField' => 'id',
                'foreignField' => $relatedPivotKey,
                'as' => 'pivot',
            ],
        ])->addPipelineStage([
            '$unwind' => [
                'path' => '$pivot',
                'preserveNullAndEmptyArrays' => true,
            ],
        ])->addPipelineStage([
            '$addFields' => [
                "pivot_$relatedPivotKey" => '$pivot.' . $relatedPivotKey,
                "pivot_$foreignPivotKey" => '$pivot.' . $foreignPivotKey,
            ],
        ])->addPipelineStage([
            '$match' => [
                "pivot.$foreignPivotKey" => $this->parent->getKey(),
            ],
        ])->addPipelineStage([
            '$unset' => 'id',
        ]);
    }

    public function get($columns = ['*'])
    {
        if ($this->pivotColumns) {
            $addFields = [];
            foreach ($this->pivotColumns as $column) {
                $addFields["pivot_$column"] = '$pivot.' . $column;
            }

            $this->query->addPipelineStage([
                '$addFields' => $addFields,
            ]);
        }
        $this->query->addPipelineStage([
            '$unset' => 'pivot',
        ]);

        return parent::get($columns);
    }

    /**
     * Get the fully qualified foreign key for the relation.
     * @return string
     */
    public function getForeignKey()
    {
        return $this->foreignPivotKey;
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedForeignPivotKeyName()
    {
        return $this->foreignPivotKey;
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedRelatedPivotKeyName()
    {
        return $this->relatedPivotKey;
    }
}
