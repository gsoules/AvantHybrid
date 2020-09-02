<?php

class AvantHybrid
{
    public static function getFileName($hybridImageRecord)
    {
        return $hybridImageRecord['file_name'];
    }

    public static function getImageHtml($item, $fileName, $isThumbnail = false, $index = 0)
    {
        $imageUrl = self::getImageUrl($fileName);
        $identifier = ItemMetadata::getItemIdentifier($item);
        $title = ItemMetadata::getItemTitle($item);
        $thumbUrl = $isThumbnail ? self::getThumbUrl($fileName) : $imageUrl;
        $imageHtml = ItemPreview::getImageLinkHtml($item->id, $identifier, 'lightbox', $imageUrl, $thumbUrl, '', $title, IMAGE_THUMB_TOOLTIP, '0', $index);
        return $imageHtml;
    }

    public static function getImageRecords($itemId)
    {
        return get_db()->getTable('HybridImages')->getHybridImageRecordsByItemId($itemId);
    }

    public static function getImageUrl($fileName)
    {
        return get_option(HybridConfig::OPTION_HYBRID_IMAGE_URL) . $fileName;
    }

    public static function getThumbHtml($item, $fileName, $index)
    {
        return self::getImageHtml($item, $fileName, true, $index);
    }

    public static function getThumbUrl($fileName)
    {
        // This URL format is specific to PastPerfect Online. If this plugin is ever generalized to support
        // other products, add a configuration option to indicate that this format applies.
        $parts = explode('/', $fileName);
        $url = get_option(HybridConfig::OPTION_HYBRID_IMAGE_URL) . $parts[0] . '/thumbs/' . $parts[1];
        return $url;
    }
}