<?php

namespace Stylemix\Listing\Attribute;

use Elastica\Query\AbstractQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use Stylemix\Listing\Contracts\Aggregateble;
use Stylemix\Listing\Contracts\Filterable;
use Stylemix\Listing\Entity;
use Stylemix\Listing\Facades\Elastic;
use Stylemix\Listing\Facades\Entities;

/**
 * @property string  $related         Related entity
 * @property array   $mapProperties   Properties of related entity to use in mapping
 * @property integer $aggregationSize Limit size of returned aggregation terms
 * @property boolean $updateOnChange  Trigger reindexing on related model change
 * @method $this mapProperties(array $map) List of mapped properties
 * @method $this aggregationSize(int $size) Size for aggregations
 * @method $this updateOnChange() Set whether to trigger reindexing on related model change
 */
class Relation extends Base implements Filterable, Aggregateble
{
	use AppliesTermQuery;

	protected $queryBuilder;

	protected $where = [];

	/** @var string Related model primary key name */
	protected $otherKey;

	public function __construct(string $name, $related = null, $foreignKey = null, $otherKey = 'id')
	{
		parent::__construct($name);
		$this->related      = $related ?? $name;
		$this->fillableName = $foreignKey ?? $name . '_id';
		$this->otherKey     = $otherKey;
	}

	/**
	 * @inheritdoc
	 */
	public function applyIndexData($data, $model)
	{
		if ($data->has($this->name) && !$model->isDirty($this->fillableName)) {
			return;
		}

		if (!($model_id = $model->getAttribute($this->fillableName))) {
			return;
		}

		// Always retrieve data as collection
		$results = $this->getResults($model)
			->keyBy($this->otherKey)
			->map(function (Entity $item) {
				$item = $item->getIndexDocumentData($this->mapProperties ?: null);
				return (object) ($item);
			});

		// Sort models by input ids
		$array = collect();
		foreach (Arr::wrap($model_id) as $id) {
			$array[$id] = $results->get($id);
		}

		$array = $array->filter();

		// then, if not multiple, just take the first item
		$ids = $array->keys()->values();
		$data->put($this->fillableName, $this->multiple ? $ids->all() : $ids->first());
		$data->put($this->name, $this->multiple ? $array->values()->all() : $array->first());
	}

	/**
	 * @inheritdoc
	 */
	public function applyHydratingIndexData($data, $model)
	{
		if (!isset($data[$this->name])) {
			return;
		}

		if ($this->multiple) {
			$data[$this->name] = collect($data[$this->name])
				->mapInto(Fluent::class);
		}
		else {
			$data[$this->name] = new Fluent($data[$this->name]);
		}
	}

	/**
	 * Adds attribute mappings for elastic search
	 *
	 * @param \Illuminate\Support\Collection $mapping Mapping to modify
	 *
	 * @return void
	 */
	public function elasticMapping($mapping)
	{
		$mapping[$this->fillableName] = ['type' => 'integer'];

		$modelMapping = $this->getInstance()->getMappingProperties();

		$mapping[$this->name] = [
			'type' => 'nested',
			'properties' => $this->mapProperties ? Arr::only($modelMapping, $this->mapProperties) : $modelMapping,
		];
	}

	/**
	 * Adds attribute casts
	 *
	 * @param \Illuminate\Support\Collection $casts
	 */
	public function applyCasts($casts)
	{
		$casts->put($this->fillableName, 'integer');
	}

	/**
	 * @inheritdoc
	 */
	public function isValueEmpty($value)
	{
		return trim($value) === '';
	}

	/**
	 * @inheritDoc
	 */
	public function filterKeys() : array
	{
		return [$this->fillableName, $this->name];
	}

	/**
	 * @inheritDoc
	 */
	public function applyFilter($criteria, $key) : AbstractQuery
	{
		return $this->createTermQuery($criteria, $this->fillableName);
	}

	/**
	 * @inheritDoc
	 */
	public function applyAggregation()
	{
		return Elastic::aggregation()
			->nested('nested', $this->name)
			->addAggregation(
				Elastic::aggregation()
					->terms('available')
					->setField($this->name . '.id')
					->setSize($this->aggregationSize ?: 60)
					->addAggregation(
						Elastic::aggregation()
							->top_hits('entities')
							->setSize(1)
					)
			);
	}

	/**
	 * Collect aggregations from raw ES result
	 *
	 * @param \Stylemix\Listing\Elastic\Aggregations $aggregations
	 * @param array $raw Raw aggregation data from ES
	 */
	public function collectAggregations($aggregations, $raw)
	{
		$entries = [];

		foreach (data_get($raw, $this->name . '.nested.available.buckets', []) as $bucket) {
			$source = data_get($bucket, 'entities.hits.hits.0._source');
			if (empty($source)) {
				continue;
			}

			$entries[] = array_merge(
				$source,
				['count' => $bucket['doc_count']]
			);
		}

		$aggregations->put($this->name, $entries);
	}

	/**
	 * Adds constraint to model queries
	 *
	 * @param string $column
	 * @param string $operator
	 * @param mixed $value
	 * @param string $boolean
	 *
	 * @return static
	 */
	public function where($column, $operator = null, $value = null, $boolean = 'and')
	{
		$this->where[] = func_get_args();

		return $this;
	}

	/**
	 * @param \Stylemix\Listing\Entity $entity
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function getResults($entity)
	{
		if (!($model_id = $entity->getAttribute($this->fillableName))) {
			return collect();
		}

		$model_id = Arr::wrap($model_id);

		return $this->getQueryBuilder()
			->whereIn($this->otherKey, $model_id)
			->get();
	}

	/**
	 * Whether owner entity should be updated by related entity changes for this attribute
	 *
	 * @param Entity $owner   Owner entity model of this attribute
	 * @param Entity $related Related entity model
	 *
	 * @return null|boolean
	 */
	public function shouldTriggerRelatedUpdate($owner, $related)
	{
		return $this->updateOnChange;
	}

	/**
	 * Get new eloquent builder for related model
	 *
	 * @return Builder
	 */
	public function getQueryBuilder()
	{
		$builder = $this->getInstance()->newQuery();

		foreach ($this->where as $criteria) {
			$builder->where(...$criteria);
		}

		return $builder;
	}

	/**
	 * @return \Stylemix\Listing\Elastic\Builder
	 */
	public function getIndexedQueryBuilder()
	{
		$builder = $this->getInstance()->search();

		foreach ($this->where as $criteria) {
			$builder->where(...$criteria);
		}

		return $builder;
	}

	/**
	 * Creates new instance of related entity
	 *
	 * @return \Stylemix\Listing\Entity
	 */
	public function getInstance()
	{
		return Entities::make($this->related);
	}

	/**
	 * Get related entity's key field name
	 *
	 * @return string
	 */
	public function getOtherKey()
	{
		return $this->otherKey;
	}
}
