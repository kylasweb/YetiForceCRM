<?php

/**
 * WooCommerce product synchronization map file.
 *
 * The file is part of the paid functionality. Using the file is allowed only after purchasing a subscription.
 * File modification allowed only with the consent of the system producer.
 *
 * @package Integration
 *
 * @copyright YetiForce S.A.
 * @license   YetiForce Public License 5.0 (licenses/LicenseEN.txt or yetiforce.com)
 * @author    Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 */

namespace App\Integrations\WooCommerce\Synchronizer\Maps;

/**
 * WooCommerce product synchronization map class.
 */
class Product extends Base
{
	/** {@inheritdoc} */
	protected $moduleName = 'Products';
	/** @var bool Is variation */
	public $isVariation = false;
	/**
	 * @todo Properties:
	 * 	[featured] => 1
	 *	[purchasable] => 1
	 *	[manage_stock] => 1
	 *	[stock_status] => instock
	 *	[has_options] => 1
	 *
	 * {@inheritdoc}
	 */
	protected $fieldMap = [
		'productname' => 'name',
		'discontinued' => ['name' => 'on_sale', 'fn' => 'convertBool'],
		'alias' => ['name' => 'slug', 'optional' => true],
		'ean' => 'sku',
		'parent_id' => ['name' => 'parent_id', 'fn' => 'findRelationship'],
		'tags' => ['name' => 'tags', 'fn' => 'convertTags', 'optional' => true],
		'product_type' => ['name' => 'type', 'mayNotExist' => true, 'map' => [
			'simple' => 'PLL_TYPE_SIMPLE',
			'grouped' => 'PLL_TYPE_GROUPED',
			'variable' => 'PLL_TYPE_VARIABLE',
		]],
		'woocommerce_permalink' => ['name' => 'permalink', 'direction' => 'yf'],
		'woocommerce_product_status' => ['name' => 'status', 'map' => [
			'publish' => 'FL_WOO_PUBLISH',
			'pending' => 'FL_WOO_PENDING',
			'draft' => 'FL_WOO_DRAFT',
		]],
		'woocommerce_product_visibility' => ['name' => 'catalog_visibility', 'optional' => true, 'map' => [
			'visible' => 'FL_WOO_VISIBLE',
			'catalog' => 'FL_WOO_CATALOG',
			'search' => 'FL_WOO_SEARCH',
			'hidden' => 'FL_WOO_HIDDEN',
		]],
		'unit_price' => ['name' => 'regular_price', 'fn' => 'convertPrice'],
		'weight' => ['name' => 'weight', 'fn' => 'convert', 'apiType' => 'string', 'crmType' => 'float'],
		'qtyinstock' => ['name' => 'stock_quantity', 'fn' => 'convert', 'apiType' => 'float', 'crmType' => 'float'],
		'createdtime' => ['name' => 'date_created_gmt', 'fn' => 'convertDateTime', 'direction' => 'yf'],
		'description' => 'description',
		'short_description' => ['name' => 'short_description', 'optional' => true],
	];
	/** @var string[] List of fields to copy values from product to variants. */
	protected $variationCopyFieldList = [
		'name' => 'name',
		'type' => 'type',
		'id' => 'parent_id',
	];
	/** @var string[] List of fields that are skipped when there is a double value for variants. */
	protected $variationSkipDuplicateValues = ['ean', 'description'];
	/** @var array List of fields to copy values from product to variants. */
	protected $attributesMap;
	/** @var int[] */
	private $categories = [];
	/** @var string[] */
	public $productType = ['PLL_TYPE_SIMPLE', 'PLL_TYPE_GROUPED', 'PLL_TYPE_VARIABLE'];
	/** @var \App\Integrations\WooCommerce\Synchronizer\ProductCategory Product category model instance */
	public $category;
	/** @var \App\Integrations\WooCommerce\Synchronizer\ProductTags Product category model instance */
	public $tags;

	/** {@inheritdoc} */
	public function getDataYf(string $type = 'fieldMap'): array
	{
		parent::getDataYf($type);
		$this->importCategories();
		$this->importAttributes();
		return $this->dataYf;
	}

	/** {@inheritdoc} */
	public function getDataApi(): array
	{
		parent::getDataApi();
		$this->exportCategories();
		$this->exportAttributes();
		if ('PLL_TYPE_VARIABLE' === $this->dataYf['product_type']) {
			$this->exportVariations();
		}
		return $this->dataApi;
	}

	/** {@inheritdoc} */
	public function saveInYf(): void
	{
		parent::saveInYf();
		if (!empty($this->categories) || !$this->synchronizer->controller->config->get('master')) {
			$this->updateCategoriesInCrm();
		}
		if ('variable' === $this->dataApi['type']) {
			$this->updateVariationsInYf();
		}
	}

	/** {@inheritdoc} */
	public function saveInApi(): void
	{
		if ($this->isVariation) {
			$path = "products/{$this->dataApi['parent_id']}/variations";
			foreach ($this->variationCopyFieldList as $to) {
				unset($this->dataApi[$to]);
			}
		} else {
			$path = 'products';
		}
		if (empty($this->dataApi['id'])) {
			$response = $this->synchronizer->controller->getConnector()->request('POST', $path, $this->dataApi);
			$response = \App\Json::decode($response);
			$this->recordModel->set('woocommerce_id', $response['id']);
			$this->recordModel->save();
			$this->synchronizer->updateMapIdCache(
				$this->recordModel->getModuleName(),
				$response['id'], $this->recordModel->getId()
			);
		} else {
			$this->synchronizer->controller->getConnector()->request('PUT', "$path/{$this->dataApi['id']}", $this->dataApi);
		}
		if ('PLL_TYPE_VARIABLE' === $this->dataYf['product_type']) {
			$this->updateVariationsInApi();
		}
	}

	/**
	 * Update categories in YF.
	 *
	 * @return void
	 */
	protected function updateCategoriesInCrm(): void
	{
		$relationModel = \Vtiger_Relation_Model::getInstance(
			$this->recordModel->getModule(),
			\Vtiger_Module_Model::getInstance('ProductCategory')
		);
		$relationModel->set('QueryFields', ['id'])->set('parentRecord', $this->recordModel);
		$queryGenerator = $relationModel->getQuery();
		$queryGenerator->addCondition('woocommerce_server_id', $this->synchronizer->controller->config->get('id'), 'e');
		$recordCategories = array_flip($queryGenerator->createQuery()->column());
		foreach ($this->categories as $categoryId) {
			if (isset($recordCategories[$categoryId])) {
				unset($recordCategories[$categoryId]);
			} else {
				$relationModel->addRelation($this->recordModel->getId(), $categoryId);
			}
		}
		foreach ($recordCategories as $categoryId => $value) {
			$relationModel->deleteRelation($this->recordModel->getId(), $categoryId);
		}
	}

	/**
	 * Update variations in YF.
	 *
	 * @return void
	 */
	protected function updateVariationsInYf(): void
	{
		$variationsYf = $this->getVariationsFromYf()->indexBy('woocommerce_id')->column();
		foreach ($this->getVariationsFromApi() as $variation) {
			foreach ($this->variationCopyFieldList as $from => $to) {
				$variation[$to] = $this->dataApi[$from];
			}
			$mapModel = clone $this;
			$mapModel->isVariation = true;
			$mapModel->setDataApi($variation);
			if ($dataYf = $mapModel->getDataYf()) {
				$dataYf['product_type'] = 'PLL_TYPE_VARIATION';
				$mapModel->setDataYf($dataYf);
				try {
					$mapModel->loadRecordModel($variationsYf[$variation['id']] ?? 0);
					$mapModel->saveInYf();
					if (isset($variationsYf[$variation['id']])) {
						unset($variationsYf[$variation['id']]);
					}
				} catch (\Throwable $ex) {
					$this->synchronizer->log('Saving product variation', $dataYf, $ex);
					\App\Log::error(
						'Error during saving product variation: ' . PHP_EOL . $ex->__toString(),
						$this->synchronizer::LOG_CATEGORY
					);
				}
			} else {
				\App\Log::error('Empty map product variation', $this->synchronizer::LOG_CATEGORY);
			}
		}
		foreach ($variationsYf as $yfId) {
			\Vtiger_Record_Model::getInstanceById($yfId, $this->moduleName)->delete();
		}
	}

	/**
	 * Get variations from YF.
	 *
	 * @param bool $additionalColumns
	 *
	 * @return \App\Db\Query
	 */
	protected function getVariationsFromYf(bool $additionalColumns = false): \App\Db\Query
	{
		$queryGenerator = $this->synchronizer->getFromYf($this->getModule());
		$queryGenerator->addCondition('parent_id', $this->recordModel->getId(), 'eid');
		if ($additionalColumns) {
			$queryGenerator->setFields(array_merge(
				['id', 'modifiedtime'],
				array_keys($this->getFields()),
				$this->getAttributesMapFields()
			));
			$queryGenerator->addCondition('product_type', 'PLL_TYPE_VARIATION', 'e');
		} else {
			$queryGenerator->setFields(['id', 'woocommerce_id']);
		}
		return $queryGenerator->createQuery();
	}

	/**
	 * Get categories from YF.
	 *
	 * @return array
	 */
	protected function getCategoriesFromYf(): array
	{
		$relationModel = \Vtiger_Relation_Model::getInstance(
			$this->recordModel->getModule(),
			\Vtiger_Module_Model::getInstance('ProductCategory')
		);
		$relationModel->set('parentRecord', $this->recordModel);
		$queryGenerator = $relationModel->getQuery();
		$queryGenerator->setFields(['id', 'woocommerce_id']);
		$queryGenerator->addCondition('woocommerce_server_id', $this->synchronizer->controller->config->get('id'), 'e');
		return $queryGenerator->createQuery()->all();
	}

	/**
	 * Convert tags.
	 *
	 * @param mixed $value
	 * @param array $field
	 * @param bool  $fromApi
	 *
	 * @return string|array string (YF) or array (API)
	 */
	protected function convertTags($value, array $field, bool $fromApi)
	{
		if ($fromApi) {
			$tags = [];
			foreach ($value as $tag) {
				$tags[] = $tag['name'];
			}
			return implode(\Vtiger_Multipicklist_UIType::SEPARATOR, $tags);
		}
		if (null === $this->tags) {
			$this->tags = new \App\Integrations\WooCommerce\Synchronizer\ProductTags($this->synchronizer->controller);
		}
		$all = $this->tags->getTagsIds();
		$tags = [];
		foreach (explode(\Vtiger_Multipicklist_UIType::SEPARATOR, $value) as $tag) {
			if (isset($all[$tag])) {
				$tags[] = ['id' => $all[$tag],  'name' => $tag];
			}
		}
		return $tags;
	}

	/**
	 * Import product categories.
	 *
	 * @return void
	 */
	protected function importCategories(): void
	{
		$this->categories = [];
		if (empty($this->synchronizer->controller->config->get('sync_categories')) || empty($this->dataApi['categories'])) {
			return;
		}
		if (null === $this->category) {
			$this->category = new \App\Integrations\WooCommerce\Synchronizer\ProductCategory($this->synchronizer->controller);
		}
		foreach ($this->dataApi['categories'] as $category) {
			$yfId = $this->category->getYfId($category['id'], 'ProductCategory');
			if (empty($yfId)) {
				$yfId = $this->category->saveCategory($category['id']);
			}
			$this->categories[] = $yfId;
		}
	}

	/**
	 * Import product attributes.
	 *
	 * @return void
	 */
	protected function importAttributes(): void
	{
		$customAttributes = $this->synchronizer->controller->config->get('customAttributes');
		$attributesList = $this->getAttributesMap();
		foreach ($this->dataApi['attributes'] as $attr) {
			if (isset($attributesList[$attr['id']])) {
				$attributeMap = $attributesList[$attr['id']];
				if (isset($attributeMap['yfField'])) {
					$this->dataYf[$attributeMap['yfField']] = $this->isVariation ?
					$attr['option'] : implode(\Vtiger_Multipicklist_UIType::SEPARATOR, $attr['options']);
				}
			} elseif (isset($customAttributes[$attr['name']])) {
				$this->dataYf[$customAttributes[$attr['name']]] = implode(' | ', $this->isVariation ?
				$attr['option'] : $attr['options']);
			}
		}
	}

	/**
	 * Export product categories.
	 *
	 * @return void
	 */
	protected function exportCategories(): void
	{
		if (empty($this->synchronizer->controller->config->get('sync_categories'))) {
			return;
		}
		if (null === $this->category) {
			$this->category = new \App\Integrations\WooCommerce\Synchronizer\ProductCategory($this->synchronizer->controller);
		}
		$this->dataApi['categories'] = [];
		foreach ($this->getCategoriesFromYf() as $category) {
			$this->dataApi['categories'][] = ['id' => $category['woocommerce_id']];
		}
	}

	/**
	 * Export product attributes.
	 *
	 * @return void
	 */
	protected function exportAttributes(): void
	{
		$this->dataApi['attributes'] = [];
		$position = 0;
		foreach ($this->getAttributesMap() as $attrId => $attr) {
			if (isset($attr['yfField'])) {
				$api = ['id' => $attrId, 'name' => $attr['name'], 'position' => $position, 'visible' => 1];
				$attrValue = explode(\Vtiger_Multipicklist_UIType::SEPARATOR, $this->dataYf[$attr['yfField']]);
				if ($this->isVariation) {
					$api['option'] = reset($attrValue);
				} else {
					$api['options'] = $attrValue;
				}
				$this->dataApi['attributes'][] = $api;
				++$position;
			}
		}
		foreach ($this->synchronizer->controller->config->get('customAttributes') as $wooName => $yfName) {
			if (\array_key_exists($yfName, $this->dataYf)) {
				$api = ['id' => 0, 'name' => $wooName, 'position' => $position, 'visible' => 1];
				if ($this->isVariation) {
					$api['option'] = $this->dataYf[$yfName] ?? '';
				} else {
					$api['options'] = explode(' | ', $this->dataYf[$yfName]);
				}
				$this->dataApi['attributes'][] = $api;
				++$position;
			}
		}
	}

	/**
	 * Export product variations.
	 *
	 * @return void
	 */
	protected function exportVariations(): void
	{
		$dataReader = $this->getVariationsFromYf(true)->createCommand()->query();
		while ($variation = $dataReader->read()) {
			$this->dataYf['variations'][] = $variation;
		}
	}

	/**
	 * Get variations from API.
	 *
	 * @return array
	 */
	protected function getVariationsFromApi(): array
	{
		return $this->synchronizer->getFromApi("products/{$this->dataApi['id']}/variations");
	}

	/**
	 * Create/update product variations by API.
	 *
	 * @return void
	 */
	protected function updateVariationsInApi(): void
	{
		$master = $this->synchronizer->controller->config->get('master');
		foreach ($this->dataYf['variations'] as $variation) {
			foreach ($this->variationSkipDuplicateValues as $fieldName) {
				if ($variation[$fieldName] == $this->dataYf[$fieldName]) {
					$variation[$fieldName] = '';
				}
			}
			$mapModel = clone $this;
			$mapModel->isVariation = true;
			$mapModel->setDataYf($variation, true);
			$mapModel->setDataApi([]);
			if ($dataApi = $mapModel->getDataApi()) {
				try {
					if (empty($variation['woocommerce_id']) || $master) {
						$mapModel->saveInApi();
					}
				} catch (\Throwable $ex) {
					$this->synchronizer->log('Export product variation', ['YF' => $variation, 'API' => $dataApi], $ex);
					\App\Log::error(
						'Error during export product variation: ' . PHP_EOL . $ex->__toString(),
						$this->synchronizer::LOG_CATEGORY
					);
				}
			} else {
				\App\Log::error('Empty map product variation details', $this->synchronizer::LOG_CATEGORY);
			}
		}
	}

	/**
	 * Get attributes map.
	 *
	 * @return array
	 */
	public function getAttributesMap(): array
	{
		if (isset($this->attributesMap)) {
			return $this->attributesMap;
		}
		$productAttributes = $this->synchronizer->controller->getSync('ProductAttributes');
		foreach ($productAttributes->getListFromApi() as $attribute) {
			if ($fieldName = $productAttributes->getMap($attribute['slug'])) {
				$attribute['yfField'] = $fieldName;
			}
			$this->attributesMap[$attribute['id']] = $attribute;
		}
		return $this->attributesMap;
	}

	/**
	 * Get attributes map fields.
	 *
	 * @return string[]
	 */
	public function getAttributesMapFields(): array
	{
		$fields = [];
		foreach ($this->getAttributesMap() as $attr) {
			if (isset($attr['yfField'])) {
				$fields[] = $attr['yfField'];
			}
		}
		foreach ($this->synchronizer->controller->config->get('customAttributes') as $fieldName) {
			$fields[] = $fieldName;
		}
		return $fields;
	}
}
