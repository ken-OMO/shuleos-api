<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\Administrator\AdministratorPortalAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformSubscriptionCatalogController extends BaseApiController
{
    private const ADDONS = [
        'boarding' => 'Boarding',
        'transport' => 'Transport',
        'elections' => 'Student Council Elections',
    ];

    private const PRICE_RULES = [
        'amount' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
        'duration_months' => 'required|integer|min:1|max:120',
    ];

    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorAuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $this->owner($request);
        $features = DB::table('package_features')
            ->join('subscription_features', 'subscription_features.id', '=', 'package_features.feature_id')
            ->where('subscription_features.active', true)
            ->orderBy('subscription_features.feature_name')
            ->get(['package_features.package_id', 'subscription_features.feature_name'])
            ->groupBy('package_id');
        $packages = DB::table('subscription_packages')->orderBy('package_name')->get()->map(function ($package) use ($features) {
            $package->features = $features->get($package->id, collect())->pluck('feature_name')->values();

            return $package;
        });

        return $this->success(['packages' => $packages, 'addons' => $this->addonPrices()]);
    }

    public function addons()
    {
        return $this->success($this->addonPrices());
    }

    public function savePackage(Request $request, ?string $package = null)
    {
        $this->owner($request);
        $data = $request->validate(self::PRICE_RULES + [
            'package_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:2000',
            'active' => 'required|boolean',
            'features' => 'present|array|max:50',
            'features.*' => 'required|string|max:255|distinct:ignore_case',
        ]);
        $names = $data['features'];
        unset($data['features']);

        $id = DB::transaction(function () use ($request, $package, $data, $names) {
            $id = $package ?? (string) Str::uuid();
            $old = [];
            if ($package === null) {
                DB::table('subscription_packages')->insert(['id' => $id] + $data);
            } else {
                $record = DB::table('subscription_packages')->where('id', $id)->lockForUpdate()->first();
                abort_unless($record, 404);
                $old = (array) $record;
                $old['feature_ids'] = DB::table('package_features')->where('package_id', $id)->pluck('feature_id')->all();
                DB::table('subscription_packages')->where('id', $id)->update($data);
            }
            $ids = [];
            foreach ($names as $name) {
                $code = 'catalog_'.hash('sha256', Str::lower($name));
                $feature = DB::table('subscription_features')->where('feature_name', $name)->where('active', true)
                    ->orderBy('id')->value('id');
                if ($feature === null) {
                    DB::table('subscription_features')->insertOrIgnore([
                        'feature_code' => $code, 'feature_name' => $name, 'active' => true,
                    ]);
                    $feature = DB::table('subscription_features')->where('feature_code', $code)->value('id');
                }
                $ids[] = $feature;
            }
            DB::table('package_features')->where('package_id', $id)
                ->whereIn('feature_id', DB::table('subscription_features')->where('active', true)->select('id'))
                ->whereNotIn('feature_id', $ids)->delete();
            foreach (array_unique($ids) as $feature) {
                DB::table('package_features')->insertOrIgnore(['package_id' => $id, 'feature_id' => $feature]);
            }
            $this->audit->record($request->user(), 'subscription_package_saved', 'subscription_packages', $id, $old, $data + ['features' => $names]);

            return $id;
        });

        return $this->success(['id' => $id]);
    }

    public function saveAddon(Request $request, string $addon)
    {
        $this->owner($request);
        abort_unless(isset(self::ADDONS[$addon]), 404);
        $data = $request->validate(self::PRICE_RULES);
        DB::transaction(function () use ($request, $addon, $data): void {
            DB::table('registration_addon_prices')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $addon] + $data);
            $query = DB::table('registration_addon_prices')->where('code', $addon);
            $old = $query->lockForUpdate()->first();
            $query->update($data);
            $this->audit->record($request->user(), 'registration_addon_price_saved', 'registration_addon_prices', $old->id, (array) $old, ['code' => $addon] + $data);
        });

        return $this->success(['code' => $addon]);
    }

    private function addonPrices(): array
    {
        $prices = DB::table('registration_addon_prices')->get()->keyBy('code');
        $addons = [];
        foreach (self::ADDONS as $code => $name) {
            $price = $prices->get($code);
            $addons[] = [
                'code' => $code, 'name' => $name,
                'amount' => $price?->amount, 'duration_months' => $price?->duration_months,
            ];
        }

        return $addons;
    }

    private function owner(Request $request): void
    {
        $user = $request->user();
        $this->access->requirePlatform($user);
        abort_unless($user->role?->role_name === 'Platform Owner', 403);
    }
}
