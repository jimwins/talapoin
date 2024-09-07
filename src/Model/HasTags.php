<?php

declare(strict_types=1);

namespace Talapoin\Model;

trait HasTags
{
    protected function handleTags($model_name, $tag_model_name, $new_tags = null)
    {
        if ($new_tags) {
            if (!$this->id) {
                $this->save();
            }

            if (!is_array($new_tags)) {
                $new_tags = preg_split('/, */', $new_tags);
            }

            $this->has_many($tag_model_name)->delete_many();

            foreach ($new_tags as $tag_name) {
                $tag_name = trim($tag_name);
                if ($tag_name === '') {
                    continue;
                }

                $tag = $this->factory($model_name)->where('name', $tag_name)->find_one();
                if (!$tag) {
                    /** @var \Talapoin\Model $tag */
                    $tag = $this->factory($model_name)->create();
                    $tag->name = $tag_name;
                    $tag->save();
                }

                $field = $this->_get_table_name(self::$auto_prefix_models . get_called_class()) . '_id';
                $tag_field = $this->_get_table_name(self::$auto_prefix_models . get_class($tag)) . '_id';

                $assoc = $this->factory($tag_model_name)->create();
                $assoc->$field = $this->id;
                $assoc->$tag_field = $tag->id;
                $assoc->save();
            }
        }

        /*
         * This doesn't turn these into full-fledged Model\Tag objects, but
         * enough for our templates.
         **/
        if ($this->tags_json) {
            $tags = json_decode($this->tags_json);
            return array_map(fn ($tag) => [ 'name' => $tag ], $tags);
        }

        return $this->has_many_through($model_name, $tag_model_name)->find_many();
    }

    public function tags($new_tags = null)
    {
        return $this->handleTags('Tag', $this->tags_model, $new_tags);
    }
}
