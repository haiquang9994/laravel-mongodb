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

        $this->query
            ->addPipelineStage('lookup', [
                'from' => $this->table,
                'let' => ['pid' => '$_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$eq' => [
                                    ['$toObjectId' => '$' . $relatedPivotKey], '$$pid'
                                ]
                            ],
                            "$foreignPivotKey" => $this->parent->getKey(),
                        ],
                    ]
                ],
                'as' => 'pivot',
            ])
            ->addPipelineStage('unwind', '$pivot');
    }

    /**
     * Execute the query as a "select" statement.
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get($columns = ['*'])
    {
        $relatedPivotKey = $this->getQualifiedRelatedPivotKeyName();
        $foreignPivotKey = $this->getQualifiedForeignPivotKeyName();

        $addFields = [
            "pivot_$relatedPivotKey" => '$pivot.' . $relatedPivotKey,
            "pivot_$foreignPivotKey" => '$pivot.' . $foreignPivotKey,
        ];

        if ($this->pivotColumns) {
            foreach ($this->pivotColumns as $column) {
                $addFields["pivot_$column"] = '$pivot.' . $column;
            }
        }

        $this->query
            ->addPipelineStage('addFields', $addFields)
            ->addPipelineStage('unset', 'pivot');

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

    /**
     * Qualify the given column name by the pivot table.
     *
     * @param string $column
     * @return string
     */
    public function qualifyPivotColumn($column)
    {
        return 'pivot.' . $column;
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $relatedPivotKey = $this->getQualifiedRelatedPivotKeyName();
        $foreignPivotKey = $this->getQualifiedForeignPivotKeyName();
        $keys = $this->getKeys($models, $this->parentKey);

        $this->query
            ->addPipelineStage('lookup', [
                'from' => $this->table,
                'let' => ['pid' => '$_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$eq' => [
                                    ['$toObjectId' => '$' . $relatedPivotKey], '$$pid'
                                ]
                            ],
                            "$foreignPivotKey" => ['$in' => $keys],
                        ],
                    ]
                ],
                'as' => 'pivot',
            ])
            ->addPipelineStage('unwind', '$pivot');
    }
}
