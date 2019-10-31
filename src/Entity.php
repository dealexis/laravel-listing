<?php

namespace Stylemix\Listing;

use Carbon\Carbon;
use Elasticquent\ElasticquentTrait;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Plank\Mediable\Mediable;
use Stylemix\Base\Eloquent\CastsEnums;
use Stylemix\Base\Eloquent\DateFixes;
use Stylemix\Listing\Contracts\Mutatable;
use Stylemix\Listing\Elastic\Aggregations;
use Stylemix\Listing\Elastic\Builder;
use Stylemix\Listing\Elastic\Collection;

/**
 * Class Entity
 *
 * @property \Stylemix\Listing\EntityData[]|\Illuminate\Support\Collection dataAttributes
 * @package Stylemix\Listing
 */
abstract class Entity extends Model
{
	use DateFixes, CastsEnums, ElasticquentTrait, Mediable {
		ElasticquentTrait::newCollection insteadof Mediable;
	}

	/**
	 * @var \Stylemix\Listing\AttributeCollection[]
	 */
	protected static $attributeDefinitions = [];

	protected static $resolvedFillable;

	protected static $resolvedCasts;

	protected static $resolvedMapping;

	protected static $indexingInTests = false;

	protected $dateFormat = 'Y-m-d\TH:i:s';

	protected $with = [
		'dataAttributes',
		'media',
	];

	/** @var array Map of enum attributes names to enum classes */
	protected $enums = [];

	protected $indexSettings = null;

	protected $mappingProperties = [];

	protected $sortValues = [];

	/**
	 * @var bool Force reloading attachments after changes
	 */
	protected $rehydrates_media = true;

	/**
	 * Attributes relation
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function dataAttributes(): HasMany
	{
		$instance = new EntityData();
		$instance->setTable($this->getTable() . '_data');

		return new HasMany($instance->newQuery(), $this, $instance->getTable() . '.entity_id', $this->getKeyName());
	}

	/**
	 * @inheritdoc
	 */
	public function getFillable()
	{
		return array_merge($this->fillable, static::$resolvedFillable[static::class]);
	}

	/**
	 * @inheritdoc
	 */
	public function getCasts()
	{
		return parent::getCasts() + static::$resolvedCasts[static::class];
	}

	public function getMappingProperties()
	{
		return array_merge($this->mappingProperties, static::$resolvedMapping[static::class]);
	}

	/**
	 * @inheritdoc
	 */
	public function getAttribute($key)
	{
		if ($this->hasGetMutator($key) || method_exists(self::class, $key)) {
			return parent::getAttribute($key);
		}

		$attribute = $this->getAttributeDefinitions()->find($key);

		if ($attribute instanceof Mutatable) {
			return $attribute->getMutator($this, $key);
		}

		if ($attribute && $attribute->multiple) {
			return $this->castMultipleAttribute($key, $this->getAttributeFromArray($key));
		}

		return parent::getAttribute($key);
	}

	/**
	 * @inheritdoc
	 */
	public function setAttribute($key, $value)
	{
		if ($this->hasSetMutator($key)) {
			parent::setAttribute($key, $value);

			return;
		}

		/** @var \Stylemix\Listing\Attribute\Base $attribute */
		$attribute = $this->getAttributeDefinitions()->keyByFills()->get($key);

		if (!$attribute) {
			parent::setAttribute($key, $value);

			return;
		}

		if ($attribute instanceof Mutatable) {
			$value = $attribute->setMutator($this, $key, $value);
		}

		if ($attribute->multiple) {
			$value = collect(array_values(Arr::wrap($value)))->filter(function ($value) use ($attribute) {
				return !$attribute->isValueEmpty($value);
			})->all();

			$this->attributes[$key] = $value;
			return;
		}

		parent::setAttribute($key, $value);
	}

	/**
	 * @inheritdoc
	 */
	protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes)
	{
		$multiple = $this->getAttributeDefinitions()->where('multiple', true);
		$multiple = array_merge($multiple->keys()->all(), $multiple->keyBy->fills()->keys()->all());

		$casted = parent::addCastAttributesToArray(Arr::except($attributes, $multiple), $mutatedAttributes);

		foreach (Arr::only($attributes, $multiple) as $key => $value) {
			$casted[$key] = $this->castMultipleAttribute($key, $value);
		}

		return $casted;
	}

	/**
	 * Get an attribute array of all arrayable relations.
	 *
	 * @return array
	 */
	protected function getArrayableRelations()
	{
		return Arr::except($this->getArrayableItems($this->relations), ['data_attributes', 'dataAttributes', 'media']);
	}

	/**
	 * @inheritdoc
	 */
	public function toArray()
	{
		$array = parent::toArray();

		static::getAttributeDefinitions()->each->applyArrayData($array = collect($array), $this);

		return $array->merge([
			'_score' => $this->documentScore(),
			'_sort' => $this->sortValues(),
			'_version' => $this->documentVersion(),
		])->all();
	}

	/**
	 * Convert model to option object.
	 * Usually used for dropdown options.
	 *
	 * @param string $primaryKey Attribute key for option value (defaults to model key)
	 *
	 * @return array
	 */
	public function toOption($primaryKey = null)
	{
		$primaryKey = $primaryKey ?? $this->getKeyName();

		return [
			'value' => $this->getAttribute($primaryKey),
			'label' => $this->title,
		];
	}

	/**
	 * @return \Stylemix\Listing\Elastic\Builder
	 */
	public function newElasticQuery()
	{
		return (new Builder())->setEntity($this);
	}

	/**
	 * @inheritdoc
	 */
	public function getIndexName()
	{
		return config('elasticquent.prefix', '') . $this->getTypeName();
	}

	/**
	 * @return array
	 */
	public function sortValues(): array
	{
		return $this->sortValues;
	}

	/**
	 * Add to Search Index
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function addToIndex()
	{
		if (!$this->exists) {
			throw new \Exception('Document does not exist.');
		}

		$params = $this->getBasicEsParams();
		// By default all writes to index will be synchronous
		$params['custom'] = ['refresh' => true];

		// Get our document body data.
		$params['body'] = $indexDocumentData = $this->getIndexDocumentData();

		// The id for the document must always mirror the
		// key for this model, even if it is set to something
		// other than an auto-incrementing value. That way we
		// can do things like remove the document from
		// the index, or get the document from the index.
		$params['id'] = $this->getKey();

		try {
			$result = $this->getElasticSearchClient()->index($params);

			DB::table($this->getTable())
				->where($this->getKeyName(), '=', $this->getKeyForSaveQuery())
				->update(['indexed_at' => now()]);

			return $result;
		}
		catch (\Exception $e) {
			DB::table($this->getTable())
				->where($this->getKeyName(), '=', $this->getKeyForSaveQuery())
				->update(['indexed_at' => null]);

			throw $e;
		}
	}

	/**
	 * Partial Update to Indexed Document
	 *
	 * @return array
	 */
	public function updateIndex()
	{
		$params = $this->getBasicEsParams();
		// By default all writes to index will be synchronous
		$params['custom'] = ['refresh' => true];

		// Get our document body data.
		$params['body']['doc'] = $this->getIndexDocumentData();

		return $this->getElasticSearchClient()->update($params);
	}

	/**
	 * Remove From Search Index
	 *
	 * @return array
	 */
	public function removeFromIndex()
	{
		$params = $this->getBasicEsParams();
		// By default all writes to index will be synchronous
		$params['custom'] = ['refresh' => true];

		try {
			$result = $this->getElasticSearchClient()->delete($params);
		}
		catch (Missing404Exception $e) {
			// That will mean the document was not found in index
		}

		DB::table($this->getTable())
			->where($this->getKeyName(), '=', $this->getKeyForSaveQuery())
			->update(['indexed_at' => null]);

		return isset($result) ? $result : null;
	}

	/**
	 * Get Index Document Data
	 *
	 * Get the data that Elasticsearch will
	 * index for this particular document.
	 *
	 * @param array|null $only Index only specific attributes
	 *
	 * @return array
	 */
	public function getIndexDocumentData($only = null)
	{
		// Take all attributes keys
		$only = is_null($only)
			? static::getAttributeDefinitions()->allKeys()
			: $only;

		// If an attribute is a date, we will cast it to a string after converting it
		// to a DateTime / Carbon instance. This is so we will get some consistent
		// formatting while accessing attributes vs. arraying / JSONing a model.
		$attributes = $this->addDateAttributesToArray(
			$attributes = Arr::only($this->getAttributes(), $only)
		);

		// Next we will handle any casts that have been setup for this model and cast
		// the values to their appropriate type. If the attribute has a mutator we
		// will not perform the cast on those attributes to avoid any confusion.
		$attributes = $this->addCastAttributesToArray(
			$attributes, []
		);

		// Here we will grab all of the appended, calculated attributes to this model
		// as these attributes are not really in the attributes array, but are run
		// when we need to array or JSON the model for convenience to the coder.
		foreach ($this->getArrayableAppends() as $key) {
			$attributes[$key] = $this->mutateAttributeForArray($key, null);
		}

		// Then allow all defined attributes to perform required actions with attributes data
		static::getAttributeDefinitions()
			->only($only)
			->each->applyIndexData($attributes = collect($attributes), $this);

		return $attributes->filter(function ($value) {
			return !is_null($value);
		})->all();
	}

	public function getBasicEsParams($getIdIfPossible = true, $limit = null, $offset = null)
	{
		$params = array(
			'index' => $this->getIndexName(),
			'type' => $this->getTypeName(),
		);

		if ($getIdIfPossible && $this->getKey()) {
			$params['id'] = $this->getKey();
		}

		if (is_numeric($limit)) {
			$params['size'] = $limit;
		}

		if (is_numeric($offset)) {
			$params['from'] = $offset;
		}

		return $params;
	}

	/**
	 * Wraps value to array and casts each value in array
	 *
	 * @param string $key
	 * @param mixed $value
	 *
	 * @return array
	 */
	protected function castMultipleAttribute($key, $value)
	{
		$values = is_array($value) || $value instanceof \ArrayAccess ? $value : Arr::wrap($value);

		if ($this->hasCast($key) && !$this->isJsonCastable($key)) {
			foreach ($values as $i => $value) {
				$values[$i] = $this->castAttribute($key, $value);
			}
		}

		return $values;
	}

	/**
	 * @inheritdoc
	 */
	public function newEloquentBuilder($query)
	{
		return new EntityBuilder($query);
	}

	/**
	 * New From Hit Builder
	 *
	 * Variation on newFromBuilder. Instead, takes
	 *
	 * @param array $hit
	 *
	 * @return static
	 */
	public function newFromHitBuilder($hit = array())
	{
		$key_name = $this->getKeyName();

		$attributes = $hit['_source'];

		if (isset($hit['_id'])) {
			$attributes[$key_name] = is_numeric($hit['_id']) ? intval($hit['_id']) : $hit['_id'];
		}

		// Add fields to attributes
		if (isset($hit['fields'])) {
			foreach ($hit['fields'] as $key => $value) {
				$attributes[$key] = $value;
			}
		}

		$instance = $this::newFromBuilderRecursive($this, $attributes);

		// In addition to setting the attributes
		// from the index, we will set the score as well.
		$instance->documentScore = $hit['_score'];

		// Set our document sort values if it's
		if (isset($hit['sort'])) {
			$instance->sortValues = $hit['sort'];
		}

		// This is now a model created
		// from an Elasticsearch document.
		$instance->isDocument = true;

		// Set our document version if it's
		if (isset($hit['_version'])) {
			$instance->documentVersion = $hit['_version'];
		}

		return $instance;
	}

	/**
	 * Create a new model instance that is existing recursive.
	 *
	 * @param  \Illuminate\Database\Eloquent\Model  $model
	 * @param  array  $attributes
	 * @param  \Illuminate\Database\Eloquent\Relations\Relation  $parentRelation
	 * @return static
	 */
	public static function newFromBuilderRecursive(Model $model, array $attributes = [], Relation $parentRelation = null)
	{
		$instance = $model->newInstance([], $exists = true);

		foreach ($attributes as $key => $val) {
			if ($model->isDateAttribute($key)) {
				$val = Carbon::parse($val);
			}

			$attributes[$key] = $val;
		}

		static::getAttributeDefinitions()->each->applyHydratingIndexData($attributes = collect($attributes), $instance);
		$instance->setRawAttributes($attributes->all(), true);

		// Load relations recursive
		static::loadRelationsAttributesRecursive($instance);
		// Load pivot
		static::loadPivotAttribute($instance, $parentRelation);

		return $instance;
	}

	/**
	 * Checks if ES index exists
	 *
	 * @return bool
	 */
	public static function indexExists()
	{
		$instance = new static;

		$client = $instance->getElasticSearchClient();

		$index = array(
			'index' => $instance->getIndexName(),
		);

		return $client->indices()->exists($index);
	}

	/**
	 * Get resolved attribute definitions.
	 *
	 * @return \Stylemix\Listing\AttributeCollection|\Stylemix\Listing\Attribute\Base[]
	 */
	public static function getAttributeDefinitions()
	{
		if (!isset(static::$attributeDefinitions[static::class])) {
			$definitions = static::attributeDefinitions();

			static::$attributeDefinitions[static::class] = (new AttributeCollection($definitions))
				->keyBy('name');
		}

		return static::$attributeDefinitions[static::class];
	}

	/**
	 * Attribute definitions
	 * @return array
	 */
	abstract protected static function attributeDefinitions() : array;

	/**
	 * Resolve and cache model fillable
	 */
	protected static function resolveFillable()
	{
		static::getAttributeDefinitions()->each->applyFillable($fillable = collect());
		static::$resolvedFillable[static::class] = $fillable->all();
	}

	/**
	 * Resolve and cache model casts
	 */
	protected static function resolveCasts()
	{
		static::getAttributeDefinitions()->each->applyCasts($casts = collect());

		// Remove casts for all multiple attributes
		// TODO: implement casts to multiple attributes with the same
		$multiple = static::getAttributeDefinitions()
			->where('multiple', true)
			->pluck('fillableName');

		$casts = $casts->except($multiple);

		static::$resolvedCasts[static::class] = $casts->all();
	}

	/**
	 * Mapping for ElasticSearch
	 */
	protected static function resolveMappingProperties()
	{
		// Map data attributes
		static::getAttributeDefinitions()->each->elasticMapping($mapping = collect());

		static::$resolvedMapping[static::class] = $mapping->all();
	}

	/**
	 * Begin querying model from ElasticSearch
	 *
	 * @param mixed $request
	 *
	 * @return \Stylemix\Listing\Elastic\Builder
	 */
	public static function search($request = null)
	{
		$builder = (new static)->newElasticQuery();

		if ($request instanceof Arrayable) {
			$request = $request->toArray();
		}

		if (is_array($request)) {
			$builder->fromArray($request);
		}

		return $builder;
	}

	/**
	 * Create a new Elasticquent Result Collection instance.
	 *
	 * @param  array  $models
	 * @param  array  $meta
	 * @return \Stylemix\Listing\Elastic\Collection
	 */
	public function newElasticquentResultCollection(array $models = [], $meta = null)
	{
		if (isset($meta['aggregations'])) {
			$meta['aggregations'] = Aggregations::build($this, $meta['aggregations']);
		}

		return new Collection($models, $meta);
	}

	/**
	 * @param callable $callback
	 *
	 * @return mixed
	 */
	public static function withoutEvents(callable $callback)
	{
		$originalDispatcher = static::getEventDispatcher();
		$app = app();
		static::setEventDispatcher(
			(new Dispatcher($app))->setQueueResolver(function () use ($app) {
				return $app->make(QueueFactoryContract::class);
			})
		);
		static::registerPrimaryListeners();

		try {
			return $callback();
		} finally {
			if ($originalDispatcher) {
				static::setEventDispatcher($originalDispatcher);
			}
		}
	}

	/**
	 * @inheritdoc
	 */
	protected static function boot()
	{
		// Method boot calls bootTraits that may bind event listeners to the model
		// Binding listeners creates new instance of the model
		// which requires all fillable and casts to be resolved
		static::resolveFillable();
		static::resolveCasts();
		static::resolveMappingProperties();

		parent::boot();

		// Primary listeners: attributes to data storing and ES indexing
		static::registerPrimaryListeners();

		static::observe(RelationListener::class);
	}

	/**
	 * Enable or disable indexing to ES in testing mode
	 *
	 * @param bool $value
	 */
	public static function indexingInTests($value = true)
	{
		static::$indexingInTests = $value;
	}

	public static function registerPrimaryListeners()
	{
		static::observe(AttributeListener::class);

		if (!app()->runningUnitTests() || static::$indexingInTests) {
			static::observe(IndexingListener::class);
		}
	}

}
