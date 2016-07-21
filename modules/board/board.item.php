<?php
    /**
     * @class  bodexItem
     **/

    class boardItem extends Object {

        function getWith($obj, $arr) {
            if(!$obj || !is_array($obj->variables) || !is_array($arr)) return $obj;

            foreach($arr as $val){
                $obj->{$val} = $obj->variables[$val];
            }

            return $obj;
        }
    }
?>
