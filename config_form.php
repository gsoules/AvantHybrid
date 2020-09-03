<?php
$view = get_view();

$columnMappingField = HybridConfig::getOptionTextForColumnMappingField();
$columnMappingFieldRows = max(2, count(explode(PHP_EOL, $columnMappingField)));
$deleteTable = intval(get_option(HybridConfig::OPTION_DELETE_HYBRID_TABLE)) != 0;
$imageUrl = get_option(HybridConfig::OPTION_HYBRID_IMAGE_URL);
?>

<div class="plugin-help learn-more">
    <a href="https://digitalarchive.us/plugins/avanthybrid/" target="_blank">Learn about this plugin</a>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_HYBRID_IMAGE_URL; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("The URL of hybrid images."); ?></p>
        <?php echo $view->formText(HybridConfig::OPTION_HYBRID_IMAGE_URL, $imageUrl); ?>
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


