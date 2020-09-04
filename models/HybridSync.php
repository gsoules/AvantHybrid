<?php

class HybridSync
{
    protected $hybrids = array();

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
                    $pseudos[$name] = $value;
                else
                    $elements[$name] = $value;
            }

            $this->hybrids[$pseudos['<hybrid-id>']] = array('pseudos' => $pseudos, 'elements' => $elements);
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

        foreach ($this->hybrids as $hybrid)
        {
            $hybridId = $hybrid['pseudos']['<hybrid-id>'];
            $hybridItemRecord = AvantHybrid::getItemRecord($hybridId);

            if ($hybridItemRecord)
            {
                $itemId = $hybridItemRecord['item_id'];
                $item = $this->updateItem($itemId, $hybrid);

                // Delete image and thumb urls in the Hybrid Images table
                // Delete element texts except Identifier element

            }
            else
            {
                // This is a new hybrid. Create an item for it and add it to the hybrids table.
                $item = $this->createNewItem($hybrid);
                $this->createNewHybrid($hybridId, $item);
            }

            // Map <type> and Subject to Common Vocabulary

            // Add element texts for element values
            // Set the <site> link

            // Add image and thumb urls to the Hybrid Images table.

            // Call AvantElasticsearch to update indexes
        }

        return $result;
    }

    protected function updateItem($itemId, $hybrid)
    {
        $item = ItemMetadata::getItemFromId($itemId);

        $public = $hybrid == '1';
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