{**
 * Copyright (C) 2019 SLiCK-303
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 *
 * @package    sendreviewrequest
 * @author     SLiCK-303 <slick_303@hotmail.com>
 * @copyright  2019 SLiCK-303
 * @license    Academic Free License (AFL 3.0)
**}

<div class="panel" id="fieldset_4">
    <h3><i class="icon-clipboard"></i> {l s='Logs' mod='sendreviewrequest'}</h3>
	<p>{l s='Logs for the last 30 days:' mod='sendreviewrequest'}</p>
	<ul style="font-size: 10px; font-weight: bold;">
		<li>{l s='Date = Date of sent e-mails' mod='sendreviewrequest'}</li>
		<li>{l s='Sent = Number of sent e-mails' mod='sendreviewrequest'}</li>
	</ul>
	<table class="table">
		<tr>
			<th class="center" style="width: 75px;">{l s="Date" mod='sendreviewrequest'}</th>
			<th class="center" style="width: 99%;">{l s="Sent" mod='sendreviewrequest'}</th>
		</tr>
		{foreach from=$stats_array key='date' item='stats'}
		<tr>
			<td class="center" style="width: 75px; white-space: nowrap;">{$date|escape:'htmlall':'UTF-8'}</td>
			{foreach from=$stats key='key' item='val'}
				<td class="center" style="width: 99%;">{$val.nb|escape:'htmlall':'UTF-8'}</td>
			{/foreach}	
		</tr>
		{foreachelse}
			<tr>
				<td colspan="2" class="center"><b>{l s='No log information at this time.' mod='sendreviewrequest'}</b></td>
			</tr>
		{/foreach}
	</table>
</div>
