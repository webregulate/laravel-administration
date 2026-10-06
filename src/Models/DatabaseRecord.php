<?php

namespace WebRegulate\LaravelAdministration\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function newInstance($attributes = [], $exists = false)
    {
        $model = parent::newInstance($attributes, $exists);
        $model->setKeyName($this->getKeyName());
        $model->setKeyType($this->getKeyType());
        $model->setIncrementing($this->getIncrementing());

        return $model;
    }
}