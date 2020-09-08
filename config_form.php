<?php
$view = get_view();

$columnMappingField = HybridConfig::getOptionTextForColumnMappingField();
$columnMappingFieldRows = max(2, count(explode(PHP_EOL, $columnMappingField)));
$deleteTable = intval(get_option(HybridConfig::OPTION_DELETE_HYBRID_TABLE)) != 0;
$imageUrl = get_option(HybridConfig::OPTION_HYBRID_IMAGE_URL);
$importId = HybridConfig::getOptionTextForImportId();
$importPw = HybridConfig::getOptionTextForImportPassword();
$siteElement = HybridConfig::getOptionTextForSiteElement();
$siteUrl = get_option(HybridConfig::OPTION_HYBRID_SITE_URL);
$useCommonVocabulary = intval(get_option(HybridConfig::OPTION_HYBRID_USE_CV)) != 0;
?>

<div class="plugin-help learn-more">
    <a href="https://digitalarchive.us/plugins/avanthybrid/" target="_blank">Learn about this plugin</a>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_HYBRID_IMAGE_URL; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("The base URL of hybrid images"); ?></p>
        <?php echo $view->formText(HybridConfig::OPTION_HYBRID_IMAGE_URL, $imageUrl); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_HYBRID_SITE_URL; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("The base URL of the hybrid website"); ?></p>
        <?php echo $view->formText(HybridConfig::OPTION_HYBRID_SITE_URL, $siteUrl); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_HYBRID_SITE_ELEMENT; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("The name of the external resource element for the hybrid record link"); ?></p>
        <?php echo $view->formText(HybridConfig::OPTION_HYBRID_SITE_ELEMENT, $siteElement); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_HYBRID_IMPORT_ID; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("The import ID (3 - 6 alpha characters)"); ?></p>
        <?php echo $view->formText(HybridConfig::OPTION_HYBRID_IMPORT_ID, $importId); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_HYBRID_IMPORT_PW; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("The import password (8 alphanumeric characters)"); ?></p>
        <?php echo $view->formText(HybridConfig::OPTION_HYBRID_IMPORT_PW, $importPw); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_HYBRID_COLUMN_MAPPING; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("Mappings from hybrid data source column names to element names"); ?></p>
        <?php echo $view->formTextarea(HybridConfig::OPTION_HYBRID_COLUMN_MAPPING, $columnMappingField, array('rows' => $columnMappingFieldRows)); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_HYBRID_USE_CV; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("Use the Common Vocabulary when importing Type and Subject"); ?></p>
        <?php echo $view->formCheckbox(HybridConfig::OPTION_HYBRID_USE_CV, true, array('checked' => $useCommonVocabulary)); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_DELETE_HYBRID_TABLE; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __(" WARNING: Checking this box will cause all hybrid item mappings to be
        permanently deleted if you uninstall this plugin. 
        Click <a href=\"https://digitalarchive.us/plugins/avanthybrid/\" target=\"_blank\" style=\"color:red;\">
        here</a> to read the documentation for the Delete Tables option before unchecking the box."); ?></p>
        <?php echo $view->formCheckbox(HybridConfig::OPTION_DELETE_HYBRID_TABLE, true, array('checked' => $deleteTable)); ?>
    </div>
</div>


