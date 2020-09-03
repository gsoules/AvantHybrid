<?php

class AvantHybrid
{
    public static function getFileNameForImage($hybridImageRecord)
    {
        return $hybridImageRecord['image'];
    }

    public static function getFileNameForThumb($hybridImageRecord)
    {
        return $hybridImageRecord['thumb'];
    }

    public static function getImageHtml($item, $hybridImageRecord, $isThumbnail = false, $index = 0)
    {
        $imageUrl = self::getImageUrl($hybridImageRecord);
        $identifier = ItemMetadata::getItemIdentifier($item);
        $title = ItemMetadata::getItemTitle($item);
        $thumbUrl = $isThumbnail ? self::getThumbUrl($hybridImageRecord) : self::getImageUrl($hybridImageRecord);
        $imageHtml = ItemPreview::getImageLinkHtml($item->id, $identifier, 'lightbox', $imageUrl, $thumbUrl, '', $title, IMAGE_THUMB_TOOLTIP, '0', $index);
        return $imageHtml;
    }

    public static function getImageRecords($itemId)
    {
        return get_db()->getTable('HybridImages')->getHybridImageRecordsByItemId($itemId);
    }

    public static function getImageUrl($hybridImageRecord)
    {
        return get_option(HybridConfig::OPTION_HYBRID_IMAGE_URL) . self::getFileNameForImage($hybridImageRecord);
    }

    public static function getThumbHtml($item, $hybridImageRecord, $index)
    {
        return self::getImageHtml($item, $hybridImageRecord, true, $index);
    }

    public static function getThumbUrl($hybridImageRecord)
    {
        return get_option(HybridConfig::OPTION_HYBRID_IMAGE_URL) . self::getFileNameForThumb($hybridImageRecord);
    }

    public static function handleRemoteRequest($action, $siteId, $password)
    {
        if (AvantElasticsearch::remoteRequestIsValid($siteId, $password))
        {
            switch ($action)
            {
                case 'hybrid-update':
                    $response = AvantHybrid::updateHybrid();
                    break;

                default:
                    $response = 'Unsupported AvantHybrid action: ' . $action;
                    break;
            }
        }
        else
        {
            $response = '';
        }

        return $response;
    }

    public static function updateHybrid()
    {
        $mappings = HybridConfig::getOptionDataForColumnMappingField();

        $map = array();
        foreach ($mappings as $elementId => $mapping)
        {
            $map[$mapping['column']] = $elementId;
        }

        if (AvantCommon::userIsSuper())
            $fileName = isset($_GET['filename']) ? $_GET['filename'] : '';
        else
            $fileName = isset($_POST['filename']) ? $_POST['filename'] : '';

        $filepath = FILES_DIR . '/hybrid/' . $fileName;

        $csvRows = array();

        if (($handle = fopen($filepath, "r")) !== FALSE)
        {
            while (($data = fgetcsv($handle)) !== FALSE)
            {
                $csvRows[] = $data;
            }
            fclose($handle);
        }

        $header = $csvRows[0];


        $cols = array();
        $timestampColumn = array_search($mappings['<timestamp>']['column'], $header);
        $pseudoElements = HybridConfig::getPseudoElements();
        foreach ($pseudoElements as $pseudoElement)
        {
            $cols[array_search($mappings[$pseudoElement]['column'], $header)] = $pseudoElement;
        }

        $hybridElements = array();
        $hybrids = array();

        foreach ($csvRows as $index => $csvRow)
        {
            if ($index == 0)
                // Skip the header row.
                continue;

            if (!isset($csvRow[$timestampColumn]))
                continue;

            $timestamp = $csvRow[$timestampColumn];
            if (!$timestamp)
            {
                // Skip this row since it was not updated.
                continue;
            }

            foreach ($csvRow as $column => $value)
            {
                $hybrids[$map[$header[$column]]] = $value;

                if (isset($cols[$column]))
                {
                    $hybridElements[$cols[$column]] = $value;
                }
                else
                {
                    $name = $header[$column];
                    $hybridElements[$name] = $value;
                }
            }
        }

        return 'OK';
    }
}