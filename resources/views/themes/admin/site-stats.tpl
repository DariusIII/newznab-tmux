 <div class="well well-sm">
<h1>{$page->title}</h1>

{if $topgrabs|count > 0}
	<h2>Top Grabbers</h2>

	<table style="width:100%;margin-top:10px;" class="data table table-striped responsive-utilities jambo-table">
		<tr>
			<th>User</th>
			<th>Grabs</th>
		</tr>

		{foreach from=$topgrabs item=result}
			<tr class="{cycle values=",alt"}">
				<td width="75%"><a href="{$smarty.const.WWW_TOP}/user-edit.php?id={$result.id}">{$result.username}</a></td>
				<td>{$result.grabs}</td>
			</tr>
		{/foreach}

	</table>

	<br/><br/>
{/if}

<h2>Signups</h2>

<table style="width:100%;margin-top:10px;" class="data table table-striped responsive-utilities jambo-tableSortable">
	<tr>
		<th>Month</th>
		<th>Signups</th>
	</tr>

	{foreach from=$usersbymonth item=result}
		{assign var="totusers" value=$totusers+$result.num}
		<tr class="{cycle values=",alt"}">
			<td width="75%">{$result.mth}</td>
			<td>{$result.num}</td>
		</tr>
	{/foreach}
	<tr><td><strong>Total</strong></td><td><strong>{$totusers}</strong></td></tr>
</table>

<br/><br/>

<h2>Users by Role</h2>

<table style="width:100%;margin-top:10px;" class="data table table-striped responsive-utilities jambo-table Sortable">
	<tr>
		<th>Role</th>
		<th>Users</th>
	</tr>

	{foreach from=$usersbyrole item=result}
		{assign var="totrusers" value=$totrusers+$result.users_count}
		<tr class="{cycle values=",alt"}">
			<td width="75%">{$result.name}</td>
			<td>{$result.users_count}</td>
		</tr>
	{/foreach}
	<tr><td><strong>Total</strong></td><td><strong>{$totrusers}</strong></td></tr>
</table>

<br/><br/>

{if $topdownloads|count > 0}
	<h2>Top Downloads</h2>

	<table style="width:100%;margin-top:10px;" class="data table table-striped responsive-utilities jambo-table">
		<tr>
			<th>Release</th>
			<th>Grabs</th>
			<th>Days Ago</th>
		</tr>

		{foreach from=$topdownloads item=result}
			<tr class="{cycle values=",alt"}">
				<td width="75%"><a href="{$smarty.const.WWW_TOP}/../details/{$result.guid}">{$result.searchname|escape:"htmlall"|replace:".":" "}</a>
				{if isset($isadmin)}<a href="{$smarty.const.WWW_TOP}/release-edit.php?id={$result.id}">[Edit]</a>{/if}</td>
				<td>{$result.grabs}</td>
				<td>{$result.adddate|timeago}</td>
			</tr>
		{/foreach}

	</table>

	<br/><br/>
{/if}

<h2>Releases Added In Last 7 Days</h2>

<table style="width:100%;margin-top:10px;" class="data table table-striped responsive-utilities jambo-table">
	<tr>
		<th>Category</th>
		<th>Releases</th>
	</tr>

	{foreach from=$recent item=result}
		<tr class="{cycle values=",alt"}">
			<td width="75%">{$result->title}</td>
			<td>{$result.count}</td>
		</tr>
	{/foreach}

</table>

<br/><br/>

{if $topcomments|count > 0}
	<h2>Top Comments</h2>

	<table style="width:100%;margin-top:10px;" class="data table table-striped responsive-utilities jambo-table">
		<tr>
			<th>Release</th>
			<th>Comments</th>
			<th>Days Ago</th>
		</tr>

		{foreach from=$topcomments item=result}
			<tr class="{cycle values=",alt"}">
				<td width="75%"><a href="{$smarty.const.WWW_TOP}/../details/{$result.guid}/#comments">{$result.searchname|escape:"htmlall"|replace:".":" "}</a></td>
				<td>{$result.comments}</td>
				<td>{$result.adddate|timeago}</td>
			</tr>
		{/foreach}

	</table>
{/if}
	 </div>