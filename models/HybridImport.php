<?php

class HybridImport
{
    const OPTION_HYBRID_IMPORT_DELETING_ITEM = 'deleting-hybrid-item';
    const OPTION_HYBRID_IMPORT_SAVING_ITEM = 'saving-hybrid-item';

    protected $actions;
    protected $countAdded;
    protected $countDeleted;
    protected $countUnmappedSubject;
    protected $countUnmappedType;
    protected $countUpdated;
    protected $identifierElementId;
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
        $this->useCommonVocabulary = plugin_is_active('AvantVocabulary') && intval(get_option(HybridConfig::OPTION_HYBRID_USE_CV)) != 0;

        if ($this->useCommonVocabulary)
        {
            $this->vocabularyCommonTermsTable = get_db()->getTable('VocabularyCommonTerms');
            $this->vocabularySiteTermsTable = get_db()->getTable('VocabularySiteTerms');
        }

        $this->identifierElementId = ItemMetadata::getIdentifierElementId();
        $this->subjectElementId = ItemMetadata::getElementIdForElementName('Subject');
        $this->typeElementId = ItemMetadata::getElementIdForElementName('Type');
        $this->siteElementId = ItemMetadata::getElementIdForElementName(HybridConfig::getOptionTextForSiteElement());

        $this->countAdded = 0;
        $this->countDeleted = 0;
        $this->countUnmappedSubject = 0;
        $this->countUnmappedType = 0;
        $this->countUpdated = 0;

    //    $this->logAction('');
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

    protected function createNewHybridItemsRecord($hybrid, $hybridId)
    {
        $item = $this->createNewOmekaItem($hybrid);

        $newHybridItemsRecord = new HybridItems();
        $newHybridItemsRecord['hybrid_id'] = $hybridId;
        $newHybridItemsRecord['item_id'] = $item->id;
        if (!$newHybridItemsRecord->save())
            throw new Exception($this->reportError(__FUNCTION__, ' save failed'));

        $this->countAdded += 1;
        $this->logAction("Added item $item->id for source record $hybridId");
        return $item;
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

    protected function deleteHybridItemsForDeletedSourceRecords()
    {
        // Delete items from the Hybrid Items table that are no longer in the source records.
        $hybridIds = AvantHybrid::getAllHybridItemIds();

        foreach ($hybridIds as $id)
        {
            $hybridId = $id['hybrid_id'];
            if (!in_array($hybridId, $this->sourceRecords))
            {
                $itemId = $this->deleteHybridItemSourceRecords($hybridId);
                $item = ItemMetadata::getItemFromId($itemId);
                if ($item)
                {
                    if (plugin_is_active('AvantElasticsearch'))
                        AvantElasticsearch::deleteItemFromIndexes($item);

                    // Delete the item, its files, and all of its element texts.
                    $_SESSION[self::OPTION_HYBRID_IMPORT_DELETING_ITEM] = true;
                    $item->delete();
                    $_SESSION[self::OPTION_HYBRID_IMPORT_DELETING_ITEM] = false;

                    $this->countDeleted += 1;
                    $this->logAction("Deleted item $itemId for source record $hybridId");
                }
            }
        }
    }

    public function deleteHybridItemSourceRecords($hybridId)
    {
        $hybridItemRecord = AvantHybrid::getHybridItemsRecord($hybridId);
        $itemId = $hybridItemRecord['item_id'];
        $this->deleteImagesFromHybridImagesTable($itemId);
        $hybridItemRecord->delete();
        return $itemId;
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

    public function fetchSourceRecords($siteId)
    {
        $sourceRecords = AvantHybrid::getAllHybridItemIds();
        foreach ($sourceRecords as $sourceRecord)
            $results[] = $sourceRecord['hybrid_id'];

        $response['status'] = 'OK';
        $response['site-id'] = $siteId;
        $response['results'] = $results;
        $response['last-import'] = get_option(HybridConfig::OPTION_HYBRID_LAST_IMPORT);

        return $response;
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
                $this->countUnmappedSubject += 1;
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
            $this->countUnmappedType += 1;
        }

        $this->lookupTermInSiteTermsTable($kind, $commonTermId, $term);

        $terms = array($term);
        return $terms;
    }

    protected function importSourceRecord($sourceRecord)
    {
        $hybridId = $sourceRecord['properties']['<hybrid-id>'];
        $hybridItemRecord = AvantHybrid::getHybridItemsRecord($hybridId);

        $item = null;
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

                $this->countUpdated += 1;
                $this->logAction("Updated item $itemId for source record $hybridId");
            }
            else
            {
                // This source record is in the hybrid items table, but has no corresponding item.
                // This should never happen, but if it does, clean up the ghost record and report it.
                // Then fall through to let the hybrid item get created again.
                $this->deleteHybridItemSourceRecords($hybridId);
                $this->logAction("No Omeka item found to update for source record $hybridId.");
            }
        }

        if (!$item)
        {
            // Create a new hybrid item and add it to the hybrids table.
            $item = $this->createNewHybridItemsRecord($sourceRecord, $hybridId);
        }

        // Add image and thumb urls to the Hybrid Images table.
        $this->addImagesToHybridImagesTable($sourceRecord, $item->id);

        // Add element texts for element values
        $this->addElementTextsToHybridItem($sourceRecord, $item);

        // Set the <site> link
        $this->addSiteLinkToHybridItem($item, $sourceRecord, $this->siteElementId);

        // Call AvantElasticsearch to update indexes
        if (plugin_is_active('AvantElasticsearch'))
        {
            $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
            $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
            $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;
            $avantElasticsearch = new AvantElasticsearch();
            $avantElasticsearch->updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled);
        }

        $public = $sourceRecord['properties']['<public>'] == '1';
        $this->saveOmekaItem($item, $public);

        return true;
    }

    public function importSourceRecords($siteId)
    {
        $date = new DateTime();
        $date->setTimezone(new DateTimeZone("America/New_York"));
        $dateNow = $date->format('Y-m-d H:i:s');
        set_option(HybridConfig::OPTION_HYBRID_LAST_IMPORT, $dateNow);

        $data = isset($_POST['data']) ? $_POST['data'] : '';
        if (empty($data))
        {
            $data = '{"PPID": "58DF9E02-0D98-429B-B9E8-666324969299", "UPDATED": "2020-09-03 12:01:06", "OBJECTID": "006.16.1", "OBJNAME": "Records", "TITLE": "Luders 2003", "IMAGE": "", "THUMB": "", "WEBINCLUDE": "1", "CAT": "archive/<hybrid-id>", "SUBJECTS": "", "DATE": "1805", "PLACE": "", "CREATOR": "Haskins, Sturgis", "PUBLISHER": null, "COLLECTION": "Sturgis Haskins Collection", "DESCRIP": "The binder contains photographs of Luders sailboats in the Mount Desert Island fleets of Southwest and Northeast Harbors, by name of boat.  It also contains newspaper articles, race results, members\' names and addresses, newsletters, emails, and the like."}';
        }

        $data = json_decode($data, true);

        // Create an array that maps hybrid column names to element Ids and pseudo element names.
        $map = array();
        $mappings = HybridConfig::getOptionDataForColumnMappingField();
        foreach ($mappings as $elementId => $mapping)
        {
            $map[$mapping['column']] = $elementId;
        }

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
        $this->importSourceRecord($sourceRecord);

//        $this->logAction("Processed: " . $data['PPID']);
//        $this->logStatistics();

        $response['status'] = 'OK';
        $response['site-id'] = $siteId;
        $response['results'] = $this->actions;

        return $response;
    }

    protected function logAction($action)
    {
        $newline = current_user() ? '<br/>' : PHP_EOL;
        if ($this->actions)
            $this->actions .= $newline;
        $this->actions .= $action;
    }

    protected function logStatistics()
    {
        $this->logAction('');
        $this->logAction("Added $this->countAdded hybrid items");
        $this->logAction("Updated $this->countUpdated hybrid items");
        $this->logAction("Deleted $this->countDeleted hybrid items");
        if ($this->countUnmappedType)
            $this->logAction("$this->countUnmappedType Type terms not found in the Common Vocabulary");
        if ($this->countUnmappedSubject)
            $this->logAction("$this->countUnmappedSubject Subject terms not found in the Common Vocabulary");
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
            $this->logAction("Added unmapped site term '$term' (kind = $kind)");
        }
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

        $count = count($csvRows) - 1;
        $this->logAction("Read $count source records from $filepath");

        // Get the header row and the column for the timestamp.
        $header = $this->validateHeaderRow($csvRows[0]);
        if (!$header)
            return false;

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

    protected function validateHeaderRow($headerRow)
    {
        $header = $headerRow;

        // Verify that the file is UTF-8 and remove the BOM from the first column of the first row.
        $column0Row0 = $header[0];
        $bom = pack("CCC", 0xef, 0xbb, 0xbf);
        if (0 === strncmp($column0Row0, $bom, 3))
        {
            // BOM detected - file is UTF-8.
            $header[0] = str_replace("\xEF\xBB\xBF", '', $column0Row0);
            return $header;
        }
        else
        {
            $this->logAction("CSV file is not in UTF-8 format");
            return null;
        }
    }
}