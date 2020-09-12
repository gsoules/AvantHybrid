<?php

class AvantHybrid
{
    public static function deletingHybridItem()
    {
        return isset($_SESSION[HybridImport::OPTION_HYBRID_IMPORT_DELETING_ITEM]) && $_SESSION[HybridImport::OPTION_HYBRID_IMPORT_DELETING_ITEM];
    }

    public static function getAllHybridItemIds()
    {
        return get_db()->getTable('HybridItems')->getAllHybridItemIds();
    }

    public static function getElementTextsForOmekaItem($itemId)
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

    public static function getHybridItemsRecord($hybridId)
    {
        return get_db()->getTable('HybridItems')->getHybridItemsRecordByHybridId($hybridId);
    }

    public static function getHybridItemsRecordForOmekaItem($itemId)
    {
        return get_db()->getTable('HybridItems')->getHybridItemsRecordByItemId($itemId);
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

    public static function getOptions()
    {
        $options = isset($_POST['options']) ? $_POST['options'] : '';
        if (empty($options))
            $options = isset($_GET['options']) ? $_GET['options'] : '';
        return $options;
    }

    public static function getSiteUrl()
    {
        return get_option(HybridConfig::OPTION_HYBRID_SITE_URL);
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
        $options = self::getOptions();
        $id = HybridConfig::getOptionTextForImportId();
        $pw = HybridConfig::getOptionTextForImportPassword();
        $debugging = false;

        if ($siteId == $id && $password == $pw)
        {
            $hybridImport = new HybridImport($options);

            switch ($action)
            {
                case HybridImport::ACTION_FETCH:
                    $response = $hybridImport->fetchSourceRecords($siteId);
                    break;

                case HybridImport::ACTION_ADD:
                case HybridImport::ACTION_UPDATE:
                case HybridImport::ACTION_DELETE:
                    $debugging = isset($_GET['debug']);
                    $response = $hybridImport->performImportAction($siteId, $action);
                    break;

                default:
                    $response['status'] = 'Unsupported AvantHybrid action: ' . $action;;
                    break;
            }
        }
        else
        {
            $response['status'] = 'Hybrid request denied';
        }

        if ($debugging)
        {
            return $response['results'];
        }
        else
        {
            header('Content-Type: application/json');
            return json_encode($response);
        }
    }

    public static function savingHybridItem()
    {
        return isset($_SESSION[HybridImport::OPTION_HYBRID_IMPORT_SAVING_ITEM]) && $_SESSION[HybridImport::OPTION_HYBRID_IMPORT_SAVING_ITEM];
    }
}