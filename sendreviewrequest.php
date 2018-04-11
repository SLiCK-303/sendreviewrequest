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

class SendReviewRequest extends Module
{
	public function __construct()
	{
		$this->name = 'sendreviewrequest';
		$this->version = '3.2.6';
		$this->author = 'SLiCK-303';
		$this->tab = 'emailing';
		$this->need_instance = 0;

		$this->conf_keys = [
			'SEND_REVW_REQUEST_STATE',
			'SEND_REVW_REQUEST_GROUP',
			'SEND_REVW_REQUEST_NUMBER',
			'SEND_REVW_REQUEST_COLUMNS',
			'SEND_REVW_REQUEST_DAYS',
			'SEND_REVW_REQUEST_OLD',
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
			!Configuration::updateValue('SEND_REVW_REQUEST_STATE', '5,4') ||
			!Configuration::updateValue('SEND_REVW_REQUEST_GROUP', '3') ||
			!Configuration::updateValue('SEND_REVW_REQUEST_NUMBER', 8) ||
			!Configuration::updateValue('SEND_REVW_REQUEST_COLUMNS', 2) ||
			!Configuration::updateValue('SEND_REVW_REQUEST_DAYS', 7) ||
			!Configuration::updateValue('SEND_REVW_REQUEST_OLD', 30) ||
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
		foreach ($this->conf_keys as $key) {
			Configuration::deleteByName($key);
		}

		Configuration::deleteByName('SEND_REVW_REQUEST_SECURE_KEY');

		$this->unregisterHook('header');

		Db::getInstance()->execute('DROP TABLE '._DB_PREFIX_.'log_srr_email');

		return parent::uninstall();
	}

	public function getContent()
	{
		$html = '';
		/* Save settings */
		if (Tools::isSubmit('submitSendReviewRequest')) {
			$ok = true;
			foreach ($this->conf_keys as $c) {
				if (Tools::getValue($c) !== false) { // Prevent saving when URL is wrong
					$ok &= Configuration::updateValue($c, Tools::getValue($c));
				}
			}

			// Handling Order States
			$orderStates = OrderState::getOrderStates($this->context->language->id);
			$order_state_selected = [];
			foreach ($orderStates as $orderState) {
				$id_order_state = $orderState['id_order_state'];
				if (Tools::isSubmit('SEND_REVW_REQUEST_STATE_'.$id_order_state)) {
					$order_state_selected[] = $id_order_state;
				}
			}
			if (empty($order_state_selected[0])) {
				$ok = false;
			} else {
				$ok &= Configuration::updateValue('SEND_REVW_REQUEST_STATE', implode(',', $order_state_selected));
			}

			// Handling Groups
			$groups = Group::getGroups($this->context->language->id);
			$group_selected = [];
			foreach ($groups as $group) {
				$id_group = $group['id_group'];
				if (Tools::isSubmit('SEND_REVW_REQUEST_GROUP_'.$id_group)) {
					$group_selected[] = $id_group;
				}
			}
			if (empty($group_selected[0])) {
				$ok = false;
			} else {
				$ok &= Configuration::updateValue('SEND_REVW_REQUEST_GROUP', implode(',', $group_selected));
			}

			if ($ok) {
				$html .= $this->displayConfirmation($this->l('Settings updated successfully'));
			} else {
				$html .= $this->displayError($this->l('Error occurred during settings update'));
			}
		}

		$html .= $this->renderForm();
		$html .= $this->renderStats();

		return $html;
	}

	private function logEmail($id_order, $id_customer = null)
	{
		$values = [
			'id_order' => (int)$id_order,
			'date_add' => date('Y-m-d H:i:s')
		];

		if (!empty($id_customer)) {
			$values['id_customer'] = (int)$id_customer;
		}

		Db::getInstance()->insert('log_srr_email', $values);
	}

	private function getLogsEmail()
	{
		static $id_list = [];
		static $executed = false;

		if (!$executed) {
			$query = '
				SELECT id_customer, id_order, date_add FROM '._DB_PREFIX_.'log_srr_email
				WHERE date_add >= DATE_SUB(date_add, INTERVAL '.(int)Configuration::get('SEND_REVW_REQUEST_DAYS').' DAY)
			';

			$results = Db::getInstance()->executeS($query);

			foreach ($results as $line) {
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
	
	private function sendReviewRequest($count = false)
	{
		$conf = Configuration::getMultiple([
			'SEND_REVW_REQUEST_STATE',
			'SEND_REVW_REQUEST_GROUP',
			'SEND_REVW_REQUEST_NUMBER',
			'SEND_REVW_REQUEST_COLUMNS',
			'SEND_REVW_REQUEST_DAYS',
			'SEND_REVW_REQUEST_OLD',
		]);

		$order_state = implode(',', (array) $conf['SEND_REVW_REQUEST_STATE']);
		$customer_group = implode(',', (array) $conf['SEND_REVW_REQUEST_GROUP']);
		$number_products = (int) $conf['SEND_REVW_REQUEST_NUMBER'];
		$number_columns = (int) $conf['SEND_REVW_REQUEST_COLUMNS'];
		$days = (int) $conf['SEND_REVW_REQUEST_DAYS'];
		$old = (int) $conf['SEND_REVW_REQUEST_OLD'];
		$url = Tools::getCurrentUrlProtocolPrefix();

		$email_logs = $this->getLogsEmail();

		$sql = '
			SELECT c.id_customer, c.id_shop, c.id_lang, c.firstname, c.lastname, c.email, o.id_order, o.current_state
			FROM '._DB_PREFIX_.'customer c
			LEFT JOIN '._DB_PREFIX_.'customer_group cg ON c.id_customer = cg.id_customer
			LEFT JOIN '._DB_PREFIX_.'orders o ON c.id_customer = o.id_customer
			WHERE o.valid = 1
			AND cg.id_group IN ('.$customer_group.')
			AND o.current_state IN ('.$order_state.')
		';

		$sql .= Shop::addSqlRestriction(Shop::SHARE_CUSTOMER, 'c');

		if (!empty($days)) {
			$sql .= ' AND DATE_FORMAT(o.date_upd, \'%Y-%m-%d\') < DATE_SUB(CURDATE(), INTERVAL '.$days.' DAY)';
		}

		if (!empty($old)) {
			$sql .= ' AND DATE_FORMAT(o.date_upd, \'%Y-%m-%d\') > DATE_SUB(CURDATE(), INTERVAL '.$old.' DAY)';
		}

		if (!empty($email_logs)) {
			$sql .= ' AND o.id_order NOT IN ('.join(',', $email_logs).')';
		}

		$emails = Db::getInstance()->executeS($sql);

		if ($count || !count($emails)) {
			return count($emails);
		}

		foreach ($emails as $email) {
			if (strpos($order_state, $email['current_state']) !== FALSE) {
				$order = new Order($email['id_order']);
				$id_lang = (int) $email['id_lang'];
				$products_list = '';
				$np = 0;
				$file_attachment = [];
				if ($number_columns == 2) {
					$products_list .= '<td><table width="100%">';
				}

				foreach($order->getProducts() as $review_product) {
					$np++;
					if ($np <= $number_products || $number_products == 0) {
						$product = new Product((int)$review_product['id_product'], false, $id_lang);
						$image = Image::getCover((int)$review_product['id_product']);
						$product_link = $this->context->link->getProductLink((int)$review_product['id_product'], $product->link_rewrite, $product->category, $product->ean13, $id_lang, (int)$order->id_shop, 0, true);
						$image_url =  $this->context->link->getImageLink($product->link_rewrite, (int)$image['id_image'], 'small_default');

						if (($np % 2) == 0 && $number_columns == 2) {
							$products_list .= '<td>&nbsp;</td>';
						}
						if ($number_columns == 1) {
							$products_list .= '<td>';
						} else {
							$products_list .= '<td><table width="100%">';
						}
						$products_list .=
							'<tr style="background-color: '.($np % 2 ? '#DDE2E6' : '#EBECEE').';">
								<td style="padding: 0.6em 0.4em;width: 25%;text-align: center;"><img src="'.$url.$image_url.'"  title="'.$product->name.'" alt="'.$product->name.'" width="100" height="100" /></td>
								<td style="padding: 0.6em 0.4em;width: 75%;text-align: left;"><strong><a href="'.$product_link.'#post_review" title="'.$this->l('Click to go to product page').'">'.$product->name.'</a></strong></td>
							</tr>';
						if ($number_columns == 1) {
							$products_list .= '</td>';
						} else {
							$products_list .= '</table></td>';
						}
						if (($np % 2) == 0 && $number_columns == 2) {
							$products_list .= '</tr><tr>';
						}
					}
				}
				if ($number_columns == 2) {
					$products_list .= '</table></td>';
				}

				// Trigger the Krona Action
				if (Module::isEnabled('genzo_krona')) {
					$gk_params = [
						'module_name' => 'revws',
						'action_name' => 'review_created',
						'id_customer' => (int)$email['id_customer'],
					];
					$action = Hook::exec('displayKronaActionPoints', $gk_params, null, true, false);

					$template_vars = [
						'{email}'     => $email['email'],
						'{lastname}'  => $email['lastname'],
						'{firstname}' => $email['firstname'],
						'{products}'  => $this->formatProductForEmail($products_list),
						'{points}'    => $action['genzo_krona']['points'],
					];

					Mail::Send(
						(int)$id_lang,
						'post_review_krona',
						Mail::l('Send your reviews', $id_lang),
						$template_vars,
						$email['email'],
						$email['firstname'].' '.$email['lastname'],
						null,
						null,
						null,
						null,
						dirname(__FILE__).'/mails/');
				} else {
					$template_vars = [
						'{email}'     => $email['email'],
						'{lastname}'  => $email['lastname'],
						'{firstname}' => $email['firstname'],
						'{products}'  => $this->formatProductForEmail($products_list),
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
				}


				$this->logEmail((int)$email['id_order'], (int)$email['id_customer']);
			}
		}
	}

	public function cronTask()
	{
		Context::getContext()->link = new Link(); //when this is call by cron context is not init
		$this->sendReviewRequest();
	}

	public function renderStats()
	{
		$stats = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
			SELECT DATE_FORMAT(date_add, \'%Y-%m-%d\') date_stat, COUNT(id_log_email) nb
			FROM '._DB_PREFIX_.'log_srr_email
			WHERE date_add >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
			GROUP BY DATE_FORMAT(date_add, \'%Y-%m-%d\')
		');

		$stats_array = [];
		foreach ($stats as $stat) {
			$stats_array[$stat['date_stat']][1]['nb'] = (int)$stat['nb'];
		}

		$this->context->smarty->assign(['stats_array' => $stats_array]);

		return $this->display(__FILE__, 'stats.tpl');
	}

	public function hookHeader()
	{
		if(Tools::getValue('id_product')) {
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
		$id_lang = $this->context->language->id;
		$orderStates = OrderState::getOrderStates($id_lang);

		$groups = Group::getGroups($id_lang);
		$visitorGroup = Configuration::get('PS_UNIDENTIFIED_GROUP');
		if (Configuration::get('PS_GUEST_CHECKOUT_ENABLED')) {
			$guestGroup = '';
		} else {
			$guestGroup = Configuration::get('PS_GUEST_GROUP');
		}
		foreach ($groups as $key => $g) {
			if (in_array($g['id_group'], [$visitorGroup, $guestGroup])) {
				unset($groups[$key]);
			}
		}

		$cron_info = '';
		if (Shop::getContext() === Shop::CONTEXT_SHOP) {
			$cron_info = $this->l('Define the settings and paste the following URL in the crontab, or call it manually on a daily basis:').'<br /><b>'.$this->context->shop->getBaseURL().'modules/sendreviewrequest/cron.php?secure_key='.Configuration::get('SEND_REVW_REQUEST_SECURE_KEY').'</b>';
		}

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
						'type'     => 'checkbox',
						'label'    => $this->l('Order State'),
						'name'     => 'SEND_REVW_REQUEST_STATE',
						'hint'     => $this->l('Orders need to be in this state/s for the email to be sent'),
						'multiple' => true,
						'values'   => [
							'query' => $orderStates,
							'id'    => 'id_order_state',
							'name'  => 'name',
						],
						'expand'   => (count($orderStates) > 10) ? [
							'print_total' => count($orderStates),
							'default'     => 'show',
							'show'        => ['text' => $this->l('Show'), 'icon' => 'plus-sign-alt'],
							'hide'        => ['text' => $this->l('Hide'), 'icon' => 'minus-sign-alt'],
						] : null,
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Number of products'),
						'name'    => 'SEND_REVW_REQUEST_NUMBER',
						'hint'    => $this->l('Set the number of products you would like to display in the email (0 = all)'),
					],
					[
						'type'    => 'radio',
						'label'   => $this->l('Columns'),
						'name'    => 'SEND_REVW_REQUEST_COLUMNS',
						'hint'    => $this->l('Select the number of columns of products to display in the email'),
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
						'hint'    => $this->l('Send request AFTER order is this old (0 = now)'),
						'suffix'  => $this->l('day(s)'),
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Send before'),
						'name'    => 'SEND_REVW_REQUEST_OLD',
						'hint'    => $this->l('Send request BEFORE order is this old (0 = forever)'),
						'suffix'  => $this->l('day(s)'),
					],
					[
						'type'     => 'checkbox',
						'label'    => $this->l('Group access'),
						'name'     => 'SEND_REVW_REQUEST_GROUP',
						'hint'     => $this->l('Select the group/s you want to send emails to'),
						'multiple' => true,
						'values'   => [
							'query' => $groups,
							'id'    => 'id_group',
							'name'  => 'name',
						],
						'expand'   => (count($groups) > 3) ? [
							'print_total' => count($groups),
							'default'     => 'show',
							'show'        => ['text' => $this->l('Show'), 'icon' => 'plus-sign-alt'],
							'hide'        => ['text' => $this->l('Hide'), 'icon' => 'minus-sign-alt'],
						] : null,
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

		$vars['SEND_REVW_REQUEST_STATE'] = (array) Configuration::get('SEND_REVW_REQUEST_STATE');
		$vars['SEND_REVW_REQUEST_GROUP'] = (array) Configuration::get('SEND_REVW_REQUEST_GROUP');
		$vars['SEND_REVW_REQUEST_NUMBER'] = (int) Configuration::get('SEND_REVW_REQUEST_NUMBER');
		$vars['SEND_REVW_REQUEST_COLUMNS'] = (int) Configuration::get('SEND_REVW_REQUEST_COLUMNS');
		$vars['SEND_REVW_REQUEST_DAYS'] = (int) Configuration::get('SEND_REVW_REQUEST_DAYS');
		$vars['SEND_REVW_REQUEST_OLD'] = (int) Configuration::get('SEND_REVW_REQUEST_OLD');

		// Order Status
		$order_state = explode(',', Configuration::get('SEND_REVW_REQUEST_STATE'));
		foreach ($order_state as $id) {
		    $vars['SEND_REVW_REQUEST_STATE_'.$id] = true;
		}

		// Groups
		$group = explode(',', Configuration::get('SEND_REVW_REQUEST_GROUP'));
		foreach ($group as $id) {
		    $vars['SEND_REVW_REQUEST_GROUP_'.$id] = true;
		}

		$helper->tpl_vars = [
			'fields_value' => $vars,
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id,
		];

		return $helper->generateForm([
			$fields_form_1,
			$fields_form_2,
		]);
	}

}
