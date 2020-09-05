<?php

define('CONFIG_LABEL_DELETE_HYBRID_TABLE', __('Delete Tables'));
define('CONFIG_LABEL_HYBRID_COLUMN_MAPPING', __('Column Mapping'));
define('CONFIG_LABEL_HYBRID_IMAGE_URL', __('Image URL'));
define('CONFIG_LABEL_HYBRID_SITE_ELEMENT', __('Site Element'));
define('CONFIG_LABEL_HYBRID_SITE_URL', __('Site URL'));
define('CONFIG_LABEL_HYBRID_SYNC_PW', __('Syncronization Password'));

class HybridConfig extends ConfigOptions
{
    const OPTION_DELETE_HYBRID_TABLE = 'avanthybrid_delete_table';
    const OPTION_HYBRID_COLUMN_MAPPING = 'avanthybrid_column_mapping';
    const OPTION_HYBRID_IMAGE_URL = 'avanthybrid_image_url';
    const OPTION_HYBRID_SITE_ELEMENT = 'avanthybrid_site_element';
    const OPTION_HYBRID_SITE_URL = 'avanthybrid_site_url';
    const OPTION_HYBRID_SYNC_PW = 'avanthybrid_sync_pw';

    public static function getOptionDataForColumnMappingField()
    {
        $rawData = self::getRawData(self::OPTION_HYBRID_COLUMN_MAPPING);
        $optionData = array();
        $pseudoElements = self::getPseudoElements();

        foreach ($rawData as $elementId => $data)
        {
            $elementName = ItemMetadata::getElementNameFromId($elementId);
            if (empty($elementName))
            {
                if (in_array($elementId, $pseudoElements))
                {
                    $elementName = $elementId;
                }
                else
                {
                    // This element must have been deleted since the AvantElements configuration was last saved.
                    continue;
                }
            }
            $data['name'] = $elementName;
            $optionData[$elementId] = $data;
        }

        return $optionData;
    }

    public static function getOptionTextForColumnMappingField()
    {
        if (self::configurationErrorsDetected())
        {
            $text = $_POST[self::OPTION_HYBRID_COLUMN_MAPPING];
        }
        else
        {
            $data = self::getOptionDataForColumnMappingField();
            $text = '';

            foreach ($data as $elementId => $definition)
            {
                if (!empty($text))
                {
                    $text .= PHP_EOL;
                }
                $name = $definition['name'];
                $column = $definition['column'];
                $text .= "$column: $name";
            }
        }
        return $text;
    }

    public static function getOptionTextForSiteElement()
    {
        if (self::configurationErrorsDetected())
        {
            $text = $_POST[self::OPTION_HYBRID_SITE_ELEMENT];
        }
        else
        {
            $elementId = get_option(self::OPTION_HYBRID_SITE_ELEMENT);
            $text = ItemMetadata::getElementNameFromId($elementId);
        }
        return $text;
    }

    public static function getOptionTextForSyncPassword()
    {
        if (self::configurationErrorsDetected())
            $text = $_POST[self::OPTION_HYBRID_SYNC_PW];
        else
            $text = get_option(HybridConfig::OPTION_HYBRID_SYNC_PW);
        return $text;
    }

    public static function getPseudoElements()
    {
        return array(
            '<hybrid-id>',
            '<timestamp>',
            '<image>',
            '<thumb>',
            '<site>',
            '<public>'
        );
    }

    public static function removeConfiguration()
    {
        delete_option(self::OPTION_DELETE_HYBRID_TABLE);
        delete_option(self::OPTION_HYBRID_IMAGE_URL);
    }

    public static function saveConfiguration()
    {
        set_option(self::OPTION_DELETE_HYBRID_TABLE, (int)(boolean)$_POST[self::OPTION_DELETE_HYBRID_TABLE]);

        $pw = $_POST[self::OPTION_HYBRID_SYNC_PW];
        if (strlen($pw) == 8 && ctype_alnum($pw))
            set_option(self::OPTION_HYBRID_SYNC_PW, $pw);
        else
            self::errorIf(true, CONFIG_LABEL_HYBRID_SYNC_PW, __('Password must be 8 alphanumeric characters'));

        $imageUrl = $_POST[self::OPTION_HYBRID_IMAGE_URL];
        if (substr( $imageUrl, -1 ) != '/')
            $imageUrl .= '/';
        set_option(self::OPTION_HYBRID_IMAGE_URL, $imageUrl);

        $siteUrl = $_POST[self::OPTION_HYBRID_SITE_URL];
        if (substr( $siteUrl, -1 ) != '/')
            $siteUrl .= '/';
        set_option(self::OPTION_HYBRID_SITE_URL, $siteUrl);

        $elementName = $_POST[self::OPTION_HYBRID_SITE_ELEMENT];
        if (!empty($elementName))
        {
            $elementId = ItemMetadata::getElementIdForElementName($elementName);
            self::errorIfNotElement($elementId, CONFIG_LABEL_HYBRID_SITE_ELEMENT, $elementName);
        }
        set_option(self::OPTION_HYBRID_SITE_ELEMENT, $elementId);

        self::saveOptionDataForColumnMappingField();
    }

    public static function saveOptionDataForColumnMappingField()
    {
        $pseudoElements = self::getPseudoElements();

        $data = array();
        $definitions = array_map('trim', explode(PHP_EOL, $_POST[self::OPTION_HYBRID_COLUMN_MAPPING]));

        foreach ($definitions as $definition)
        {
            if (empty($definition))
                continue;

            // Syntax: <hybrid-column-name> ":" <element-name>
            $parts = array_map('trim', explode(':', $definition));

            $sourceColumName = $parts[0];
            $partsCount = count($parts);
            self::errorIf(count($parts) > 2, CONFIG_LABEL_HYBRID_COLUMN_MAPPING, __("Mapping for column '%s' has too many parameters", $sourceColumName));

            $targetColumName = $partsCount == 2 ? $parts[1] : $parts[0];

            $unusedElementsData = CommonConfig::getOptionDataForUnusedElements();
            self::errorIf(in_array($targetColumName, $unusedElementsData), CONFIG_LABEL_HYBRID_COLUMN_MAPPING, __("Element '%s' is unused and cannot be used", $targetColumName));

            $elementName = $targetColumName;
            if (in_array($elementName, $pseudoElements))
            {
                $elementId = $elementName;
                unset($pseudoElements[array_search($elementName, $pseudoElements)]);
            }
            else
            {
                $elementId = ItemMetadata::getElementIdForElementName($elementName);
                self::errorIfNotElement($elementId, CONFIG_LABEL_HYBRID_COLUMN_MAPPING, $elementName);
            }

            $data[$elementId] = array('column' => $sourceColumName);
        }

        if (!empty($pseudoElements))
        {
            $missing = implode(', ', $pseudoElements);
            self::errorIf(true, CONFIG_LABEL_HYBRID_COLUMN_MAPPING, __('Some mappings are missing: %s', $missing));
        }

        set_option(self::OPTION_HYBRID_COLUMN_MAPPING, json_encode($data));
    }
}