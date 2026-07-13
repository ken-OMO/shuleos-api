<?php

namespace App\Models;

class LearningResource extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['is_deleted' => 'boolean', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'archived_at' => 'datetime'];

    public function versions()
    {
        return $this->hasMany(LearningResourceVersion::class, 'resource_id')->orderByDesc('version_number');
    }

    public function currentVersion()
    {
        return $this->hasOne(LearningResourceVersion::class, 'resource_id')->whereColumn('version_number', 'learning_resources.current_version_number');
    }

    public function category()
    {
        return $this->belongsTo(LearningResourceCategory::class);
    }

    public function learningArea()
    {
        return $this->belongsTo(LearningArea::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function ratings()
    {
        return $this->hasMany(LearningResourceRating::class, 'resource_id');
    }

    public function bookmarks()
    {
        return $this->hasMany(LearningResourceBookmark::class, 'resource_id');
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
