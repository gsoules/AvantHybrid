<?php

class AvantHybrid
{
    public static function getElementTextsForItem($itemId)
    {
        $db = get_db();

        try
        {
            $select = $db->select()
                ->from($db->ElementText)
                ->where('record_id = ?', $itemId);
            $results = $db->getTable('ElementText')->fetchObjects($select);
       }
        catch (Exception $e)
        {
            $results = array();
        }

        return $results;
    }

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

    public static function getItemRecord($hybridId)
    {
        return get_db()->getTable('HybridItems')->getHybridItemsRecordByHybridId($hybridId);
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
        $pw = HybridConfig::getOptionTextForSyncPassword();

        if ($password == $pw)
        {
            switch ($action)
            {
                case 'hybrid-update':
                    $hybridSync = new HybridSync();
                    $response = $hybridSync->syncHybridItemsWithUpdates();
                    break;

                default:
                    $response = 'Unsupported AvantHybrid action: ' . $action;
                    break;
            }
        }
        else
        {
            $response = 'Hybrid request denied';
        }

        return $response;
    }
}