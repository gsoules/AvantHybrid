<?php

class Table_HybridImages extends Omeka_Db_Table
{
    public function getHybridImageRecordsByItemId($itemId)
    {
        $select = $this->getSelect();
        $select->where("item_id = $itemId");
        $result = $this->fetchObjects($select);
        return $result;
    }
}