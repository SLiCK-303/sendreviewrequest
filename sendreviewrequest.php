<?php
/**
 * Copyright (C) 2018 SLiCK-303
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * @package    sendreviewrequest
 * @author     SLiCK-303 <slick_303@hotmail.com>
 * @copyright  2018 SLiCK-303
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
**/

if (!defined('_TB_VERSION_')) {
	exit;
}

/**
 * Class SendReviewRequest
 */
class SendReviewRequest extends Module
{
	public function __construct()
	{
		$this->name = 'sendreviewrequest';
		$this->version = '3.0.0';
		$this->author = 'SLiCK-303';
		$this->tab = 'emailing';
		$this->need_instance = 0;

		$this->conf_keys = [
			'SEND_REVW_REQUEST_ENABLE',
			'SEND_REVW_REQUEST_STATE',
			'SEND_REVW_REQUEST_GROUP',
			'SEND_REVW_REQUEST_NUMBER',
			'SEND_REVW_REQUEST_COLUMNS',
			'SEND_REVW_REQUEST_DAYS'
		];

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('Send Review Request');
		$this->description = $this->l('Send a review request after a defined order state.');

		$this->confirmUninstall = $this->l('Are you sure you want to delete all settings and your logs?');

		$secure_key = Configuration::get('SEND_REVW_REQUEST_SECURE_KEY');
		if($secure_key === false)
			Configuration::updateValue('SEND_REVW_REQUEST_SECURE_KEY', Tools::strtoupper(Tools::passwdGen(16)));
	}

	public function install()
	{
		if (!parent::install() ||
			!$this->registerHook('header') ||
			!Configuration::updateValue('SEND_REVW_REQUEST_ENABLE', 1) ||
			!Configuration::updateValue('SEND_REVW_REQUEST_STATE', 5) ||
			!Configuration::updateValue('SEND_REVW_REQUEST_GROUP', '3') ||
			!Configuration::updateValue('SEND_REVW_REQUEST_NUMBER', 6) ||
			!Configuration::updateValue('SEND_REVW_REQUEST_COLUMNS', 2) ||
			!Configuration::updateValue('SEND_REVW_REQUEST_DAYS', 7) ||
			!Db::getInstance()->execute('
				CREATE TABLE '._DB_PREFIX_.'log_srr_email (
				`id_log_email` int(11) NOT NULL AUTO_INCREMENT,
				`id_customer` int(11) NOT NULL,
				`id_order` int(11) NOT NULL,
				`date_add` datetime NOT NULL,
				PRIMARY KEY (`id_log_email`),
				INDEX `id_order`(`id_order`),
				INDEX `date_add`(`date_add`)
			) ENGINE='._MYSQL_ENGINE_)
		) {
			return false;
		}
		return true;
	}

	public function uninstall()
	{
		foreach ($this->conf_keys as $key)
			Configuration::deleteByName($key);

		Configuration::deleteByName('SEND_REVW_REQUEST_SECURE_KEY');
		$this->unregisterHook('header');

		Db::getInstance()->execute('DROP TABLE '._DB_PREFIX_.'log_srr_email');

		return parent::uninstall();
	}

	public function getContent()
	{
		$html = '';
		/* Save settings */
		if (Tools::isSubmit('submitSendReviewRequest'))
		{
			$ok = true;
			foreach ($this->conf_keys as $c)
				if(Tools::getValue($c) !== false) // Prevent saving when URL is wrong
					$ok &= Configuration::updateValue($c, Tools::getValue($c));
			if ($ok)
				$html .= $this->displayConfirmation($this->l('Settings updated successfully'));
			else
				$html .= $this->displayError($this->l('Error occurred during settings update'));
		}
		$html .= $this->renderForm();
		$html .= $this->renderStats();

		return $html;
	}

	/* Log each sent e-mail */
	private function logEmail($id_order, $id_customer = null)
	{
		$values = [
			'id_order' => (int)$id_order,
			'date_add' => date('Y-m-d H:i:s')
		];
		if (!empty($id_customer))
			$values['id_customer'] = (int)$id_customer;
		Db::getInstance()->insert('log_srr_email', $values);
	}

	private function getLogsEmail()
	{
		static $id_list = [];
		static $executed = false;

		if (!$executed)
		{
			$query = '
			SELECT id_customer, id_order, date_add FROM '._DB_PREFIX_.'log_srr_email
			WHERE date_add >= DATE_SUB(date_add, INTERVAL '.(int)Configuration::get('SEND_REVW_REQUEST_DAYS').' DAY)';

			$results = Db::getInstance()->executeS($query);

			foreach ($results as $line)
			{
				$id_list[] = $line['id_order'];
			}
			$executed = true;
		}

		return $id_list;
	}

	public function formatProductForEmail($content)
	{
		return $content;
	}
	
	/**
	 * sendReviewRequest send mails to all customers with a specific order status
	 *
	 * @param boolean $count if set to true, will return number of customer (default : false, will send mails, no return value)
	 *
	 * @return void
	 */
	private function sendReviewRequest($count = false)
	{
		$conf = Configuration::getMultiple([
			'SEND_REVW_REQUEST_STATE',
			'SEND_REVW_REQUEST_GROUP',
			'SEND_REVW_REQUEST_NUMBER',
			'SEND_REVW_REQUEST_COLUMNS',
			'SEND_REVW_REQUEST_DAYS'
		]);

		$order_state = (int) $conf['SEND_REVW_REQUEST_STATE'];
		$number_products = (int) $conf['SEND_REVW_REQUEST_NUMBER'];
		$number_columns = (int) $conf['SEND_REVW_REQUEST_COLUMNS'];
		$customer_group = implode(',', (array) $conf['SEND_REVW_REQUEST_GROUP']);
		$days = (int) $conf['SEND_REVW_REQUEST_DAYS'];
		$url = Tools::getCurrentUrlProtocolPrefix();

		$email_logs = $this->getLogsEmail();

		$sql = '
			SELECT DISTINCT c.id_customer, c.id_shop, c.id_lang, c.firstname, c.lastname, c.email, o.id_order, oh.id_order_state
			FROM '._DB_PREFIX_.'customer c
			LEFT JOIN '._DB_PREFIX_.'customer_group cg ON (c.id_customer = cg.id_customer)
			LEFT JOIN '._DB_PREFIX_.'orders o ON (c.id_customer = o.id_customer)
			LEFT JOIN '._DB_PREFIX_.'order_history oh ON (o.id_order = oh.id_order)
			WHERE o.valid = 1
			AND DATE_FORMAT(o.date_add, \'%Y-%m-%d\') <= DATE_SUB(CURDATE(), INTERVAL '.$days.' DAY)
			AND cg.id_group IN ('.$customer_group.')
			AND oh.id_order_state = '.$order_state;

			$sql .= Shop::addSqlRestriction(Shop::SHARE_CUSTOMER, 'c');

		if (!empty($email_logs))
			$sql .= ' AND o.id_order NOT IN ('.join(',', $email_logs).') ';

		$emails = Db::getInstance()->executeS($sql);

		if ($count || !count($emails))
			return count($emails);

		foreach ($emails as $email)
		{
			if ($email['id_order_state'] == $order_state)
			{
				$order = new Order($email['id_order']);
				$id_lang = (int) $email['id_lang'];
				$products_list = '';
				$np = 0;
				$file_attachment = [];
				if ($number_columns == 2)
				{
					$products_list .= '<td><table width="100%">';
				}

				foreach($order->getProducts() as $review_product)
				{
					$np++;
					if ($np <= $number_products || $number_products == 0)
					{
						$product = new Product((int)$review_product['id_product'], false, $id_lang);
						$image = Image::getCover((int)$review_product['id_product']);
						$product_link = $this->context->link->getProductLink((int)$review_product['id_product'], $product->link_rewrite, $product->category, $product->ean13, $id_lang, (int)$order->id_shop, 0, true);
						$image_url =  $this->context->link->getImageLink($product->link_rewrite, (int)$image['id_image'], 'small_default');

						if (($np % 2) == 0 && $number_columns == 2)
						{
							$products_list .= '<td>&nbsp;</td>';
						}
						if ($number_columns == 1)
						{
							$products_list .= '<td>';
						} else {
							$products_list .= '<td><table width="100%">';
						}
						$products_list .=
							'<tr style="background-color: '.($np % 2 ? '#DDE2E6' : '#EBECEE').';">
								<td style="padding: 0.6em 0.4em;width: 25%;text-align: center;"><img src="'.$url.$image_url.'"  title="'.$product->name.'" alt="'.$product->name.'" width="100" height="100" /></td>
								<td style="padding: 0.6em 0.4em;width: 75%;text-align: left;"><strong><a href="'.$product_link.'#post_review" title="'.$this->l('Click to go to product page').'">'.$product->name.'</a></strong></td>
							</tr>';
						if ($number_columns == 1)
						{
							$products_list .= '</td>';
						} else {
							$products_list .= '</table></td>';
						}
						if (($np % 2) == 0 && $number_columns == 2)
						{
							$products_list .= '</tr><tr>';
						}
					}
				}
				if ($number_columns == 2) {
					$products_list .= '</table></td>';
				}

				$template_vars = [
					'{email}'     => $email['email'],
					'{lastname}'  => $email['lastname'],
					'{firstname}' => $email['firstname'],
					'{products}'  => $this->formatProductForEmail($products_list)
				];

				Mail::Send(
					(int)$id_lang,
					'post_review',
					Mail::l('Send your reviews', $id_lang),
					$template_vars,
					$email['email'],
					$email['firstname'].' '.$email['lastname'],
					null,
					null,
					null,
					null,
					dirname(__FILE__).'/mails/');

				$this->logEmail((int)$email['id_order'], (int)$email['id_customer']);
			}
		}
	}

	public function cronTask()
	{
		Context::getContext()->link = new Link(); //when this is call by cron context is not init
		$enabled = (int) Configuration::get('SEND_REVW_REQUEST_ENABLE');
		if ($enabled == 1)
			$this->sendReviewRequest();
	}

	public function renderStats()
	{
		$stats = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
			SELECT DATE_FORMAT(date_add, \'%Y-%m-%d\') date_stat, COUNT(id_log_email) nb
			FROM '._DB_PREFIX_.'log_srr_email
			WHERE date_add >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
			GROUP BY DATE_FORMAT(date_add, \'%Y-%m-%d\')');

		$stats_array = [];
		foreach ($stats as $stat)
		{
			$stats_array[$stat['date_stat']][1]['nb'] = (int)$stat['nb'];
		}

		$this->context->smarty->assign(['stats_array' => $stats_array]);

		return $this->display(__FILE__, 'stats.tpl');
	}

	public function hookHeader()
	{
		if(Tools::getValue('id_product'))
		{
			if (Module::isEnabled('productcomments')) {
				$html = '
				<script type="text/javascript">
					$(document).ready(function(){
						var hash = window.location.hash;
						if(hash == \'#post_review\'){
							$(\'#product_comments_block_extra\').find(\'.open-comment-form\').click();
						}
					});
				</script>';
			} elseif (Module::isEnabled('revws')) {
				$html = '
				<script type="text/javascript">
					$(document).ready(function() {
						if (window.location.hash.indexOf(\'post_review\') > -1) {
							var action = {
								type: \'TRIGGER_CREATE_REVIEW\',
								productId: id_product
							};
							window.revws ? window.revws(action) : window.revwsData.initActions = [ action ];
						}
					})
				</script>';
			}
		
			return $html;
		}
	}

	public function renderForm()
	{
		$r1 = $this->sendReviewRequest(true);

		$cron_info = '';
		if (Shop::getContext() === Shop::CONTEXT_SHOP)
			$cron_info = $this->l('Define the settings and paste the following URL in the crontab, or call it manually on a daily basis:').'<br />
								<b>'.$this->context->shop->getBaseURL().'modules/sendreviewrequest/cron.php?secure_key='.Configuration::get('SEND_REVW_REQUEST_SECURE_KEY').'</b></p>';

		$fields_form_1 = [
			'form' => [
				'legend' => [
					'title' => $this->l('Information'),
					'icon'  => 'icon-cogs',
				],
				'description' => $cron_info,
			],
		];

		$fields_form_2 = [
			'form' => [
				'legend' => [
					'title' => $this->l('E-Mails to send'),
					'icon'  => 'icon-cogs',
				],
				'input' => [
					[
						'type'    => 'switch',
						'label'   => $this->l('Enable'),
						'name'    => 'SEND_REVW_REQUEST_ENABLE',
						'hint'    => $this->l('Activate sending of review request message'),
						'is_bool' => true,
						'values'  => [
							[
								'id'      => 'active_on',
								'value'   => 1,
								'label'   => $this->l('Enabled'),
							],
							[
								'id'      => 'active_off',
								'value'   => 0,
								'label'   => $this->l('Disabled'),
							],
						],
					],
					[
						'type'    => 'select',
						'label'   => $this->l('Order state'),
						'name'    => 'SEND_REVW_REQUEST_STATE',
						'hint'    => $this->l('Select the order status you want to send email'),
						'options' => [
							'query' => array_merge(OrderState::getOrderStates((int) $this->context->language->id)),
							'id'    => 'id_order_state',
							'name'  => 'name',
						],
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Number of products'),
						'name'    => 'SEND_REVW_REQUEST_NUMBER',
						'hint'    => $this->l('Set the number of products you would like to display to customer (0 = all)'),
					],
					[
						'type'    => 'radio',
						'label'   => $this->l('Columns'),
						'name'    => 'SEND_REVW_REQUEST_COLUMNS',
						'hint'    => $this->l('Select the number of columns you\'d like the products to display in the email'),
						'values'  => [
							[
								'id'    => '1column',
								'value' => 1,
								'label' => $this->l('1 column'),
							],
							[
								'id'    => '2columns',
								'value' => 2,
								'label' => $this->l('2 columns'),
							],
						],
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Send after'),
						'name'    => 'SEND_REVW_REQUEST_DAYS',
						'hint'    => $this->l('How many days to wait before request is sent'),
						'suffix'  => $this->l('day(s)'),
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Group access'),
						'name'    => 'SEND_REVW_REQUEST_GROUP',
						'hint'    => $this->l('Enter your group ids, separated by commas')
					],
					[
						'type'    => 'desc',
						'name'    => '',
						'text'    => sprintf($this->l('Next process will send: %d e-mail(s)'), $r1),
					],
				],
				'submit' => [
					'title' => $this->l('Save'),
					'class' => 'btn btn-default pull-right',
				],
			],
		];

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$helper->identifier = $this->identifier;
		$helper->override_folder = '/';
		$helper->module = $this;
		$helper->submit_action = 'submitSendReviewRequest';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = [
			'fields_value' => $this->getConfigFieldsValues(),
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id
		];

		return $helper->generateForm([
			$fields_form_1,
			$fields_form_2
		]);
	}

	public function getConfigFieldsValues()
	{
		return [
			'SEND_REVW_REQUEST_ENABLE'  => Tools::getValue('SEND_REVW_REQUEST_ENABLE', (int) Configuration::get('SEND_REVW_REQUEST_ENABLE')),
			'SEND_REVW_REQUEST_STATE'   => Tools::getValue('SEND_REVW_REQUEST_STATE', (int) Configuration::get('SEND_REVW_REQUEST_STATE')),
			'SEND_REVW_REQUEST_GROUP'   => Tools::getValue('SEND_REVW_REQUEST_GROUP', (string) Configuration::get('SEND_REVW_REQUEST_GROUP')),
			'SEND_REVW_REQUEST_NUMBER'  => Tools::getValue('SEND_REVW_REQUEST_NUMBER', (int) Configuration::get('SEND_REVW_REQUEST_NUMBER')),
			'SEND_REVW_REQUEST_COLUMNS' => Tools::getValue('SEND_REVW_REQUEST_COLUMNS', (int) Configuration::get('SEND_REVW_REQUEST_COLUMNS')),
			'SEND_REVW_REQUEST_DAYS'    => Tools::getValue('SEND_REVW_REQUEST_DAYS', (int) Configuration::get('SEND_REVW_REQUEST_DAYS')),
		];
	}
}
