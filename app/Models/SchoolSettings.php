<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolSettings extends Model
{
    protected $table = 'school_settings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = ['id', 'school_id', 'school_motto', 'principal_name', 'principal_signature_url', 'school_logo_url', 'report_header', 'report_footer', 'pathway_enabled'];

    protected $casts = ['pathway_enabled' => 'boolean'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
