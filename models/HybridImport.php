<?php

class HybridImport
{
    const OPTION_HYBRID_IMPORT_DELETING_ITEM = 'deleting-hybrid-item';
    const OPTION_HYBRID_IMPORT_SAVING_ITEM = 'saving-hybrid-item';

    const ACTION_ADD = 'hybrid-add';
    const ACTION_DELETE = 'hybrid-delete';
    const ACTION_FETCH = 'hybrid-fetch';
    const ACTION_UPDATE = 'hybrid-update';

    protected $actions;
    protected $bulkImport;
    protected $identifierElementId;
    protected $siteElementId;
    protected $siteId;
    protected $subjectElementId;
    protected $tracing;
    protected $typeElementId;
    protected $useCommonVocabulary;
    protected $vocabularyCommonTermsTable = null;
    protected $vocabularySiteTermsTable = null;

    function __construct($siteId, $options)
    {
        $this->siteId = $siteId;
        $parts = explode(',', $options);

        // The bulk import option means that this import request is one of a many that are intended
        // to quickly import many source records. To improve performance, no calls will be made to
        // AvantElastic search to add, update, or remove items from the local and shared indexes.
        // This speeds up the import process by 10x or more, but using this option requires that
        // that you rebuild the Elasticsearch indexes, via the Elasticsearch Indexing page,
        // immediately after the import has completed.
        $this->bulkImport = in_array('bulk', $parts);

        $this->tracing = in_array('trace', $parts);
        if ($this->tracing)
        {
            $this->logAction('');
            $bulk = $this->bulkImport ? 'Yes' : ' No';
            $this->trace("Bulk import: $bulk");
        }

        $this->useCommonVocabulary =
            plugin_is_active('AvantVocabulary') &&
            plugin_is_active('AvantElasticsearch') &&
            intval(get_option(HybridConfig::OPTION_HYBRID_USE_CV)) != 0;

        if ($this->useCommonVocabulary)
        {
            $this->vocabularyCommonTermsTable = get_db()->getTable('VocabularyCommonTerms');
            $this->vocabularySiteTermsTable = get_db()->getTable('VocabularySiteTerms');
        }

        $this->identifierElementId = ItemMetadata::getIdentifierElementId();
        $this->subjectElementId = ItemMetadata::getElementIdForElementName('Subject');
        $this->typeElementId = ItemMetadata::getElementIdForElementName('Type');
        $this->siteElementId = ItemMetadata::getElementIdForElementName(HybridConfig::getOptionTextForSiteElement());
    }

    protected function addElementTextsToHybridItem($hybrid, $item)
    {
        $this->trace(__FUNCTION__);

        foreach ($hybrid['elements'] as $elementId => $text)
        {
            if (empty($text))
                continue;

            if ($this->useCommonVocabulary && $elementId == $this->typeElementId)
                $texts = $this->getValueForTypeElement($text);
            elseif ($this->useCommonVocabulary && $elementId == $this->subjectElementId)
                $texts = $this->getValueForSubjectElement($text);
            else
                $texts = array($text);

            foreach ($texts as $value)
            {
                $element = $item->getElementById($elementId);
                $item->addTextForElement($element, $value);
                $item->saveElementTexts();
            }
        }
    }

    protected function addImagesToHybridImagesTable($hybrid, $itemId)
    {
        $this->trace(__FUNCTION__);

        $images = explode(';', $hybrid['properties']['<image>']);
        $thumbs = explode(';', $hybrid['properties']['<thumb>']);

        foreach ($images as $index => $image)
        {
            if (empty($image))
                continue;
            $hybridImagesRecord = new HybridImages();
            $hybridImagesRecord['item_id'] = $itemId;
            $hybridImagesRecord['order'] = $index + 1;
            $hybridImagesRecord['image'] = $images[$index];
            $hybridImagesRecord['thumb'] = $thumbs[$index];
            if (!$hybridImagesRecord->save())
                throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
        }
    }

    protected function addSiteLinkToHybridItem($item, $hybrid, $siteElementId)
    {
        if (empty($siteElementId))
            return;

        $this->trace(__FUNCTION__);

        $element = $item->getElementById($siteElementId);
        $siteUrl = AvantHybrid::getSiteUrl();

        // Replace '<hybrid-id>' within the <site> value with the hybrid Id. For example:
        // replace "photo/<hybrid-id>" with "photo/46E11150-44DA-4BA3-8715-777062693340".
        $recordPath = str_replace('<hybrid-id>', $hybrid['properties']['<hybrid-id>'], $hybrid['properties']['<site>']);

        $item->addTextForElement($element, $siteUrl . $recordPath);
        $item->saveElementTexts();
    }

    protected function createNewHybridItemsRecord($item, $hybridId)
    {
        $this->trace(__FUNCTION__);

        $newHybridItemsRecord = new HybridItems();
        $newHybridItemsRecord['hybrid_id'] = $hybridId;
        $newHybridItemsRecord['item_id'] = $item->id;
        if (!$newHybridItemsRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
    }

    protected function createNewOmekaItem($hybrid)
    {
        $nextIdentifier = AvantCommon::getNextIdentifier();
        $elementTexts = array(
            'Dublin Core' => array(
                'Identifier' => array(
                    array('text' => $nextIdentifier, 'html' => false)
                )
            )
        );

        $public = $hybrid['properties']['<public>'] == '1';
        $metadata = array(
            'public' => $public,
            'item_type_id' => AvantAdmin::getCustomItemTypeId()
        );

        $_SESSION[self::OPTION_HYBRID_IMPORT_SAVING_ITEM] = true;
        $item = insert_item($metadata, $elementTexts);
        $_SESSION[self::OPTION_HYBRID_IMPORT_SAVING_ITEM] = false;

        return $item;
    }

    protected function deleteHybridItem($hybridId)
    {
        $itemId = $this->deleteHybridItemSourceRecords($hybridId);
        $item = ItemMetadata::getItemFromId($itemId);
        if ($item)
        {
            if (!$this->bulkImport && plugin_is_active('AvantElasticsearch') )
            {
                AvantElasticsearch::deleteItemFromIndexes($item);
            }

            // Delete the item, its files, and all of its element texts.
            $_SESSION[self::OPTION_HYBRID_IMPORT_DELETING_ITEM] = true;
            $item->delete();
            $_SESSION[self::OPTION_HYBRID_IMPORT_DELETING_ITEM] = false;

            $this->logAction("Deleted item $itemId for source record $hybridId");
        }
        else
        {
            $this->logAction("No item exists for source record $hybridId");
        }
    }

    protected function deleteHybridItemElementTexts($itemId, $identifierElementId)
    {
        // Delete element texts except Identifier element
        $elementTexts = AvantHybrid::getElementTextsForOmekaItem($itemId);
        foreach ($elementTexts as $elementText)
        {
            if ($elementText['element_id'] == $identifierElementId)
            {
                continue;
            }
            $elementText->delete();
        }
    }

    public function deleteHybridItemSourceRecords($hybridId)
    {
        $this->trace(__FUNCTION__);

        $hybridItemRecord = AvantHybrid::getHybridItemsRecord($hybridId);
        if ($hybridItemRecord)
        {
            $itemId = $hybridItemRecord['item_id'];
            $this->deleteImagesFromHybridImagesTable($itemId);
            $hybridItemRecord->delete();
            return $itemId;
        }

        return 0;
    }

    protected function deleteImagesFromHybridImagesTable($itemId)
    {
        // Delete image and thumb urls in the Hybrid Images table
        $hybridImages = AvantHybrid::getImageRecords($itemId);
        foreach ($hybridImages as $hybridImage)
        {
            $hybridImage->delete();
        }
    }

    public function fetchSourceRecords()
    {
        $sourceRecords = AvantHybrid::getAllHybridItemIds();
        foreach ($sourceRecords as $sourceRecord)
            $results[$sourceRecord['hybrid_id']] = $sourceRecord['imported'];

        $response['status'] = 'OK';
        $response['site-id'] = $this->siteId;
        $response['results'] = $results;

        return $response;
    }

    protected function getSourceRecordData()
    {
        $data = isset($_POST['data']) ? $_POST['data'] : '';
        if (empty($data))
        {
            // No data means we are debugging using hard-coded data copy/pasted from the Python exporter in dry run mode.
            $data = "{'PPID': 'C0910F7B-BEA4-42E6-9CD5-051821184355', 'OBJECTID': '018.011.16', 'OBJNAME': 'Book', 'TITLE': 'Poems For Sutton Island', 'IMAGE': '', 'THUMB': '', 'WEBINCLUDE': '0', 'CAT': 'library/<hybrid-id>', 'SUBJECTS': '', 'DATE': '1969', 'PLACE': '', 'CREATOR': 'Hortense Flexner', 'PUBLISHER': '', 'COLLECTION': '', 'DESCRIP': ''}";

            // Convert the string to use double-quotes instead of single, and remove carriage returns, so that json_decode will work;
            $data = str_replace("\r\n", "", $data);
            $data = str_replace('"', '$', $data);
            $data = str_replace("'", "\"", $data);
            $data = str_replace('$', '\\"', $data);
        }
        $data = json_decode($data, true);
        return $data;
    }

    protected function getTimeNow()
    {
        $date = new DateTime();
        $date->setTimezone(new DateTimeZone("America/New_York"));
        $dateNow = $date->format('Y-m-d H:i:s');
        return $dateNow;
    }

    protected function getValueForSubjectElement($text)
    {
        $terms = array();
        $texts = explode(';', $text);

        foreach ($texts as $subject)
        {
            $kind = AvantVocabulary::KIND_SUBJECT;
            $commonTermRecord = $this->vocabularyCommonTermsTable->getCommonTermRecordByLeaf($kind, $subject);
            $commonTermId = 0;

            if ($commonTermRecord)
            {
                // The text matches the leaf of a common Subject term.
                $term = $commonTermRecord['common_term'];
                $commonTermId = $commonTermRecord['common_term_id'];
            }
            else
            {
                // Create an 'Other' Subject term.
                $normalizedTerm = AvantVocabulary::normalizeSiteTerm($kind, $subject);
                $term = "Other, $normalizedTerm";
            }

            $this->lookupTermInSiteTermsTable($kind, $commonTermId, $term);
            $terms[] = $term;
        }

        return $terms;
    }

    protected function getValueForTypeElement($text)
    {
        $kind = AvantVocabulary::KIND_TYPE;
        $commonTermRecord = $this->vocabularyCommonTermsTable->getCommonTermRecordByLeaf($kind, $text);
        $commonTermId = 0;

        if ($commonTermRecord)
        {
            // The text matches the leaf of a common Type term.
            $term = $commonTermRecord['common_term'];
            $commonTermId = $commonTermRecord['common_term_id'];
        }
        else
        {
            // Create an 'Other' Type term.
            $term = "Other, $text";
        }

        $this->lookupTermInSiteTermsTable($kind, $commonTermId, $term);

        $terms = array($term);
        return $terms;
    }

    protected function importSourceRecord($sourceRecord, $action)
    {
        $hybridId = $sourceRecord['properties']['<hybrid-id>'];
        $hybridItemRecord = AvantHybrid::getHybridItemsRecord($hybridId);
        $item = null;

        if ($action == self::ACTION_ADD)
        {
            if ($hybridItemRecord)
            {
                // This is a bad request is to add an item that already exists. This should
                // never happen, but if it does convert the Add request to an Update request.
                $action = self::ACTION_UPDATE;
            }
            else
            {
                // Create a new hybrid item and add it to the hybrids table.
                $item = $this->createNewOmekaItem($sourceRecord);
                if (!$item)
                {
                    $this->logAction("Failed to add hybrid item for source record $hybridId");
                    return;
                }
                $this->createNewHybridItemsRecord($item, $hybridId);
            }
        }

        if ($action == self::ACTION_UPDATE)
        {
            if ($hybridItemRecord)
            {
                $itemId = $hybridItemRecord['item_id'];
                $item = AvantCommon::fetchItemForRemoteRequest($itemId);

                if ($item)
                {
                    // Delete the hybrid's images.
                    $this->deleteImagesFromHybridImagesTable($itemId);

                    // Delete the item's element texts;
                    $this->deleteHybridItemElementTexts($itemId, $this->identifierElementId);

                    // Update the hybrid record's imported date.
                    $dateNow = $this->getTimeNow();
                    $hybridItemRecord['imported'] = $dateNow;
                    if (!$hybridItemRecord->save())
                        throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
                }
                else
                {
                    // This source record is in the hybrid items table, but has no corresponding item.
                    // This should never happen, but if it does, clean up the ghost record.
                    $this->deleteHybridItemSourceRecords($hybridId);
                    $this->logAction("No Omeka item found to update for source record $hybridId.");
                    return;
                }
            }
            else
            {
                // This is a bad request to update a hybrid item that does not exist in the hybrid items table.
                $this->logAction("No hybrid item exists to be updated for source record $hybridId");
                return;
            }
        }

        // Add image and thumb urls to the Hybrid Images table.
        $this->addImagesToHybridImagesTable($sourceRecord, $item->id);

        // Add element texts for element values
        $this->addElementTextsToHybridItem($sourceRecord, $item);

        // Set the <site> link
        $this->addSiteLinkToHybridItem($item, $sourceRecord, $this->siteElementId);

        // Call AvantElasticsearch to update indexes
        if (!$this->bulkImport && plugin_is_active('AvantElasticsearch'))
        {
            $this->trace("Update Elasticsearch indexes");
            $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
            $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
            $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;
            $avantElasticsearch = new AvantElasticsearch();
            $avantElasticsearch->updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled);
        }

        // Save the item, updating its public status if necessary.
        $this->trace("saveOmekaItem");
        $public = $sourceRecord['properties']['<public>'] == '1';
        $this->saveOmekaItem($item, $public);

        $this->reportAction($action, $item, $hybridId);
    }

    protected function logAction($action, $trace = false)
    {
        if ($trace && !$this->tracing)
            return;

        $newline = current_user() ? '<br/>' : PHP_EOL;
        if ($this->actions)
            $this->actions .= $newline;

        if ($trace)
            $action = $this->getTimeNow() . ": $action";

        $this->actions .= $action;
    }

    protected function lookupTermInSiteTermsTable($kind, $commonTermId, $term)
    {
        $addSiteTerm = true;

        if ($this->vocabularySiteTermsTable->siteTermExists($kind, $term))
        {
            // The term is in the site terms table as a mapped or unmapped term.
            $addSiteTerm = false;
        }
        elseif ($commonTermId)
        {
            $results = $this->vocabularySiteTermsTable->getSiteTermRecordsByCommonTermId($commonTermId);
            if ($results)
            {
                // The term is in the site terms table as a common term.
                $addSiteTerm = false;
            }
        }

        if ($addSiteTerm)
        {
            AvantVocabulary::addNewUnmappedSiteTerm($kind, $term);
            $this->trace("Added unmapped site term '$term' (kind = $kind)");
        }
    }

    public function performImportAction($action)
    {
        $data = $this->getSourceRecordData();

        // Create an array that maps hybrid column names to element Ids and pseudo element names.
        $map = array();
        $mappings = HybridConfig::getOptionDataForColumnMappingField();
        foreach ($mappings as $elementId => $mapping)
        {
            $map[$mapping['column']] = $elementId;
        }

        if ($action == self::ACTION_DELETE)
        {
            $hybridIdColumnName = array_search('<hybrid-id>', $map);
            $hybridId = $data[$hybridIdColumnName];
            $this->deleteHybridItem($hybridId);
        }
        else
        {
            $pseudoElements = HybridConfig::getPseudoElements();

            foreach ($map as $column => $elementName)
            {
                $value = $data[$column];
                if (in_array($elementName, $pseudoElements))
                    $properties[$elementName] = $value;
                else
                    $elements[$elementName] = $value;
            }

            $sourceRecord = array('properties' => $properties, 'elements' => $elements);
            $this->importSourceRecord($sourceRecord, $action);
        }

        $response['status'] = 'OK';
        $response['site-id'] = $this->siteId;
        $response['results'] = $this->actions;

        return $response;
    }

    protected function reportAction($action, $item, $hybridId)
    {
        $actionName = $action == self::ACTION_ADD ? 'Added' : 'Updated';
        $this->logAction("$actionName item $item->id for record $hybridId", $this->tracing);

        if ($this->tracing)
        {
            // Insert a blank line after each item.
            $this->logAction('');
        }
    }

    protected function reportError($methodName, $error)
    {
        return "Exception in method $methodName(): $error";
    }

    protected function saveOmekaItem($item, $public)
    {
        if ($item->public != $public)
            $item['public'] = $public;

        $date = new DateTime();
        $date->setTimezone(new DateTimeZone("America/New_York"));
        $modified = $date->format('c');
        $item['modified'] = $modified;

        $_SESSION[self::OPTION_HYBRID_IMPORT_SAVING_ITEM] = true;
        $saved = $item->save();
        $_SESSION[self::OPTION_HYBRID_IMPORT_SAVING_ITEM] = false;

        if (!$saved)
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
    }

    protected function trace($message)
    {
        $this->logAction($message, true);
    }
}