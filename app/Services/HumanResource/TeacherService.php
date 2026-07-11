<?php

declare(strict_types=1);

namespace App\Services\HumanResource;

use App\Core\Identifier\Identifier;
use App\Models\Teacher;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

final class TeacherService extends BaseService
{
    /**
     * Relationships to eager load.
     */
    private const RELATIONS = [

        'user',

        'school',

    ];

    /**
     * Create a teacher.
     */
    public function create(
        array $data
    ): Teacher {

        return $this->transaction(

            function () use ($data): Teacher {

                $this->beforeCreate(

                    $data

                );

                $data['id'] = Identifier::generate();

                $data['active'] ??= true;

                $data['is_deleted'] ??= false;

                $teacher = Teacher::create(

                    $data

                );

                $teacher->load(

                    self::RELATIONS

                );

                $this->afterCreate(

                    $teacher

                );

                return $teacher;

            }

        );

    }

    /**
     * Update a teacher.
     */
    public function update(
        string $id,
        array $data
    ): Teacher {

        return $this->transaction(

            function () use ($id, $data): Teacher {

                $teacher = $this->find(

                    $id

                );

                $this->beforeUpdate(

                    $teacher,

                    $data

                );

                $teacher->update(

                    $data

                );

                $teacher = $teacher->fresh()->load(

                    self::RELATIONS

                );

                $this->afterUpdate(

                    $teacher

                );

                return $teacher;

            }

        );

    }

    /**
     * Find a teacher.
     */
    public function find(
        string $id
    ): Teacher {

        $teacher = Teacher::with(

            self::RELATIONS

        )
            ->where(

                'is_deleted',

                false

            )
            ->findOrFail(

                $id

            );

        return $teacher;

    }

    /**
     * List teachers.
     */
    public function list(
        int $perPage = 20
    ): LengthAwarePaginator {

        return Teacher::query()

            ->with(

                self::RELATIONS

            )

            ->where(

                'is_deleted',

                false

            )

            ->latest()

            ->paginate(

                $perPage

            );

    }

    /**
     * Search teachers.
     */
    public function search(
        string $keyword,
        int $perPage = 20
    ): LengthAwarePaginator {

        return Teacher::query()

            ->with(

                self::RELATIONS

            )

            ->where(

                'is_deleted',

                false

            )

            ->where(

                function ($query) use ($keyword) {

                    $query

                        ->where(

                            'tsc_no',

                            'ILIKE',

                            "%{$keyword}%"

                        )

                        ->orWhere(

                            'staff_no',

                            'ILIKE',

                            "%{$keyword}%"

                        )

                        ->orWhere(

                            'phone',

                            'ILIKE',

                            "%{$keyword}%"

                        )

                        ->orWhere(

                            'email',

                            'ILIKE',

                            "%{$keyword}%"

                        );

                }

            )

            ->latest()

            ->paginate(

                $perPage

            );

    }

    /**
     * Get all teachers.
     */
    public function all(): Collection
    {

        return Teacher::query()

            ->with(

                self::RELATIONS

            )

            ->where(

                'is_deleted',

                false

            )

            ->latest()

            ->get();

    }
}
