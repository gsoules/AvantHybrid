<?php

class HybridImport
{
    protected $allHybrids = array();
    protected $identifierElementId;
    protected $rebuildSiteTermsTable;
    protected $siteElementId;
    protected $subjectElementId;
    protected $typeElementId;
    protected $updatedHybrids = array();
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
    }

    protected function addElementTexts($hybrid, $item)
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

    protected function addHybridImages($hybrid, $itemId)
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

    protected function addSiteLink($item, $hybrid, $siteElementId)
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

    protected function createNewHybrid($hybridId, Item $item)
    {
        $newHybridItemRecord = new HybridItems();
        $newHybridItemRecord['hybrid_id'] = $hybridId;
        $newHybridItemRecord['item_id'] = $item->id;
        if (!$newHybridItemRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
    }

    protected function createNewItem($hybrid)
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
        $avantElasticsearchIndexBuilder = plugin_is_active('AvantElasticsearch') ? new AvantElasticsearchIndexBuilder() : null;

        // Delete items from the Hybrid Items table that are not in the hybrids list.
        $hybridIds = AvantHybrid::getAllHybridItemIds();
        foreach ($hybridIds as $id)
        {
            $hybridId = $id['hybrid_id'];
            if (!in_array($hybridId, $this->allHybrids))
            {
                $hybridItemRecord = AvantHybrid::getItemRecord($hybridId);
                $itemId = $hybridItemRecord['item_id'];
                $this->deleteImages($itemId);
                $hybridItemRecord->delete();
                $item = ItemMetadata::getItemFromId($itemId);
                if ($item)
                {
                    // Delete the item and all of its element texts.
                    // This will also remove the item from the Elasticsearch indexes.
                    $item->delete();
                }
            }
        }
    }

    protected function deleteElementTexts($itemId, $identifierElementId)
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

    protected function deleteImages($itemId)
    {
        // Delete image and thumb urls in the Hybrid Images table
        $hybridImages = AvantHybrid::getImageRecords($itemId);
        foreach ($hybridImages as $hybridImage)
        {
            $hybridImage->delete();
        }
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

    public function importSourceRecords()
    {
        $result = $this->readSourceRecordsCsvFile();
        if ($result != 'OK')
            return $result;

        // Delete any hybrid items in the Digital Archive that are no longer in the hybrid source database.
        $this->deleteDeletedHybridItems();

        // Apply updates to all hybrid items that were added to or changed in the source database.
        foreach ($this->updatedHybrids as $hybrid)
        {
            $hybridId = $hybrid['properties']['<hybrid-id>'];
            $hybridItemRecord = AvantHybrid::getItemRecord($hybridId);

            if ($hybridItemRecord)
            {
                $itemId = $hybridItemRecord['item_id'];
                $item = $this->updateItem($itemId, $hybrid);
                if (!$item)
                    return "No item found for Id $itemId";

                // Delete the hybrid's images.
                $this->deleteImages($itemId);

                // Delete the item's element texts;
                $this->deleteElementTexts($itemId, $this->identifierElementId);
            }
            else
            {
                // This is a new hybrid. Create an item for it and add it to the hybrids table.
                $item = $this->createNewItem($hybrid);
                $this->createNewHybrid($hybridId, $item);
            }

            // Add image and thumb urls to the Hybrid Images table.
            $this->addHybridImages($hybrid, $item->id);

            // Add element texts for element values
            $this->addElementTexts($hybrid, $item);

            // Set the <site> link
            $this->addSiteLink($item, $hybrid, $this->siteElementId);

            // Call AvantElasticsearch to update indexes
            if (plugin_is_active('AvantElasticsearch'))
            {
                $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
                $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
                $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;
                $avantElasticsearch = new AvantElasticsearch();
                $avantElasticsearch->updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled);
            }
        }

        $result = $this->rebuildSiteTermsTable();

        return $result;
    }

    protected function lookupTermInSiteTermsTable($kind, $commonTermId, $term)
    {
        $rebuild = true;
        if ($this->vocabularySiteTermsTable->siteTermExists(AvantVocabulary::KIND_SUBJECT, $term))
        {
            // The term is in the site terms table as a mapped or unmapped term.
            $rebuild = false;
        }
        else
        {
            $results = $this->vocabularySiteTermsTable->getSiteTermRecordsByCommonTermId($commonTermId);
            if ($results)
            {
                // The term is in the site terms table as a common term.
                $rebuild = false;
            }
        }

        $this->rebuildSiteTermsTable = $rebuild;
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
            return "File not found: $filepath";
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

            $this->allHybrids[] = $csvRow[$hybridIdColumn];

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

            $this->updatedHybrids[$properties['<hybrid-id>']] = array('properties' => $properties, 'elements' => $elements);
        }

        return 'OK';
    }

    protected function rebuildSiteTermsTable()
    {
        $result = 'OK';
        if ($this->useCommonVocabulary && $this->rebuildSiteTermsTable)
        {
            try
            {
                $tableBuilder = new AvantVocabularyTableBuilder();
                $tableBuilder->buildSiteTermsTable();
            }
            catch (Exception $e)
            {
                $result = 'Common Vocabulary site terms table rebuild failed: ' . $e->getMessage();
            }
        }
        return $result;
    }

    protected function reportError($methodName, $error)
    {
        return "Exception in method $methodName(): $error";
    }

    protected function updateItem($itemId, $hybrid)
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