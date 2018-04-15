{**
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
**}

<div class="panel" id="fieldset_4">
    <h3><i class="icon-clipboard"></i> {l s='Logs' mod='sendreviewrequest'}</h3>
	<p>{l s='Logs for the last 30 days:' mod='sendreviewrequest'}</p>
	<table class="table">
		<tr>
			<th style="width: 75px;">{l s='Date' mod='sendreviewrequest'}</th>
			<th colspan="3" style="text-align: center;">{l s='Review requests sent' mod='sendreviewrequest'}</th>
		</tr>
		{foreach from=$stats_array key='date' item='stats'}
		<tr>
			<td class="center">{$date|escape:'htmlall':'UTF-8'}</td>
			{foreach from=$stats key='key' item='val'}
				<td class="center">{$val.nb|escape:'htmlall':'UTF-8'}</td>
			{/foreach}	
		</tr>
		{foreachelse}
			<tr>
				<td colspan="4" style="font-weight: bold;">{l s='No log information at this time.' mod='sendreviewrequest'}</td>
			</tr>
		{/foreach}
	</table>
</div>
