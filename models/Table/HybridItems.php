<?php

class Table_HybridItems extends Omeka_Db_Table
{
    public function getHybridItemsRecordByHybridId($hybridId)
    {
        $select = $this->getSelect();
        $select->where("hybrid_id = '$hybridId'");
        $result = $this->fetchObject($select);
        return $result;
    }
}