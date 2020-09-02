<?php

define('CONFIG_LABEL_DELETE_HYBRID_TABLE', __('Delete Tables'));
define('CONFIG_LABEL_HYBRID_IMAGE_URL', __('Image URL'));

class HybridConfig extends ConfigOptions
{
    const OPTION_DELETE_HYBRID_TABLE = 'avanthybrid_delete_table';
    const OPTION_HYBRID_IMAGE_URL = 'avanthybrid_image_url';

    public static function removeConfiguration()
    {
        delete_option(self::OPTION_DELETE_HYBRID_TABLE);
        delete_option(self::OPTION_HYBRID_IMAGE_URL);
    }

    public static function saveConfiguration()
    {
        set_option(self::OPTION_DELETE_HYBRID_TABLE, (int)(boolean)$_POST[self::OPTION_DELETE_HYBRID_TABLE]);
        set_option(self::OPTION_HYBRID_IMAGE_URL, $_POST[self::OPTION_HYBRID_IMAGE_URL]);
    }
}