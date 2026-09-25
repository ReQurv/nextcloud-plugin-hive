<?php
/** @var array $_ */
\OCA\RequrvHive\Template\ViteAssets::load('requrvhive-admin');
?>

<div id="requrvhive-admin-settings"
     class="section"
     data-search-enabled="<?php echo $_['search_enabled'] ? '1' : '0'; ?>"></div>
