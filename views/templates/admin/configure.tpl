{**
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
{if $cu_doc_url}
  <div class="alert alert-info" style="display:flex;align-items:center;gap:12px">
    <i class="icon-book"></i>
    <a class="btn btn-default btn-sm" href="{$cu_doc_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener"><i class="icon-file-pdf-o"></i> {l s='User guide (PDF)' d='Modules.Cleanurls.Admin'}</a>
    <span class="text-muted">{l s='Other languages:' d='Modules.Cleanurls.Admin'}
      {foreach $cu_docs as $iso => $url}<a href="{$url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$iso|upper|escape:'html':'UTF-8'}</a>{if !$url@last} · {/if}{/foreach}</span>
  </div>
{/if}
<ul class="nav nav-tabs" style="margin-bottom:16px">
  <li class="{if $cu_tab == 'settings'}active{/if}"><a href="{$cu_base|escape:'html':'UTF-8'}&cu_tab=settings"><i class="icon-cogs"></i> {l s='URL settings' d='Modules.Cleanurls.Admin'}</a></li>
  <li class="{if $cu_tab == 'redirects'}active{/if}"><a href="{$cu_base|escape:'html':'UTF-8'}&cu_tab=redirects"><i class="icon-random"></i> {l s='Redirects' d='Modules.Cleanurls.Admin'} <span class="badge">{$cu_redirects|count}</span></a></li>
  <li class="{if $cu_tab == 'notfound'}active{/if}"><a href="{$cu_base|escape:'html':'UTF-8'}&cu_tab=notfound"><i class="icon-warning"></i> {l s='404 monitor' d='Modules.Cleanurls.Admin'} <span class="badge">{$cu_404_count|intval}</span></a></li>
  <li class="{if $cu_tab == 'duplicates'}active{/if}"><a href="{$cu_base|escape:'html':'UTF-8'}&cu_tab=duplicates"><i class="icon-copy"></i> {l s='Duplicate URLs' d='Modules.Cleanurls.Admin'} <span class="badge{if $cu_dup_count} badge-danger{/if}">{$cu_dup_count|intval}</span></a></li>
  <li class="{if $cu_tab == 'sitemap'}active{/if}"><a href="{$cu_base|escape:'html':'UTF-8'}&cu_tab=sitemap"><i class="icon-sitemap"></i> {l s='Sitemap' d='Modules.Cleanurls.Admin'}</a></li>
</ul>

{if $cu_tab == 'settings'}
  {if $cu_examples}
    <div class="panel">
      <div class="panel-heading"><i class="icon-eye"></i> {l s='Before and after' d='Modules.Cleanurls.Admin'}</div>
      <table class="table">
        <thead><tr><th>{l s='Page type' d='Modules.Cleanurls.Admin'}</th><th>{l s='Old URL (redirects with a 301)' d='Modules.Cleanurls.Admin'}</th><th>{l s='New URL' d='Modules.Cleanurls.Admin'}</th></tr></thead>
        <tbody>
          {foreach $cu_examples as $ex}
            <tr>
              <td><strong>{$ex.label|escape:'html':'UTF-8'}</strong></td>
              <td><code style="color:#b91c1c">{$ex.before|escape:'html':'UTF-8'}</code></td>
              <td>{if $ex.after}<a href="{$ex.after|escape:'html':'UTF-8'}" target="_blank" rel="noopener"><code style="color:#15803d">{$ex.after|escape:'html':'UTF-8'}</code></a>{else}<span class="text-muted">{l s='Turned off' d='Modules.Cleanurls.Admin'}</span>{/if}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
      <p class="help-block">{l s='%count% renamed URLs are remembered and redirect to their new address.' sprintf=['%count%' => $cu_history_count] d='Modules.Cleanurls.Admin'}</p>
    </div>
  {/if}
  {$cu_settings_form nofilter}
{/if}

{if $cu_tab == 'redirects'}
  <div class="panel">
    <div class="panel-heading"><i class="icon-plus"></i> {l s='Add a redirect' d='Modules.Cleanurls.Admin'}</div>
    <form method="post" action="{$cu_base|escape:'html':'UTF-8'}&cu_tab=redirects" class="form-horizontal">
      <div class="form-group">
        <label class="control-label col-lg-2">{l s='Old URL' d='Modules.Cleanurls.Admin'}</label>
        <div class="col-lg-8">
          <div class="input-group"><span class="input-group-addon">{$cu_shop_base|escape:'html':'UTF-8'}</span>
            <input type="text" name="cu_source" value="{$cu_prefill|escape:'html':'UTF-8'}" placeholder="old-page.html" required></div>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-2">{l s='New URL' d='Modules.Cleanurls.Admin'}</label>
        <div class="col-lg-8"><input type="text" name="cu_target" placeholder="women  {l s='or' d='Modules.Cleanurls.Admin'}  https://example.com/page" required></div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-2">{l s='Match' d='Modules.Cleanurls.Admin'}</label>
        <div class="col-lg-3">
          <select name="cu_match">
            <option value="exact">{l s='Exact URL' d='Modules.Cleanurls.Admin'}</option>
            <option value="prefix">{l s='Starts with' d='Modules.Cleanurls.Admin'}</option>
            <option value="regex">{l s='Regular expression ($1 in the new URL)' d='Modules.Cleanurls.Admin'}</option>
          </select>
        </div>
        <label class="control-label col-lg-1">{l s='Type' d='Modules.Cleanurls.Admin'}</label>
        <div class="col-lg-2">
          <select name="cu_code">
            {foreach $cu_codes as $code}<option value="{$code|intval}">{$code|intval}{if $code == 301} ({l s='permanent' d='Modules.Cleanurls.Admin'}){elseif $code == 302} ({l s='temporary' d='Modules.Cleanurls.Admin'}){/if}</option>{/foreach}
          </select>
        </div>
      </div>
      <div class="panel-footer"><button type="submit" name="submitCleanurlsRedirect" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Add redirect' d='Modules.Cleanurls.Admin'}</button></div>
    </form>
  </div>
  <div class="panel">
    <div class="panel-heading"><i class="icon-list"></i> {l s='Redirects' d='Modules.Cleanurls.Admin'}</div>
    {if $cu_redirects}
      <table class="table">
        <thead><tr><th>{l s='Old URL' d='Modules.Cleanurls.Admin'}</th><th>{l s='New URL' d='Modules.Cleanurls.Admin'}</th><th>{l s='Match' d='Modules.Cleanurls.Admin'}</th><th>{l s='Type' d='Modules.Cleanurls.Admin'}</th><th>{l s='Hits' d='Modules.Cleanurls.Admin'}</th><th>{l s='Last hit' d='Modules.Cleanurls.Admin'}</th><th></th></tr></thead>
        <tbody>
          {foreach $cu_redirects as $r}
            <tr>
              <td><code>/{$r.source|escape:'html':'UTF-8'}</code></td>
              <td><code>{$r.target|escape:'html':'UTF-8'}</code></td>
              <td>{$r.match_type|escape:'html':'UTF-8'}</td>
              <td><span class="badge">{$r.code|intval}</span></td>
              <td>{$r.hits|intval}</td>
              <td>{$r.last_hit|escape:'html':'UTF-8'}</td>
              <td class="text-right"><a class="btn btn-default btn-xs" href="{$cu_base|escape:'html':'UTF-8'}&cu_tab=redirects&cu_delete_redirect={$r.id_redirect|intval}" onclick="return confirm('{l s='Delete this redirect?' d='Modules.Cleanurls.Admin' js=1}');"><i class="icon-trash"></i></a></td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <p class="text-muted">{l s='No redirects yet. Old URLs with IDs are already redirected automatically; add your own here for moved or deleted pages.' d='Modules.Cleanurls.Admin'}</p>
    {/if}
  </div>
{/if}

{if $cu_tab == 'notfound'}
  <div class="panel">
    <div class="panel-heading"><i class="icon-warning"></i> {l s='Pages not found (404)' d='Modules.Cleanurls.Admin'}
      <span class="panel-heading-action"><form method="post" action="{$cu_base|escape:'html':'UTF-8'}&cu_tab=notfound" style="display:inline"><button name="cu_clear_404" class="btn btn-default btn-sm" onclick="return confirm('{l s='Clear the whole 404 log?' d='Modules.Cleanurls.Admin' js=1}');"><i class="icon-trash"></i> {l s='Clear log' d='Modules.Cleanurls.Admin'}</button></form></span>
    </div>
    {if $cu_404}
      <table class="table">
        <thead><tr><th>{l s='URL' d='Modules.Cleanurls.Admin'}</th><th>{l s='Hits' d='Modules.Cleanurls.Admin'}</th><th>{l s='Last seen' d='Modules.Cleanurls.Admin'}</th><th>{l s='Came from' d='Modules.Cleanurls.Admin'}</th><th></th></tr></thead>
        <tbody>
          {foreach $cu_404 as $n}
            <tr>
              <td><code>/{$n.path|escape:'html':'UTF-8'}</code></td>
              <td><strong>{$n.hits|intval}</strong></td>
              <td>{$n.date_upd|escape:'html':'UTF-8'}</td>
              <td class="text-muted">{$n.referer|truncate:60|escape:'html':'UTF-8'}</td>
              <td class="text-right"><a class="btn btn-primary btn-xs" href="{$cu_base|escape:'html':'UTF-8'}&cu_tab=redirects&cu_prefill={$n.path|escape:'url'}"><i class="icon-random"></i> {l s='Redirect' d='Modules.Cleanurls.Admin'}</a></td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <p class="text-muted">{l s='No 404 errors logged yet.' d='Modules.Cleanurls.Admin'}</p>
    {/if}
  </div>
{/if}

{if $cu_tab == 'duplicates'}
  <div class="panel">
    <div class="panel-heading"><i class="icon-copy"></i> {l s='Duplicate URLs' d='Modules.Cleanurls.Admin'}</div>
    <p>{l s='Without IDs, two pages with the same friendly URL would share one address. The first one keeps it; the others need a different one.' d='Modules.Cleanurls.Admin'}</p>
    {if $cu_duplicates}
      <table class="table">
        <thead><tr><th>{l s='Page type' d='Modules.Cleanurls.Admin'}</th><th>{l s='Language' d='Modules.Cleanurls.Admin'}</th><th>{l s='Friendly URL' d='Modules.Cleanurls.Admin'}</th><th>{l s='Pages' d='Modules.Cleanurls.Admin'}</th><th>{l s='Problem' d='Modules.Cleanurls.Admin'}</th></tr></thead>
        <tbody>
          {foreach $cu_duplicates as $d}
            <tr>
              <td>{$d.type|escape:'html':'UTF-8'}</td>
              <td>{$d.iso|upper|escape:'html':'UTF-8'}</td>
              <td><code>{$d.slug|escape:'html':'UTF-8'}</code></td>
              <td>{foreach $d.items as $it}#{$it.id|intval} {$it.name|escape:'html':'UTF-8'}{if !$it@last}<br>{/if}{/foreach}</td>
              <td>{if $d.reason == 'page_clash'}{l s='Same URL as a store page' d='Modules.Cleanurls.Admin'}{else}{l s='Same URL' d='Modules.Cleanurls.Admin'}{/if}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
      <form method="post" action="{$cu_base|escape:'html':'UTF-8'}&cu_tab=duplicates">
        <button name="cu_fix_duplicates" class="btn btn-primary" onclick="return confirm('{l s='Rename the duplicates to slug-2, slug-3…? Their old URLs will redirect.' d='Modules.Cleanurls.Admin' js=1}');"><i class="icon-magic"></i> {l s='Fix automatically' d='Modules.Cleanurls.Admin'}</button>
      </form>
    {else}
      <p class="alert alert-success">{l s='No duplicate URLs. Every page has its own address.' d='Modules.Cleanurls.Admin'}</p>
    {/if}
  </div>
{/if}

{if $cu_tab == 'sitemap'}
  <div class="panel">
    <div class="panel-heading"><i class="icon-sitemap"></i> {l s='XML sitemap' d='Modules.Cleanurls.Admin'}</div>
    {if $cu_sitemap_on}
      <p>{l s='Your sitemap is live and always up to date:' d='Modules.Cleanurls.Admin'} <a href="{$cu_sitemap_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener"><code>{$cu_sitemap_url|escape:'html':'UTF-8'}</code></a></p>
      <p>{l s='%count% pages are listed, each with its translations (hreflang).' sprintf=['%count%' => $cu_sitemap_count] d='Modules.Cleanurls.Admin'}</p>
      <h4>{l s='Next steps' d='Modules.Cleanurls.Admin'}</h4>
      <ol>
        <li>{l s='Submit the sitemap URL in Google Search Console and Bing Webmaster Tools.' d='Modules.Cleanurls.Admin'}</li>
        <li>{if $cu_robots_has}<span class="text-success"><i class="icon-check"></i> {l s='robots.txt already points to the sitemap.' d='Modules.Cleanurls.Admin'}</span>{else}
          {l s='Tell search engines where it is in robots.txt:' d='Modules.Cleanurls.Admin'}
          <form method="post" action="{$cu_base|escape:'html':'UTF-8'}&cu_tab=sitemap" style="display:inline"><button name="cu_add_robots" class="btn btn-default btn-sm"><i class="icon-plus"></i> {l s='Add to robots.txt' d='Modules.Cleanurls.Admin'}</button></form>{/if}</li>
      </ol>
    {else}
      <p class="text-muted">{l s='The sitemap is turned off. Turn it on in the URL settings tab.' d='Modules.Cleanurls.Admin'}</p>
    {/if}
  </div>
{/if}
