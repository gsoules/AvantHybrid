<?php

class HybridImport
{
    protected $actions;
    protected $identifierElementId;
    protected $rebuildSiteTermsTable;
    protected $siteElementId;
    protected $sourceRecords = array();
    protected $subjectElementId;
    protected $typeElementId;
    protected $updatedHybridItems = array();
    protected $useCommonVocabulary;
    protected $vocabularyCommonTermsTable = null;
    protected $vocabularySiteTermsTable = null;

    function __construct()
    {
        $this->useCommonVocabulary = intval(get_option(HybridConfig::OPTION_HYBRID_USE_CV)) != 0;
        $this->rebuildSiteTermsTable = false;

        if ($this->useCommonVocabulary && plugin_is_active('AvantVocabulary'))
        {
            $this->vocabularyCommonTermsTable = get_db()->getTable('VocabularyCommonTerms');
            $this->vocabularySiteTermsTable = get_db()->getTable('VocabularySiteTerms');
        }

        $this->identifierElementId = ItemMetadata::getIdentifierElementId();
        $this->subjectElementId = ItemMetadata::getElementIdForElementName('Subject');
        $this->typeElementId = ItemMetadata::getElementIdForElementName('Type');
        $this->siteElementId = ItemMetadata::getElementIdForElementName(HybridConfig::getOptionTextForSiteElement());

        $this->logAction('');
    }

    protected function addElementTextsToHybridItem($hybrid, $item)
    {
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

        $element = $item->getElementById($siteElementId);
        $siteUrl = AvantHybrid::getSiteUrl();

        // Replace '<hybrid-id>' within the <site> value with the hybrid Id. For example:
        // replace "photo/<hybrid-id>" with "photo/46E11150-44DA-4BA3-8715-777062693340".
        $recordPath = str_replace('<hybrid-id>', $hybrid['properties']['<hybrid-id>'], $hybrid['properties']['<site>']);

        $item->addTextForElement($element, $siteUrl . $recordPath);
        $item->saveElementTexts();
    }

    protected function createNewHybridItemsTableRecord($hybridId, Item $item)
    {
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
        $item = insert_item($metadata, $elementTexts);
        return $item;
    }

    protected function deleteDeletedHybridItems()
    {
        // Delete items from the Hybrid Items table that are not in the hybrids list.
        $hybridIds = AvantHybrid::getAllHybridItemIds();
        foreach ($hybridIds as $id)
        {
            $hybridId = $id['hybrid_id'];
            if (!in_array($hybridId, $this->sourceRecords))
            {
                $hybridItemRecord = AvantHybrid::getItemRecord($hybridId);
                $itemId = $hybridItemRecord['item_id'];
                $this->deleteImagesFromHybridImagesTable($itemId);
                $hybridItemRecord->delete();
                $item = ItemMetadata::getItemFromId($itemId);
                if ($item)
                {
                    // Delete the item and all of its element texts.
                    // This will also remove the item from the Elasticsearch indexes.
                    $item->delete();
                    $this->logAction('Delete item' . $itemId);
                }
            }
        }
    }

    protected function deleteHybridItemElementTexts($itemId, $identifierElementId)
    {
        // Delete element texts except Identifier element
        $elementTexts = AvantHybrid::getElementTextsForItem($itemId);
        foreach ($elementTexts as $elementText)
        {
            if ($elementText['element_id'] == $identifierElementId)
            {
                continue;
            }
            $elementText->delete();
        }
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

    protected function getResponse($success)
    {
        $status = $success ? "OK" : "FAIL";
        return  $status . $this->actions;
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

    protected function importSourceRecord($hybrid)
    {

        $hybridId = $hybrid['properties']['<hybrid-id>'];
        $hybridItemRecord = AvantHybrid::getItemRecord($hybridId);

        if ($hybridItemRecord)
        {
            $itemId = $hybridItemRecord['item_id'];
            $item = $this->updateHybridItemModifiedDate($itemId, $hybrid);
            if (!$item)
            {
                $this->logAction("No Omeka item found to update for item Id $itemId");
                return true;
            }

            // Delete the hybrid's images.
            $this->deleteImagesFromHybridImagesTable($itemId);

            // Delete the item's element texts;
            $this->deleteHybridItemElementTexts($itemId, $this->identifierElementId);

            $this->logAction("Updated $hybridId as item $itemId");
        }
        else
        {
            // This is a new hybrid. Create an item for it and add it to the hybrids table.
            $item = $this->createNewOmekaItem($hybrid);
            $this->createNewHybridItemsTableRecord($hybridId, $item);
            $this->logAction("Added $hybridId as item $item->id");
        }

        // Add image and thumb urls to the Hybrid Images table.
        $this->addImagesToHybridImagesTable($hybrid, $item->id);

        // Add element texts for element values
        $this->addElementTextsToHybridItem($hybrid, $item);

        // Set the <site> link
        $this->addSiteLinkToHybridItem($item, $hybrid, $this->siteElementId);

        // Call AvantElasticsearch to update indexes
        if (plugin_is_active('AvantElasticsearch'))
        {
            $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
            $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
            $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;
            $avantElasticsearch = new AvantElasticsearch();
            $avantElasticsearch->updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled);
        }

        return true;
    }

    public function importSourceRecords()
    {
        if (!$this->readSourceRecordsCsvFile())
            return $this->getResponse(false);

        // Delete any hybrid items in the Digital Archive that are no longer in the hybrid source database.
        $this->deleteDeletedHybridItems();

        // Apply updates to all hybrid items that were added to or changed in the source database.
        foreach ($this->updatedHybridItems as $hybrid)
        {
            if (!$this->importSourceRecord($hybrid))
                return $this->getResponse(false);
        }

        $this->rebuildSiteTermsTable();

        return $this->getResponse(true);
    }

    protected function logAction($action)
    {
        $newline = current_user() ? '<br/>' : PHP_EOL;
        $this->actions .= $action . $newline;
    }

    protected function lookupTermInSiteTermsTable($kind, $commonTermId, $term)
    {
        $rebuild = true;

        if ($this->vocabularySiteTermsTable->siteTermExists(AvantVocabulary::KIND_SUBJECT, $term))
        {
            // The term is in the site terms table as a mapped or unmapped term.
            $rebuild = false;
        }
        elseif ($commonTermId)
        {
            $results = $this->vocabularySiteTermsTable->getSiteTermRecordsByCommonTermId($commonTermId);
            if ($results)
            {
                // The term is in the site terms table as a common term.
                $rebuild = false;
            }
        }

        if ($rebuild)
            $this->rebuildSiteTermsTable = true;
    }

    protected function readSourceRecordsCsvFile()
    {
        // Get the path to the file containing the hybrid data.
        if (AvantCommon::userIsSuper())
            $fileName = isset($_GET['filename']) ? $_GET['filename'] : '';
        else
            $fileName = isset($_POST['filename']) ? $_POST['filename'] : '';
        $filepath = FILES_DIR . '/hybrid/' . $fileName;

        // Create an array that maps hybrid column names to element Ids and pseudo element names.
        $map = array();
        $mappings = HybridConfig::getOptionDataForColumnMappingField();
        foreach ($mappings as $elementId => $mapping)
        {
            $map[$mapping['column']] = $elementId;
        }

        // Read all the rows in the CSV file
        $csvRows = array();
        if (($handle = @fopen($filepath, "r")) !== FALSE)
        {
            while (($data = fgetcsv($handle)) !== FALSE)
            {
                $csvRows[] = $data;
            }
            fclose($handle);
        }
        else
        {
            $this->logAction("File not found: $filepath");
            return false;
        }

        // Get the header row and the column for the timestamp.
        $header = $csvRows[0];
        $hybridIdColumn = array_search(array_search('<hybrid-id>', $map), $header);
        $timestampColumn = array_search(array_search('<timestamp>', $map), $header);

        // Create a hybrid object for each row that has been updated.
        $pseudoElements = HybridConfig::getPseudoElements();
        foreach ($csvRows as $index => $csvRow)
        {
            if ($index == 0)
                // Skip the header row.
                continue;

            $this->sourceRecords[] = $csvRow[$hybridIdColumn];

            if (!isset($csvRow[$timestampColumn]))
                // Skip rows that have not been updated.
                continue;

            if (empty($csvRow[$timestampColumn]))
                // Skip this row since it was not updated.
                continue;

            foreach ($csvRow as $column => $value)
            {
                $name = $map[$header[$column]];
                if (in_array($name, $pseudoElements))
                    $properties[$name] = $value;
                else
                    $elements[$name] = $value;
            }

            $this->updatedHybridItems[$properties['<hybrid-id>']] = array('properties' => $properties, 'elements' => $elements);
        }

        return true;
    }

    protected function rebuildSiteTermsTable()
    {
        if ($this->useCommonVocabulary && $this->rebuildSiteTermsTable)
        {
            try
            {
                $tableBuilder = new AvantVocabularyTableBuilder();
                $tableBuilder->buildSiteTermsTable();
                $this->logAction('Rebuilt Common Vocabulary site terms table');
            }
            catch (Exception $e)
            {
                $this->logAction('Common Vocabulary site terms table rebuild failed: ' . $e->getMessage());
            }
        }
        else
        {
            $this->logAction('Did not need to rebuild Common Vocabulary site terms table');
        }
    }

    protected function reportError($methodName, $error)
    {
        return "Exception in method $methodName(): $error";
    }

    protected function updateHybridItemModifiedDate($itemId, $hybrid)
    {
        $item = AvantCommon::fetchItemForRemoteRequest($itemId);
        if (!$item)
            return null;

        $public = $hybrid['properties']['<public>'] == '1';
        if ($item->public != $public)
            $item['public'] = $public;

        $date = new DateTime();
        $date->setTimezone(new DateTimeZone('UTC'));
        $modified = $date->format('c');
        $item['modified'] = $modified;

        if (!$item->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

        return $item;
    }
}