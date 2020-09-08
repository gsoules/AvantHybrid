<?php

class Table_HybridItems extends Omeka_Db_Table
{
    public function getAllHybridItemIds()
    {
        $select = $this->getSelect();
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns(array('hybrid_id'));
        $results = get_db()->query($select)->fetchAll();
        return $results;
    }

    public function getHybridItemsRecordByHybridId($hybridId)
    {
        $select = $this->getSelect();
        $select->where("hybrid_id = '$hybridId'");
        $result = $this->fetchObject($select);
        return $result;
    }

    public function getHybridItemsRecordByItemId($itemId)
    {
        $select = $this->getSelect();
        $select->where("item_id = '$itemId'");
        $result = $this->fetchObject($select);
        return $result;
    }
}