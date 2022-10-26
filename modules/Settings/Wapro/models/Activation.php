<?php
/**
 * Activation file for WAPRO ERP module.
 *
 * @package Settings.Model
 *
 * @copyright YetiForce S.A.
 * @license YetiForce Public License 5.0 (licenses/LicenseEN.txt or yetiforce.com)
 * @author Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 */

/**
 * Activation model class for WAPRO ERP.
 */
class Settings_Wapro_Activation_Model
{
	/** @var array Map relation table name */
	private const FIELDS = [
		['wapro_id', 'MultiCompany', 'LBL_SYSTEM_INFORMATION'],
		['wapro_id', 'BankAccounts', 'LBL_CUSTOM_INFORMATION'],
		['wapro_id', 'Accounts', 'LBL_ADVANCED_BLOCK'],
		['wapro_id', 'Contacts', 'LBL_CUSTOM_INFORMATION'],
		['wapro_id', 'Products', 'LBL_PRODUCT_INFORMATION'],
		['wapro_id', 'FInvoice', 'LBL_CUSTOM_INFORMATION'],
		['wapro_id', 'FCorectingInvoice', 'LBL_CUSTOM_INFORMATION'],
		['wapro_paid', 'FInvoice', 'LBL_CUSTOM_INFORMATION', [
			'uitype' => 317, 'label' => 'FL_WAPRO_PAID', 'columntype' => 'decimal(28,8)', 'maximumlength' => '1.0E+20',
			'typeofdata' => 'NN~O', 'displaytype' => 9
		]],
		['wapro_user', 'Users', 'LBL_USERLOGIN_ROLE', [
			'uitype' => 1, 'label' => 'FL_WAPRO_USER', 'columntype' => 'varchar(20)', 'maximumlength' => '20', 'typeofdata' => 'V~O'
		]],
	];

	/**
	 * Check if the functionality has been activated.
	 *
	 * @return bool
	 */
	public static function check(): bool
	{
		$db = \App\Db::getInstance();
		$check = $db->isTableExists(\App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME) && $db->isTableExists(\App\Integrations\Wapro::LOG_TABLE_NAME);
		if ($check) {
			foreach (self::FIELDS as $field) {
				$check = (new \App\Db\Query())->from('vtiger_field')->where(['tabid' => \App\Module::getModuleId($field[1]), 'fieldname' => $field[0]])->exists();
				if (!$check) {
					break;
				}
			}
		}
		if ($check) {
			$check = Vtiger_Inventory_Model::getInstance('FInvoice')->isField('discount_aggreg');
		}
		if ($check) {
			$cron = (new \App\Db\Query())->from('vtiger_cron_task')->where(['name' => 'LBL_INTEGRATION_WAPRO', 'handler_class' => 'Vtiger_IntegrationWapro_Cron'])->one();
			$check = $cron && 1 == $cron['status'];
		}
		return $check;
	}

	/**
	 * Activate integration, requires creation of additional integration data.
	 *
	 * @return bool
	 */
	public static function activate(): bool
	{
		$importer = new \App\Db\Importers\Base();
		$db = \App\Db::getInstance();
		if (!$db->isTableExists(\App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME)) {
			$db->createTable(\App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME, [
				'crmid' => $importer->integer(10)->notNull(),
				'wid' => $importer->integer(10)->notNull(),
				'wtable' => $importer->stringType('50')->notNull(),
			]);
			$db->createCommand()->createIndex(\App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME . '_crmid_idx', \App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME, 'crmid', true)->execute();
			$db->createCommand()->createIndex(\App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME . '_wapro_idx', \App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME, 'wtable,wid')->execute();
			$db->createCommand()->addForeignKey(
				'fk_1_' . \App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME, \App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME, 'crmid', 'vtiger_crmentity', 'crmid', 'CASCADE', 'RESTRICT'
			)->execute();
			$status = true;
		}
		if (!$db->isTableExists(\App\Integrations\Wapro::LOG_TABLE_NAME)) {
			$db->createTable(\App\Integrations\Wapro::LOG_TABLE_NAME, [
				'id' => $importer->primaryKeyUnsigned(),
				'time' => $importer->dateTime()->notNull(),
				'category' => $importer->stringType(100),
				'message' => $importer->stringType(255),
				'error' => $importer->boolean(),
				'trace' => $importer->text(),
			]);
			$status = true;
		}
		foreach (self::FIELDS as $field) {
			$moduleId = \App\Module::getModuleId($field[1]);
			$check = (new \App\Db\Query())->from('vtiger_field')->where(['tabid' => $moduleId, 'fieldname' => $field[0]])->exists();
			if (!$check) {
				$moduleModel = Vtiger_Module_Model::getInstance($field[1]);
				$blockModel = vtlib\Block::getInstance($field[2], $field[1]);
				if (!$blockModel) {
					$blocks = vtlib\Block::getAllForModule($moduleModel);
					$blockModel = current($blocks);
				}
				$fieldInstance = new Vtiger_Field_Model();
				$fieldInstance->set('name', $field[0])->set('tabid', $moduleId);
				if ('wapro_id' === $field[0]) {
					$fieldInstance->set('column', 'wid')
						->set('columntype', $importer->integer(10)->notNull())
						->set('table', $db->quoteSql(\App\Integrations\Wapro::RECORDS_MAP_TABLE_NAME))
						->set('label', 'FL_WAPRO_ID')
						->set('uitype', 1)
						->set('displaytype', 9)
						->set('typeofdata', 'I~O');
				} else {
					$entityInstance = $moduleModel->getEntityInstance();
					if (empty($entityInstance->customFieldTable)) {
						$tableName = $entityInstance->table_name;
					} else {
						$tableName = current($entityInstance->customFieldTable);
					}
					$fieldInstance->set('column', $field[0])->set('table', $tableName);
					foreach ($field[3] as $key => $value) {
						$fieldInstance->set($key, $value);
					}
				}
				if ($fieldInstance->save($blockModel)) {
					$status = true;
				} else {
					$status = false;
				}
			}
		}
		self::activateInventory();
		self::activateCron();
		return $status ?? false;
	}

	/**
	 * Activate integration in inventory.
	 *
	 * @return void
	 */
	private static function activateInventory(): void
	{
		$inventory = Vtiger_Inventory_Model::getInstance('FInvoice');
		$fieldModel = $inventory->getFieldCleanInstance('DiscountAggregation');
		$fieldModel->setDefaultDataConfig();
		$inventory->saveField($fieldModel);
	}

	/**
	 * Activate integration in cron.
	 *
	 * @return void
	 */
	private static function activateCron(): void
	{
		$cron = (new \App\Db\Query())->from('vtiger_cron_task')->where(['name' => 'LBL_INTEGRATION_WAPRO', 'handler_class' => 'Vtiger_IntegrationWapro_Cron'])->one();
		if (empty($cron)) {
			\vtlib\Cron::register('LBL_INTEGRATION_WAPRO', 'Vtiger_IntegrationWapro_Cron', 900, 'Vtiger', 1);
		}
		if (1 != $cron['status']) {
			\App\Cron::updateStatus(\App\Cron::STATUS_ENABLED, 'LBL_INTEGRATION_WAPRO');
		}
	}
}
