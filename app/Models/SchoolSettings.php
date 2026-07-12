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

    protected $fillable = ['id', 'school_id', 'school_motto', 'principal_name', 'principal_signature_url', 'school_logo_url', 'report_header', 'report_footer', 'pathway_enabled', 'parent_portal_enabled', 'report_card_fee_policy', 'report_card_balance_threshold', 'report_card_restriction_message', 'report_card_allow_admin_override', 'parent_portal_show_fees', 'parent_portal_show_attendance', 'parent_portal_show_announcements', 'parent_portal_show_pathway'];

    protected $casts = ['pathway_enabled' => 'boolean', 'parent_portal_enabled' => 'boolean', 'report_card_balance_threshold' => 'decimal:2', 'report_card_allow_admin_override' => 'boolean', 'parent_portal_show_fees' => 'boolean', 'parent_portal_show_attendance' => 'boolean', 'parent_portal_show_announcements' => 'boolean', 'parent_portal_show_pathway' => 'boolean'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
