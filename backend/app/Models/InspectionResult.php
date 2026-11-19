<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InspectionResult extends Model
{
    protected $table = 'inspection_results';
    protected $guarded = [];
    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;
}
