<?php

class HybridSync
{
    protected $hybrids = array();

    protected function addHybridImages($hybrid, $itemId)
    {
        $images = explode(';', $hybrid['properties']['<image>']);
        $thumbs = explode(';', $hybrid['properties']['<thumb>']);

        foreach ($images as $index => $image)
        {
            $hybridImagesRecord = new HybridImages();
            $hybridImagesRecord['item_id'] = $itemId;
            $hybridImagesRecord['order'] = $index + 1;
            $hybridImagesRecord['image'] = $images[$index];
            $hybridImagesRecord['thumb'] = $thumbs[$index];
            if (!$hybridImagesRecord->save())
                throw new Exception($this->reportError(__FUNCTION__, ' save failed'));
        }
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

        $public = $hybrid == '1';
        $metadata = array(
            'public' => $public,
            'item_type_id' => AvantAdmin::getCustomItemTypeId()
        );
        $item = insert_item($metadata, $elementTexts);
        return $item;
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

    protected function readHybridCsvFile()
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
        $timestampColumn = array_search(array_search('<timestamp>', $map), $header);

        // Create a hybrid object for each row that has been updated.
        $pseudoElements = HybridConfig::getPseudoElements();
        foreach ($csvRows as $index => $csvRow)
        {
            if ($index == 0)
                // Skip the header row.
                continue;

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

            $this->hybrids[$properties['<hybrid-id>']] = array('properties' => $properties, 'elements' => $elements);
        }

        return 'OK';
    }

    protected function reportError($methodName, $error)
    {
        return "Exception in method $methodName(): $error";
    }

    public function syncHybridItemsWithUpdates()
    {
        $result = $this->readHybridCsvFile();
        if ($result != 'OK')
            return $result;

        // Delete items from the Hybrid Items table that are not in the hybrids list.
        //
        //

        $identifierElementId = ItemMetadata::getIdentifierElementId();
        $subjectElementId = ItemMetadata::getElementIdForElementName('Subject');
        $typeElementId = ItemMetadata::getElementIdForElementName('Type');

        $vocabularyCommonTermsTable = plugin_is_active('AvantVocabulary') ? get_db()->getTable('VocabularyCommonTerms') : null;

        foreach ($this->hybrids as $hybrid)
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
                $this->deleteElementTexts($itemId, $identifierElementId);
            }
            else
            {
                // This is a new hybrid. Create an item for it and add it to the hybrids table.
                $item = $this->createNewItem($hybrid);
                $this->createNewHybrid($hybridId, $item);
            }

            // Add image and thumb urls to the Hybrid Images table.
            $this->addHybridImages($hybrid, $item->id);

            // Map <type> and Subject to Common Vocabulary
            $type = $hybrid['properties']['<type>'];
            if (!empty($type))
            {
                if ($vocabularyCommonTermsTable)
                {
                    $kind = AvantVocabulary::KIND_TYPE;
                    $commonTermRecord = $vocabularyCommonTermsTable->getCommonTermRecordByLeaf($kind, $type);
                    if ($commonTermRecord)
                        $type = $commonTermRecord['common_term'];
                }

                // Add a Type element to the hybrid's elements.
                $hybrid['elements'][$typeElementId] = $type;
            }

            // Add element texts for element values
            foreach ($hybrid['elements'] as $elementId => $text)
            {
                if (empty($text))
                    continue;

                if ($elementId == $subjectElementId)
                {
                    $texts = explode(';', $text);
                    if ($vocabularyCommonTermsTable)
                    {
                        foreach ($texts as $index => $subject)
                        {
                            $kind = AvantVocabulary::KIND_SUBJECT;
                            $commonTermRecord = $vocabularyCommonTermsTable->getCommonTermRecordByLeaf($kind, $subject);
                            if ($commonTermRecord)
                                $texts[$index] = $commonTermRecord['common_term'];
                        }
                    }
                }
                else
                {
                    $texts = array($text);
                }

                foreach ($texts as $value)
                {
                    $element = $item->getElementById($elementId);
                    $item->addTextForElement($element, $value);
                    $item->saveElementTexts();
                }
            }

            // Set the <site> link

            // Call AvantElasticsearch to update indexes
        }

        return $result;
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