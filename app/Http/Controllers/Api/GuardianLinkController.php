<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Models\Guardian;
use App\Models\Learner;
use App\Models\LearnerParent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuardianLinkController extends BaseCrudController
{
    private const MODULE = 'Guardian Links';

    public function index(Request $request, string $learner): JsonResponse
    {
        $schoolId = $this->schoolId($request);

        $learnerModel = $this->learner($schoolId, $learner);

        $links = LearnerParent::query()
            ->where('learner_id', $learnerModel->id)
            ->where('is_deleted', false)
            ->whereHas('guardian', function (Builder $query) use ($schoolId): void {
                $query->withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('is_deleted', false);
            })
            ->with([
                'guardian' => function ($query): void {
                    $query->withoutGlobalScopes()
                        ->select([
                            'id',
                            'first_name',
                            'last_name',
                            'phone',
                            'email',
                            'relationship',
                            'active',
                        ]);
                },
            ])
            ->orderByDesc('is_primary_contact')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $links
                ->map(fn (LearnerParent $link): array => $this->safeLink($link))
                ->values(),
        ]);
    }

    public function store(Request $request, string $learner): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $user = $request->user();

        $learnerModel = $this->learner($schoolId, $learner);

        if (! $learnerModel->active || $learnerModel->is_deleted) {
            throw ValidationException::withMessages([
                'learner' => ['The learner is not active.'],
            ]);
        }

        $this->removeServerControlledFields($request);

        $validated = $request->validate($this->linkRules(true));

        $guardian = Guardian::query()
            ->withoutGlobalScopes()
            ->where('id', $validated['guardian_id'])
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->first();

        if (! $guardian) {
            throw ValidationException::withMessages([
                'guardian_id' => ['The selected guardian is not available.'],
            ]);
        }

        $link = DB::transaction(function () use (
            $request,
            $validated,
            $guardian,
            $learnerModel,
            $user,
            $schoolId
        ): LearnerParent {
            $existing = LearnerParent::query()
                ->where('learner_id', $learnerModel->id)
                ->where('parent_id', $guardian->id)
                ->lockForUpdate()
                ->first();

            if ($existing && ! $existing->is_deleted) {
                throw ValidationException::withMessages([
                    'guardian_id' => ['This guardian is already linked to the learner.'],
                ]);
            }

            $isPrimary = (bool) ($validated['is_primary_contact'] ?? false);

            if ($isPrimary) {
                $this->demotePrimaryContacts(
                    $learnerModel->id,
                    $schoolId,
                    $existing?->id
                );
            }

            $attributes = $this->linkAttributes($validated);

            if ($existing) {
                $oldValues = $existing->toArray();

                $existing->fill($attributes);
                $existing->active = true;
                $existing->portal_enabled = false;
                $existing->is_deleted = false;
                $existing->deleted_at = null;
                $existing->deleted_by = null;
                $existing->linked_by = $user->id;
                $existing->linked_at = now();
                $existing->save();
                $existing->refresh();

                $this->audit(
                    request: $request,
                    module: self::MODULE,
                    action: 'Link',
                    model: $existing,
                    oldValues: $oldValues,
                    newValues: $existing->toArray(),
                    description: 'Restored guardian link to learner.'
                );

                return $existing;
            }

            $link = new LearnerParent;
            $link->id = (string) Str::uuid();
            $link->learner_id = $learnerModel->id;
            $link->parent_id = $guardian->id;
            $link->fill($attributes);
            $link->active = true;
            $link->portal_enabled = false;
            $link->linked_by = $user->id;
            $link->linked_at = now();
            $link->is_deleted = false;
            $link->save();
            $link->refresh();

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Link',
                model: $link,
                oldValues: null,
                newValues: $link->toArray(),
                description: 'Linked guardian to learner.'
            );

            return $link;
        });

        $link->load([
            'guardian' => function ($query): void {
                $query->withoutGlobalScopes()
                    ->select([
                        'id',
                        'first_name',
                        'last_name',
                        'phone',
                        'email',
                        'relationship',
                        'active',
                    ]);
            },
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->safeLink($link),
        ], 201);
    }

    public function update(
        Request $request,
        string $learner,
        string $link
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $learnerModel = $this->learner($schoolId, $learner);
        $linkModel = $this->link($schoolId, $learnerModel->id, $link);

        $this->removeServerControlledFields($request);

        $validated = $request->validate($this->linkRules(false));

        DB::transaction(function () use (
            $request,
            $validated,
            $linkModel,
            $learnerModel,
            $schoolId
        ): void {
            $oldValues = $linkModel->toArray();

            if (
                array_key_exists('is_primary_contact', $validated)
                && $validated['is_primary_contact']
            ) {
                $this->demotePrimaryContacts(
                    $learnerModel->id,
                    $schoolId,
                    $linkModel->id
                );
            }

            $linkModel->fill($this->linkAttributes($validated));
            $linkModel->save();
            $linkModel->refresh();

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Update',
                model: $linkModel,
                oldValues: $oldValues,
                newValues: $linkModel->toArray(),
                description: 'Updated guardian link.'
            );
        });

        $linkModel->refresh();

        $linkModel->load([
            'guardian' => function ($query): void {
                $query->withoutGlobalScopes()
                    ->select([
                        'id',
                        'first_name',
                        'last_name',
                        'phone',
                        'email',
                        'relationship',
                        'active',
                    ]);
            },
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->safeLink($linkModel),
        ]);
    }

    public function destroy(
        Request $request,
        string $learner,
        string $link
    ): JsonResponse {
        $schoolId = $this->schoolId($request);
        $user = $request->user();

        $learnerModel = $this->learner($schoolId, $learner);
        $linkModel = $this->link($schoolId, $learnerModel->id, $link);

        DB::transaction(function () use (
            $request,
            $linkModel,
            $user
        ): void {
            $oldValues = $linkModel->toArray();

            $linkModel->active = false;
            $linkModel->portal_enabled = false;
            $linkModel->is_primary_contact = false;
            $linkModel->is_deleted = true;
            $linkModel->deleted_at = now();
            $linkModel->deleted_by = $user->id;
            $linkModel->save();
            $linkModel->refresh();

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Unlink',
                model: $linkModel,
                oldValues: $oldValues,
                newValues: $linkModel->toArray(),
                description: 'Unlinked guardian from learner.'
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Guardian link removed successfully.',
        ]);
    }

    private function learner(string $schoolId, string $learner): Learner
    {
        return Learner::query()
            ->withoutGlobalScopes()
            ->where('id', $learner)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    private function link(
        string $schoolId,
        string $learnerId,
        string $link
    ): LearnerParent {
        return LearnerParent::query()
            ->where('id', $link)
            ->where('learner_id', $learnerId)
            ->where('is_deleted', false)
            ->whereHas('guardian', function (Builder $query) use ($schoolId): void {
                $query->withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('is_deleted', false);
            })
            ->firstOrFail();
    }

    private function demotePrimaryContacts(
        string $learnerId,
        string $schoolId,
        ?string $exceptLinkId = null
    ): void {
        $query = LearnerParent::query()
            ->where('learner_id', $learnerId)
            ->where('is_deleted', false)
            ->where('is_primary_contact', true)
            ->whereHas('guardian', function (Builder $query) use ($schoolId): void {
                $query->withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('is_deleted', false);
            });

        if ($exceptLinkId !== null) {
            $query->where('id', '!=', $exceptLinkId);
        }

        $query->update([
            'is_primary_contact' => false,
        ]);
    }

    private function removeServerControlledFields(Request $request): void
    {
        $request->request->remove('portal_enabled');
        $request->request->remove('active');
        $request->request->remove('linked_by');
        $request->request->remove('linked_at');
        $request->request->remove('is_deleted');
        $request->request->remove('deleted_at');
        $request->request->remove('deleted_by');
    }

    private function linkRules(bool $creating): array
    {
        return [
            'guardian_id' => $creating
                ? ['required', 'uuid']
                : ['prohibited'],

            'relationship' => $creating
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'required', 'string', 'max:255'],

            'is_primary_contact' => ['sometimes', 'boolean'],
            'receives_sms' => ['sometimes', 'boolean'],
            'receives_email' => ['sometimes', 'boolean'],
            'receives_report_cards' => ['sometimes', 'boolean'],
            'emergency_contact' => ['sometimes', 'boolean'],
            'can_pick_learner' => ['sometimes', 'boolean'],
        ];
    }

    private function linkAttributes(array $validated): array
    {
        $allowed = [
            'relationship',
            'is_primary_contact',
            'receives_sms',
            'receives_email',
            'receives_report_cards',
            'emergency_contact',
            'can_pick_learner',
        ];

        return collect($validated)
            ->only($allowed)
            ->all();
    }

    private function safeLink(LearnerParent $link): array
    {
        $guardian = $link->guardian;

        return [
            'id' => $link->id,
            'learner_id' => $link->learner_id,

            'guardian' => $guardian ? [
                'id' => $guardian->id,
                'first_name' => $guardian->first_name,
                'last_name' => $guardian->last_name,
                'phone' => $guardian->phone,
                'email' => $guardian->email,
                'relationship' => $guardian->relationship,
                'active' => (bool) $guardian->active,
            ] : null,

            'relationship' => $link->relationship,
            'is_primary_contact' => (bool) $link->is_primary_contact,
            'receives_sms' => (bool) $link->receives_sms,
            'receives_email' => (bool) $link->receives_email,
            'receives_report_cards' => (bool) $link->receives_report_cards,
            'portal_enabled' => (bool) $link->portal_enabled,
            'emergency_contact' => (bool) $link->emergency_contact,
            'can_pick_learner' => (bool) $link->can_pick_learner,
            'linked_at' => $link->linked_at,
        ];
    }

    private function schoolId(Request $request): string
    {
        $schoolId = trim((string) ($request->user()?->school_id ?? ''));

        if ($schoolId === '') {
            abort(403, 'School context not found.');
        }

        return $schoolId;
    }
}
