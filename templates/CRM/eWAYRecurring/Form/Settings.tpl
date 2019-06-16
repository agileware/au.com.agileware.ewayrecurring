<table class="form-layout advance-search-form">
    {foreach from=$elementNames item=element}
        {assign var="elementName" value=$element.name}
        <tr>
            <td class="label">{$form.$elementName.label}</td>
            <td>
                {$form.$elementName.html}
                <br>
                <span class="description">
            {$element.description}
        </span>
            </td>
        </tr>
    {/foreach}
</table>

<div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
{literal}
    <style type="text/css">
        .advance-search-form .crm-select2 {
            width: 300px !important;
        }
    </style>
{/literal}