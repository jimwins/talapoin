<?php

declare(strict_types=1);

namespace Talapoin;

class Model extends \Titi\Model implements \JsonSerializable
{
    /* Memoize this, not sure why \Titi\Model doesn't store it on creation. */
    private $table_name;
    public function tableName()
    {
        if (isset($this->table_name)) {
            return $this->table_name;
        }
        return ($this->table_name = self::_get_table_name(get_class($this)));
    }

    public function getFields()
    {
        if ($this->is_new()) {
            $fields = [];
            $db = $this->orm->get_db();
            $res = $db->query("SELECT * FROM {$this->tableName()} WHERE 1=0");
            for ($i = 0; $i < $res->columnCount(); $i++) {
                $col = $res->getColumnMeta($i);
                $fields[] = $col['name'];
            }
            return $fields;
        } else {
            return array_keys($this->asArray());
        }
    }

    public function jsonSerialize(): mixed
    {
        return $this->asArray();
    }

    public function reload()
    {
        // punt if we don't have an id
        if (!$this->id) {
            return $this;
        }

        $new = $this->orm->find_one($this->id);

        if ($new === false) {
            return false;
        }

        $this->orm->hydrate($new->as_array());

        return $this;
    }

    /* Reload new things so we get all the fields with defaults. */
    public function save()
    {
        $new = $this->is_new();
        parent::save();
        return $new ? $this->reload() : $this;
    }
}
