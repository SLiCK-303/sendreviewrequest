<?php
/**
 * Copyright (C) 2018 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @package   sendreviewrequest
 * @author    slick-303 <slick_303@hotmail.com>
 * @copyright 2017-2018 SLiCK-303
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

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
		$this->tab = 'emailing';
		$this->version = '2.0.2';
		$this->author = 'SLiCK-303';
		$this->need_instance = 0;

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('Send Review Request');
		$this->description = $this->l('Send a review request after a defined order state.');

		$this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
	}

	public function install()
	{
		if (!parent::install() ||
			!$this->registerHook('header') ||
			!$this->registerHook('actionOrderStatusPostUpdate') ||
			!Configuration::updateValue('SENDREVIEWREQUEST', 5) ||
			!Configuration::updateValue('SENDREVIEWREQUESTNBR', 5) ||
			!Configuration::updateValue('SENDREVIEWREQUESTCOL', 1)
		) {
			return false;
		}
		return true;
	}

	public function uninstall()
	{
		if (!parent::uninstall() ||
			!Configuration::deleteByName('SENDREVIEWREQUEST') ||
			!Configuration::deleteByName('SENDREVIEWREQUESTNBR') ||
			!Configuration::deleteByName('SENDREVIEWREQUESTCOL') ||
			!$this->unregisterHook('header') ||
			!$this->unregisterHook('actionOrderStatusPostUpdate')
		) {
			return false;
		}

		return true;
	}

	public function getContent()
	{
		$output = '';
		$errors = [];
		if (Tools::isSubmit('submitSendReviewRequest'))
		{
			$nbr = Tools::getValue('SENDREVIEWREQUESTNBR');
			if (!Validate::isInt($nbr) || $nbr < 0)
				$errors[] = $this->l('The number of products is invalid. Please enter a positive number.');

			$state = Tools::getValue('SENDREVIEWREQUEST');
			if (!Validate::isInt($state) || $state <= 0)
				$errors[] = $this->l('The order state is invalid. Please choose an existing order state.');

			$col = Tools::getValue('SENDREVIEWREQUESTCOL');
			if (!Validate::isInt($col) || $col <= 0)
				$errors[] = $this->l('The columns setting is invalid. Please choose an existing column number.');

			if (isset($errors) && count($errors))
				$output = $this->displayError(implode('<br />', $errors));
			else
			{
				Configuration::updateValue('SENDREVIEWREQUESTNBR', (int)$nbr);
				Configuration::updateValue('SENDREVIEWREQUEST', (int)$state);
				Configuration::updateValue('SENDREVIEWREQUESTCOL', (int)$col);
				$output = $this->displayConfirmation($this->l('Settings updated.'));
			}
		}

		return $output.$this->renderForm();
	}

	protected function getProducts($order)
	{
		$products = $order->getProducts();
		return $products;
	}

	public function hookActionOrderStatusPostUpdate($params)
	{
		$id_order_state = (int) Tools::getValue('id_order_state');
		$order_state = (int) Configuration::get('SENDREVIEWREQUEST');
		$number_products = (int) Configuration::get('SENDREVIEWREQUESTNBR');
		$number_columns = (int) Configuration::get('SENDREVIEWREQUESTCOL');
		$shop_email = (string) Configuration::get('PS_SHOP_EMAIL');
		$shop_name = (string) Configuration::get('PS_SHOP_NAME');

		if($id_order_state == $order_state)
		{
			if ($context == null) {
				$context = Context::getContext();
			}
			$order = new Order($params['id_order']);
			$id_lang = $order->id_lang;
			$products_list = '';
			$np = 0;
			$customer = new Customer($order->id_customer);
			if (Validate::isLoadedObject($customer)) {
				$firstname = $customer->firstname;
				$lastname = $customer->lastname;
			} else {
				$firstname = '';
				$lastname = '';
			}
			$file_attachment = [];
			if ($number_columns == 2) {
				$products_list .= '<td><table width="100%">';
			}
			foreach($this->getProducts($order) as $review_product)
			{
				$np++;
				if ($np <= $number_products || $number_products == 0) {
					$product = new Product((int)$review_product['id_product'], true, (int)$id_lang);
					$image = Image::getCover((int)$review_product['id_product']);
					$product_link = $context->link->getProductLink((int)$review_product['id_product'], $product->link_rewrite, $product->category, $product->ean13, $id_lang, (int)$order->id_shop, 0, true);
					$image_url =  $context->link->getImageLink($product->link_rewrite, $image['id_image'], 'small_default');
					$file_attachment .= ['content' => $image_url, 'name' => $product->name, 'mime' => 'image/jpg'];
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
							<td style="padding: 0.6em 0.4em;width: 25%;text-align: center;"><img src="'.$image_url.'" title="'.$product->name.'" alt="'.$product->name.'" /></td>
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

			$template_vars = [
				'{firstname}' => $firstname,
				'{lastname}'  => $lastname,
				'{products}'  => $this->formatProductForEmail($products_list)
			];

			if (Validate::isEmail($customer->email))
				Mail::Send(
					(int)$id_lang,
					'post_review',
					sprintf(Mail::l('Send your reviews', (int)$id_lang)),
					$template_vars,
					($customer->email ? $customer->email : null),
					($firstname ? $firstname.' '.$lastname : null),
					$shop_email,
					$shop_name,
					$file_attachment,
					null,
					dirname(__FILE__).'/mails/',
					false,
					(int)$order->id_shop
				);
		}
	}

	public function formatProductForEmail($content)
	{
		return $content;
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
		$fields_form = [
			'form' => [
				'legend' => [
					'title' => $this->l('Settings'),
					'icon' => 'icon-cogs'
				],
				'input' => [
					[
						'type' => 'text',
						'label' => $this->l('Number of products'),
						'name' => 'SENDREVIEWREQUESTNBR',
						'class' => 'fixed-width-xs',
						'desc' => $this->l('Set the number of products that you would like to display to customer (0 = all).'),
					],
					[
						'type'    => 'select',
						'label'   => $this->l('Order state'),
						'name'    => 'SENDREVIEWREQUEST',
						'options' => [
							'query' => array_merge(OrderState::getOrderStates((int) $this->context->language->id)),
							'id'    => 'id_order_state',
							'name'  => 'name',
						],
						'desc' => $this->l('Select the order status you want to send email (default: Delivered).'),
					],
                    [
                        'type'   => 'radio',
                        'label'  => $this->l('Columns'),
                        'name'   => 'SENDREVIEWREQUESTCOL',
                        'values' => [
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
						'desc' => $this->l('Select the number of columns you\'d like products to display in email (default: 1).'),
                    ],
				],
				'submit' => [
					'title' => $this->l('Save'),
				],
			],
		];

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = [];
//		$helper->id = (int)Tools::getValue('id_order_state');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitSendReviewRequest';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = [
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		];

		return $helper->generateForm([$fields_form]);
	}

	public function getConfigFieldsValues()
	{
		return [
			'SENDREVIEWREQUEST' => Tools::getValue('SENDREVIEWREQUEST', (int)Configuration::get('SENDREVIEWREQUEST')),
			'SENDREVIEWREQUESTNBR' => Tools::getValue('SENDREVIEWREQUESTNBR', (int)Configuration::get('SENDREVIEWREQUESTNBR')),
			'SENDREVIEWREQUESTCOL' => Tools::getValue('SENDREVIEWREQUESTCOL', (int)Configuration::get('SENDREVIEWREQUESTCOL')),
		];
	}
}
