<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningAreaResource extends JsonResource
{
    /**
     * Transform resource.
     */
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'learning_area_name' =>

                $this->learning_area_name,

            'short_name' =>

                $this->short_name,

            'category' =>

                $this->category,

            'is_core' =>

                $this->is_core,

            'is_examined' =>

                $this->is_examined,

            'is_custom' =>

                $this->is_custom,

            'active' =>

                $this->active,

            'education_levels' =>

                $this->whenLoaded(

                    'educationLevels'

                ),

            'allocations' =>

                $this->whenLoaded(

                    'allocations'

                ),

            'created_at' =>

                $this->created_at,

        ];
    }
}
