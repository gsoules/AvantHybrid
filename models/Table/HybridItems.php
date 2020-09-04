<?php

class Table_HybridItens extends Omeka_Db_Table
{
    public function getHybridItensRecordsByHybridId($hybridId)
    {
        $select = $this->getSelect();
        $select->where("hybrid_id = $hybridId");
        $result = $this->fetchObject($select);
        return $result;
    }

    public function getHybridItensRecordsByItemId($itemId)
    {
        $select = $this->getSelect();
        $select->where("item_id = $itemId");
        $result = $this->fetchObject($select);
        return $result;
    }
}