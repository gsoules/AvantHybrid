<?php

class AvantHybridPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'admin_head',
        'after_delete_item',
        'config',
        'config_form',
        'install',
        'public_head',
        'public_items_show',
        'public_search_results',
        'uninstall'
     );

    protected $_filters = array(
    );

    public function hookAdminHead($args)
    {
        queue_css_file('avanthybrid-admin');
    }

    public function hookAfterDeleteItem($args)
    {
        if (AvantHybrid::deletingHybridItem())
        {
            // This method is getting called because HybridImport is deleting an item. Do nothing here.
            return;
        }

        // The Omeka item for a hybrid source record is getting deleted by the admin for some reason.
        // Delete the source record information so that we are not left with a ghost hybrid item.
        $item = $args['record'];
        $hybridItemRecord = AvantHybrid::getHybridItemsRecordForOmekaItem($item->id);
        if ($hybridItemRecord)
        {
            $hybridImport = new HybridImport();
            $hybridImport->deleteHybridItem($hybridItemRecord['hybrid_id']);
        }
    }

    public function hookConfig()
    {
        HybridConfig::saveConfiguration();
    }

    public function hookConfigForm()
    {
        require dirname(__FILE__) . '/config_form.php';
    }

    public function hookInstall()
    {
        HybridTableFactory::createHybridItemsTable();
        HybridTableFactory::createHybridImagesTable();

        set_option(HybridConfig::OPTION_HYBRID_USE_CV, 1);
    }

    public function hookPublicHead($args)
    {
    }

    public function hookPublicItemsShow($args)
    {
        $linkName = __('Save this item as a PDF file');
        echo "<p><a id='save-item-pdf-link' href='?report'>$linkName</a></p>";
    }

    public function hookPublicSearchResults($args)
    {
        if ($args['error'])
        {
            $message = $args['error'];
            echo "<p id='report-error-message'>$message</p>";
            return;
        }

        $linkName = __('Save these search results as a PDF file');
        $totalResults = $args['total'];
        if ($totalResults <= AvantSearch::MAX_SEARCH_RESULTS)
        {
            $suffix = $totalResults == 1 ? '' : 's';
            $message = "Creating a PDF report for $totalResults search result$suffix. This may take a while...";
            $url = $args['url'] . '&report=' . $totalResults;
            echo "<p id='download-link-container'><a class='search-link' id='save-search-results-pdf-link' href='$url' onclick='showDownloadingMessage(\"$message\")'>$linkName</a></p>";
            echo "<p id='report-downloading-message'></p>";
        }
        else
        {
            $message = __('There are too many results to create a PDF.\n\nRefine your search to return no more than %s results.', AvantSearch::MAX_SEARCH_RESULTS);
            echo "<p><a class='search-link' id='save-search-results-pdf-link' onclick='alert(\"$message\");' href='#'>$linkName</a></p>";
        }
    }

    public function hookUninstall()
    {
        $deleteTables = intval(get_option(HybridConfig::OPTION_DELETE_HYBRID_TABLE))== 1;
        if (!$deleteTables)
            return;

        HybridTableFactory::dropHybridImagesTable();
        HybridTableFactory::dropHybridItemsTable();

        HybridConfig::removeConfiguration();
    }
}
